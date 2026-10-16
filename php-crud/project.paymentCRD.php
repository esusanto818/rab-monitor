<?php
/**
 * project.paymentCRD.php
 * ============================================================
 * Backend Controller Pembayaran Termin per Proyek (project.payment.js)
 * dan data cetak Tanda Terima (project.payment.print.js).
 * Pola sama dengan poCRD.php: PDO (class crud), dispatch lewat $_REQUEST['task'],
 * respons JSON {success, message, data, totalCount}.
 *
 * Sumber data:
 *   project_invoices       = tagihan termin per proyek (inv_no, termin_name, amount, paid_amount)
 *   project_payments       = header pembayaran dari client (total, teralokasi, lebih bayar)
 *   project_payment_items  = alokasi pembayaran ke tiap termin
 *   projects, clients, chart_of_accounts (akun kas/bank)
 *
 * Aturan:
 *  1. [MULTI-PROYEK] Satu pembayaran = satu CLIENT; alokasi boleh ke termin beberapa proyek milik client yang sama
 *     (validasi: projects.client_id dari setiap termin harus sama dengan client pembayaran).
 *  2. Alokasi per termin tidak boleh melebihi sisa tagihan (amount - paid_amount).
 *  3. total_amount >= allocated_amount; selisihnya disimpan di excess_amount (lebih bayar).
 *  4. Simpan / hapus pembayaran memperbarui project_invoices.paid_amount dalam satu transaksi.
 *  5. Pembayaran tidak bisa diedit; koreksi = hapus (reverse) lalu input ulang.
 *  6. Termin baru: total semua termin proyek tidak boleh melebihi contract_value (bila diisi).
 *  7. [MULTI-CURRENCY] Pembayaran punya mata uang + kurs (default IDR, kurs 1). Input klien (total_amount, amount_paid) = mata uang
 *     pembayaran; tagihan termin tetap IDR. Yang disimpan: total_amount / allocated_amount / excess_amount / amount_paid = IDR
 *     (supaya project_invoices.paid_amount & laporan lama tetap konsisten), nilai asli di total_amount_cur / amount_cur.
 *     Nilai setara IDR = nilai x kurs, di-snap ke sisa tagihan bila selisih hanya akibat pembulatan 2 desimal.
 *     Butuh migrasi: sql/2026-10-15.project_payment_currency.sql
 *  8. [NEW] delete_termin: hapus termin salah entry, hanya bila belum pernah dibayar (paid_amount = 0 & tidak ada project_payment_items).
 *  9. [JURNAL] save_payment memposting jurnal otomatis (AutoPostJournal, function.all.php) dalam transaksi yang sama; gagal posting = pembayaran
 *     dibatalkan (rollback). Debit = akun Kas/Bank pilihan user (WAJIB diisi), kredit mengikuti fiscal_config:
 *       PRJ_PAY_AR  : bagian yang dialokasikan ke termin  -> kredit Piutang Usaha
 *       PRJ_PAY_DEP : bagian lebih bayar (excess)          -> kredit Deposit / Uang Muka Client
 *     reference_type = 'project_payment'. delete_payment menghapus jurnalnya (ReverseJournalByReference).
 *     Butuh migrasi: sql/2026-10-16.project_payment_journal.sql
 * 10. [JURNAL-INVOICE][NEW] save_termin memposting jurnal penagihan termin (fiscal_config PROJECT_INVOICE:
 *     Dr Piutang Usaha / Cr Pendapatan) dengan reference_type = 'project_invoice', dalam transaksi yang sama
 *     (gagal posting = termin tidak tersimpan). delete_termin menghapus jurnal penagihannya.
 *     Task backfill_termin_journal = sekali jalan, memposting jurnal untuk termin lama yang belum punya jurnal.
 * ============================================================
 */

ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$db = new crud();

const PP_EPS = 0.01;                                         // toleransi nilai rupiah
const PP_JOURNAL_REF = 'project_payment';                    // [JURNAL] nilai enum journal_entries.reference_type
const PP_METHODS = ['BANK_TRANSFER', 'CASH', 'GIRO', 'CEK']; // metode bayar yang diizinkan (varchar(20))
const PP_INV_JOURNAL_REF = 'project_invoice';                // [JURNAL-INVOICE][NEW] nilai enum journal_entries.reference_type untuk penagihan termin

class PpBusinessError extends Exception {}

function sendJsonResponse($success, $data = [], $message = '', $extra = []) {
    $response = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        if (is_array($data) && isset($data['data'])) {
            $response['data'] = $data['data'];
            if (isset($data['totalCount'])) $response['totalCount'] = $data['totalCount'];
        } else {
            $response['data'] = $data;
        }
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Validasi tanggal format YYYY-MM-DD. */
function pp_valid_date(string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

/** [JURNAL-INVOICE][NEW] Cek apakah termin sudah punya jurnal penagihan. */
function pp_invoice_has_journal($db, int $invId): bool {
    $st = $db->prepare("SELECT 1 FROM public.journal_entries WHERE reference_type::text = :t AND reference_id = :id LIMIT 1");
    $st->execute([':t' => PP_INV_JOURNAL_REF, ':id' => $invId]);
    return (bool)$st->fetchColumn();
}

/** [JURNAL-INVOICE][NEW] Posting jurnal penagihan termin (rule PROJECT_INVOICE). Gagal -> lempar PpBusinessError agar transaksi di-rollback. */
function pp_post_invoice_journal($db, int $invId, string $invNo, string $date, float $amount, string $projectName): void {
    $jr = AutoPostJournal($db, [
        'rule_code'      => 'PROJECT_INVOICE',
        'reference_type' => PP_INV_JOURNAL_REF,
        'reference_id'   => $invId,
        'journal_date'   => $date,
        'amount'         => $amount,
        'description'    => "Tagihan termin {$invNo} - {$projectName}",
    ]);
    if (!$jr['success']) throw new PpBusinessError('Gagal posting jurnal penagihan: ' . $jr['message']);
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';

try {
    switch ($task) {

        /* ------------------------------------------------------------------
         * Combo proyek (+ client & nilai kontrak)
         * ------------------------------------------------------------------ */
        case 'list_project_options':
            $rows = $db->query("
                SELECT p.id, p.name, p.client_id, c.name AS client_name,
                       COALESCE(p.contract_value, 0) AS contract_value
                  FROM public.projects p
                  LEFT JOIN public.clients c ON c.id = p.client_id
                 ORDER BY p.name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['client_id'] = $r['client_id'] !== null ? (int)$r['client_id'] : null;
                $r['contract_value'] = (float)$r['contract_value'];
            }
            sendJsonResponse(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * [MULTI-PROYEK] Combo client (hanya client yang punya proyek)
         * ------------------------------------------------------------------ */
        case 'list_client_options':
            $rows = $db->query("
                SELECT c.id, c.name
                  FROM public.clients c
                 WHERE EXISTS (SELECT 1 FROM public.projects p WHERE p.client_id = c.id)
                 ORDER BY c.name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            sendJsonResponse(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * [MULTI-CURRENCY] Combo mata uang (is_base = IDR)
         * ------------------------------------------------------------------ */
        case 'list_currencies':
            $rows = $db->query("SELECT id, init, name FROM public.m_currency ORDER BY init ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['is_base'] = (strtoupper(trim((string)$r['init'])) === 'IDR');
            }
            unset($r);
            sendJsonResponse(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * [MULTI-CURRENCY] Kurs default: kurs pajak KMK (tax_exchange_rate_*) pada tanggal bayar.
         * Tanggal tidak masuk periode KMK mana pun -> kurs terakhir. Nilai = IDR per 1 unit mata uang.
         * ------------------------------------------------------------------ */
        case 'get_exchange_rate':
            $cid  = (int)($_REQUEST['currency_id'] ?? 0);
            $date = (string)($_REQUEST['date'] ?? '');
            if (!pp_valid_date($date)) $date = date('Y-m-d');

            $st = $db->prepare("SELECT init FROM public.m_currency WHERE id = :id");
            $st->execute([':id' => $cid]);
            $curRow = $st->fetch(PDO::FETCH_ASSOC);
            if (!$curRow) sendJsonResponse(false, [], 'Mata uang tidak ditemukan.');
            $code = strtoupper(trim((string)$curRow['init']));
            if ($code === 'IDR') sendJsonResponse(true, ['rate' => 1, 'source' => 'IDR']);

            $base = "SELECT COALESCE(d.rate_per_unit, d.rate / NULLIF(d.unit_multiplier, 0)) AS rate,
                            h.kmk_no, h.start_date, h.end_date
                       FROM public.tax_exchange_rate_dtl d
                       JOIN public.tax_exchange_rate_hdr h ON h.id = d.hdr_id
                      WHERE (d.currency_id = :cid OR UPPER(d.currency_code) = :code)";
            $st = $db->prepare($base . " AND CAST(:d AS date) BETWEEN h.start_date AND h.end_date
                                          ORDER BY h.start_date DESC, h.id DESC LIMIT 1");
            $st->execute([':cid' => $cid, ':code' => $code, ':d' => $date]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $source = 'periode';
            if (!$r || $r['rate'] === null) {
                $st = $db->prepare($base . " ORDER BY h.end_date DESC, h.id DESC LIMIT 1");
                $st->execute([':cid' => $cid, ':code' => $code]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $source = 'terakhir';
            }
            if (!$r || $r['rate'] === null) sendJsonResponse(true, ['rate' => null, 'source' => 'none']);
            sendJsonResponse(true, [
                'rate'       => round((float)$r['rate'], 4),
                'source'     => $source,
                'kmk_no'     => $r['kmk_no'],
                'start_date' => date('d/m/Y', strtotime($r['start_date'])),
                'end_date'   => date('d/m/Y', strtotime($r['end_date'])),
            ]);
            break;

        /* ------------------------------------------------------------------
         * Combo akun kas / bank (chart_of_accounts aktif)
         * ------------------------------------------------------------------ */
        case 'list_account_options':
            $rows = $db->query("
                SELECT id, account_code, account_name
                  FROM public.chart_of_accounts
                 WHERE is_active = TRUE
                 ORDER BY account_code ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['label'] = $r['account_code'] . ' - ' . $r['account_name'];
            }
            sendJsonResponse(true, $rows);
            break;

        /* ------------------------------------------------------------------
         * Termin proyek yang masih punya sisa tagihan + ringkasan kontrak
         * ------------------------------------------------------------------ */
        case 'list_outstanding':
            // [MULTI-PROYEK] parameter = client_id; termin dari SEMUA proyek milik client
            $cli = (int)($_REQUEST['client_id'] ?? 0);
            if ($cli <= 0) sendJsonResponse(true, ['data' => [], 'totalCount' => 0], '', ['summary' => ['projects' => []]]);

            // [RULE-TERMIN] termin_no = urutan termin dalam proyek (termasuk termin yang sudah lunas), dihitung di subquery
            //               sebelum filter sisa tagihan agar nomor urut tidak bergeser setelah termin dilunasi.
            $st = $db->prepare("
                SELECT x.invoice_id, x.project_id, x.project_name, x.inv_no, x.termin_name, x.invoice_date,
                       x.termin_no, x.percentage, x.amount, x.paid_amount, (x.amount - x.paid_amount) AS remaining
                  FROM (
                        SELECT v.id AS invoice_id, v.project_id, p.name AS project_name,
                               v.inv_no, v.termin_name, v.invoice_date, v.percentage,
                               v.amount, v.paid_amount, p.client_id,
                               ROW_NUMBER() OVER (PARTITION BY v.project_id ORDER BY v.invoice_date ASC, v.id ASC) AS termin_no
                          FROM public.project_invoices v
                          JOIN public.projects p ON p.id = v.project_id
                       ) x
                 WHERE x.client_id = :c AND (x.amount - x.paid_amount) > 0
                 ORDER BY x.invoice_date ASC, x.invoice_id ASC");
            $st->execute([':c' => $cli]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['invoice_id']  = (int)$r['invoice_id'];
                $r['project_id']  = (int)$r['project_id'];
                $r['termin_no']   = (int)$r['termin_no'];                 // [RULE-TERMIN]
                $r['percentage']  = $r['percentage'] !== null ? (float)$r['percentage'] : null;
                $r['amount']      = (float)$r['amount'];
                $r['paid_amount'] = (float)$r['paid_amount'];
                $r['remaining']   = (float)$r['remaining'];
            }
            unset($r);

            // Ringkasan kontrak per proyek milik client (termasuk proyek yang belum punya termin)
            $st = $db->prepare("
                SELECT p.id AS project_id, p.name AS project_name,
                       COALESCE(p.contract_value, 0)  AS contract_value,
                       COALESCE(SUM(v.amount), 0)      AS total_termin,
                       COALESCE(SUM(v.paid_amount), 0) AS total_paid
                  FROM public.projects p
                  LEFT JOIN public.project_invoices v ON v.project_id = p.id
                 WHERE p.client_id = :c
                 GROUP BY p.id, p.name, p.contract_value
                 ORDER BY p.name ASC");
            $st->execute([':c' => $cli]);
            $projects = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($projects as &$pr) {
                $pr['project_id']     = (int)$pr['project_id'];
                $pr['contract_value'] = (float)$pr['contract_value'];
                $pr['total_termin']   = (float)$pr['total_termin'];
                $pr['total_paid']     = (float)$pr['total_paid'];
            }
            unset($pr);
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => count($rows)], '', ['summary' => ['projects' => $projects]]);
            break;

        /* ------------------------------------------------------------------
         * Tambah termin (tagihan) baru untuk proyek
         * ------------------------------------------------------------------ */
        case 'save_termin':
            $prj  = (int)($_POST['project_id'] ?? 0);
            $cliChk = (int)($_POST['client_id'] ?? 0);              // [MULTI-PROYEK] bila dikirim, proyek harus milik client ini
            $name = trim((string)($_POST['termin_name'] ?? ''));
            $date = (string)($_POST['invoice_date'] ?? '');
            $pct  = ($_POST['percentage'] ?? '') !== '' ? round((float)$_POST['percentage'], 2) : null;
            $amt  = round((float)($_POST['amount'] ?? 0), 2);

            try {
                if ($prj <= 0) throw new PpBusinessError('Proyek wajib dipilih.');
                if ($name === '') throw new PpBusinessError('Nama termin wajib diisi.');
                if (!pp_valid_date($date)) throw new PpBusinessError('Tanggal termin tidak valid.');
                if ($pct !== null && ($pct < 0 || $pct > 100)) throw new PpBusinessError('Persentase harus antara 0 - 100.');

                $db->beginTransaction();
                try {
                    $st = $db->prepare("SELECT COALESCE(contract_value, 0) AS cv, client_id, name FROM public.projects WHERE id = :id FOR UPDATE");
                    $st->execute([':id' => $prj]);
                    $prjRow = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$prjRow) throw new PpBusinessError('Proyek tidak ditemukan.');
                    if ($cliChk > 0 && (int)$prjRow['client_id'] !== $cliChk) throw new PpBusinessError('Proyek tidak termasuk client yang dipilih.');   // [MULTI-PROYEK]
                    $contract = (float)$prjRow['cv'];

                    if ($amt <= 0 && $pct !== null && $contract > 0) $amt = round($contract * $pct / 100, 2);   // nilai dari persentase
                    if ($amt <= 0) throw new PpBusinessError('Nilai termin harus lebih dari 0.');

                    $st = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM public.project_invoices WHERE project_id = :p");
                    $st->execute([':p' => $prj]);
                    $existing = (float)$st->fetchColumn();
                    if ($contract > 0 && $existing + $amt > $contract + PP_EPS) {
                        throw new PpBusinessError('Total termin (' . number_format($existing + $amt, 2) . ') melebihi nilai kontrak (' . number_format($contract, 2) . ').');
                    }

                    if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                    $invNo = CreateRefNo('project_invoices', 'inv_no', 'INV');
                    $st = $db->prepare("
                        INSERT INTO public.project_invoices (inv_no, project_id, termin_name, invoice_date, percentage, amount, paid_amount)
                        VALUES (:no, :p, :n, CAST(:d AS date), :pct, :a, 0)
                        RETURNING id");
                    $st->execute([':no' => $invNo, ':p' => $prj, ':n' => $name, ':d' => $date, ':pct' => $pct, ':a' => $amt]);
                    $newId = (int)$st->fetchColumn();
                    pp_post_invoice_journal($db, $newId, $invNo, $date, $amt, (string)$prjRow['name']);     // [JURNAL-INVOICE][NEW] Dr Piutang / Cr Pendapatan
                    $db->commit();
                    sendJsonResponse(true, ['id' => $newId, 'inv_no' => $invNo], "Termin {$invNo} berhasil dibuat.");
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (PpBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * Daftar pembayaran (grid utama)
         * ------------------------------------------------------------------ */
        case 'list_payments':
            $start = (int)($_REQUEST['start'] ?? 0);
            $limit = (int)($_REQUEST['limit'] ?? 250);
            $query = trim($_REQUEST['query'] ?? '');

            $where = ''; $params = [];
            if ($query !== '') {
                $where = "WHERE (pp.payment_no ILIKE :q1 OR c.name ILIKE :q2 OR COALESCE(pp.reference_no, '') ILIKE :q3
                          OR EXISTS (SELECT 1 FROM public.project_payment_items xi
                                       JOIN public.project_invoices xv ON xv.id = xi.invoice_id
                                       JOIN public.projects xp ON xp.id = xv.project_id
                                      WHERE xi.payment_id = pp.id AND xp.name ILIKE :q4))";
                $like = '%' . $query . '%';
                $params = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
            }

            $st = $db->prepare("SELECT COUNT(*)
                                  FROM public.project_payments pp
                                  JOIN public.clients c ON c.id = pp.client_id {$where}");
            $st->execute($params);
            $totalCount = (int)$st->fetchColumn();

            $st = $db->prepare("
                SELECT pp.id, pp.payment_no, pp.payment_date, pp.client_id, c.name AS client_name,
                       pp.account_id, a.account_name, pp.payment_method,
                       pp.total_amount, pp.allocated_amount, pp.excess_amount,
                       pp.reference_no, pp.notes,
                       COALESCE(UPPER(TRIM(cur.init)), 'IDR') AS currency_code,
                       COALESCE(NULLIF(pp.exch_rate, 0), 1) AS exch_rate,
                       COALESCE(pp.total_amount_cur, pp.total_amount) AS total_amount_cur,
                       (SELECT string_agg(DISTINCT p.name, ', ')
                          FROM public.project_payment_items i
                          JOIN public.project_invoices v ON v.id = i.invoice_id
                          JOIN public.projects p ON p.id = v.project_id
                         WHERE i.payment_id = pp.id) AS project_names
                  FROM public.project_payments pp
                  JOIN public.clients c ON c.id = pp.client_id
                  LEFT JOIN public.chart_of_accounts a ON a.id = pp.account_id
                  LEFT JOIN public.m_currency cur ON cur.id = pp.currency_id
                  {$where}
                 ORDER BY pp.payment_date DESC, pp.id DESC
                 LIMIT :limit OFFSET :start");
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->bindValue(':start', $start, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['client_id'] = (int)$r['client_id'];
                $r['account_id'] = $r['account_id'] !== null ? (int)$r['account_id'] : null;
                $r['total_amount'] = (float)$r['total_amount'];
                $r['allocated_amount'] = (float)$r['allocated_amount'];
                $r['excess_amount'] = (float)$r['excess_amount'];
                $r['exch_rate'] = (float)$r['exch_rate'];                 // [MULTI-CURRENCY]
                $r['total_amount_cur'] = (float)$r['total_amount_cur'];
            }
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => $totalCount]);
            break;

        /* ------------------------------------------------------------------
         * Detail pembayaran (header + item). Dipakai panel detail & cetak tanda terima.
         * ------------------------------------------------------------------ */
        case 'load_payment':
            $id = (int)($_REQUEST['id'] ?? 0);
            $st = $db->prepare("
                SELECT pp.id, pp.payment_no, pp.payment_date, pp.client_id, c.name AS client_name,
                       c.address AS client_address, c.phone AS client_phone,
                       pp.account_id, a.account_code, a.account_name, pp.payment_method,
                       pp.total_amount, pp.allocated_amount, pp.excess_amount,
                       pp.reference_no, pp.notes, pp.created_at,
                       COALESCE(UPPER(TRIM(cur.init)), 'IDR') AS currency_code,
                       COALESCE(NULLIF(pp.exch_rate, 0), 1) AS exch_rate,
                       COALESCE(pp.total_amount_cur, pp.total_amount) AS total_amount_cur,
                       e.employee_name AS created_by_name
                  FROM public.project_payments pp
                  JOIN public.clients c ON c.id = pp.client_id
                  LEFT JOIN public.chart_of_accounts a ON a.id = pp.account_id
                  LEFT JOIN public.m_currency cur ON cur.id = pp.currency_id
                  LEFT JOIN public.m_user_system u ON u.id = pp.created_by
                  LEFT JOIN public.m_employee e ON e.id = u.employee_id
                 WHERE pp.id = :id");
            $st->execute([':id' => $id]);
            $hdr = $st->fetch(PDO::FETCH_ASSOC);
            if (!$hdr) sendJsonResponse(false, [], 'Pembayaran tidak ditemukan.');
            $hdr['id'] = (int)$hdr['id'];
            $hdr['total_amount'] = (float)$hdr['total_amount'];
            $hdr['allocated_amount'] = (float)$hdr['allocated_amount'];
            $hdr['excess_amount'] = (float)$hdr['excess_amount'];
            $hdr['exch_rate'] = (float)$hdr['exch_rate'];                 // [MULTI-CURRENCY]
            $hdr['total_amount_cur'] = (float)$hdr['total_amount_cur'];

            // sisa_after = sisa tagihan termin setelah pembayaran ini (pembayaran dengan id <= ini)
            $st = $db->prepare("
                SELECT i.id, i.invoice_id, v.inv_no, v.termin_name, v.invoice_date, v.percentage, v.amount AS invoice_amount,
                       p.id AS project_id, p.name AS project_name, i.amount_paid,
                       COALESCE(i.amount_cur, i.amount_paid) AS amount_cur,       -- [MULTI-CURRENCY]
                       (v.amount - COALESCE((SELECT SUM(x.amount_paid)
                                               FROM public.project_payment_items x
                                              WHERE x.invoice_id = v.id AND x.payment_id <= i.payment_id), 0)) AS sisa_after
                  FROM public.project_payment_items i
                  JOIN public.project_invoices v ON v.id = i.invoice_id
                  JOIN public.projects p ON p.id = v.project_id
                 WHERE i.payment_id = :id
                 ORDER BY v.invoice_date ASC, i.id ASC");
            $st->execute([':id' => $id]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['invoice_id'] = (int)$it['invoice_id'];
                $it['project_id'] = (int)$it['project_id'];
                $it['percentage'] = $it['percentage'] !== null ? (float)$it['percentage'] : null;
                $it['invoice_amount'] = (float)$it['invoice_amount'];
                $it['amount_paid'] = (float)$it['amount_paid'];
                $it['amount_cur'] = (float)$it['amount_cur'];
                $it['sisa_after'] = (float)$it['sisa_after'];
            }
            sendJsonResponse(true, ['header' => $hdr, 'items' => $items]);
            break;

        /* ------------------------------------------------------------------
         * Simpan pembayaran baru (header + alokasi termin) dalam satu transaksi
         * ------------------------------------------------------------------ */
        case 'save_payment':
            $cli    = (int)($_POST['client_id'] ?? 0);              // [MULTI-PROYEK] ganti project_id
            $date   = (string)($_POST['payment_date'] ?? '');
            $acc    = (int)($_POST['account_id'] ?? 0) ?: null;
            $method = strtoupper(trim((string)($_POST['payment_method'] ?? 'BANK_TRANSFER')));
            $total  = round((float)($_POST['total_amount'] ?? 0), 2);
            $ref    = trim((string)($_POST['reference_no'] ?? '')) ?: null;
            $notes  = trim((string)($_POST['notes'] ?? '')) ?: null;
            $items  = json_decode($_POST['items'] ?? '[]', true);
            $curIn  = (int)($_POST['currency_id'] ?? 0) ?: null;     // [MULTI-CURRENCY] kosong = IDR
            $rateIn = (float)($_POST['exch_rate'] ?? 1);

            try {
                if (!pp_valid_date($date)) throw new PpBusinessError('Tanggal pembayaran tidak valid.');
                if (!$acc) throw new PpBusinessError('Akun Kas/Bank wajib dipilih.');          // [JURNAL] akun kas/bank wajib (debit jurnal)
                if ($cli <= 0) throw new PpBusinessError('Client wajib dipilih.');
                if (!in_array($method, PP_METHODS, true)) throw new PpBusinessError('Metode pembayaran tidak valid.');
                if (!is_array($items) || !count($items)) throw new PpBusinessError('Alokasikan pembayaran ke minimal satu termin.');

                $db->beginTransaction();
                try {
                    $st = $db->prepare("SELECT 1 FROM public.clients WHERE id = :id");
                    $st->execute([':id' => $cli]);
                    if (!$st->fetchColumn()) throw new PpBusinessError('Client tidak ditemukan.');
                    $clientId = $cli;

                    // [MULTI-CURRENCY] mata uang pembayaran (kosong -> IDR); IDR selalu kurs 1, apa pun yang dikirim klien
                    $st = $db->prepare("
                        SELECT id, UPPER(TRIM(init)) AS code FROM public.m_currency
                         WHERE id = COALESCE(CAST(:id AS bigint), (SELECT id FROM public.m_currency WHERE UPPER(TRIM(init)) = 'IDR' LIMIT 1))");
                    $st->execute([':id' => $curIn]);
                    $curRow = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$curRow) throw new PpBusinessError('Mata uang tidak ditemukan.');
                    $curId  = (int)$curRow['id'];
                    $isBase = ($curRow['code'] === 'IDR');
                    $rate   = $isBase ? 1.0 : round($rateIn, 4);
                    if (!$isBase && $rate <= 0) throw new PpBusinessError("Kurs {$curRow['code']} wajib diisi.");

                    if ($acc) {
                        $st = $db->prepare("SELECT 1 FROM public.chart_of_accounts WHERE id = :id");
                        $st->execute([':id' => $acc]);
                        if (!$st->fetchColumn()) throw new PpBusinessError('Akun kas/bank tidak ditemukan.');
                    }

                    // Kunci & validasi setiap termin. [MULTI-PROYEK] termin boleh beda proyek, tapi client proyeknya harus sama.
                    // FOR UPDATE OF v = hanya kunci baris invoice (bukan baris projects).
                    $stInv = $db->prepare("
                        SELECT v.id, v.inv_no, v.project_id, v.amount, v.paid_amount,
                               p.client_id, p.name AS project_name
                          FROM public.project_invoices v
                          JOIN public.projects p ON p.id = v.project_id
                         WHERE v.id = :id FOR UPDATE OF v");
                    $clean = []; $seen = []; $allocated = 0.0; $allocatedIdr = 0.0;     // [MULTI-CURRENCY] $allocated = mata uang pembayaran, $allocatedIdr = IDR
                    foreach ($items as $n => $it) {
                        $invId = (int)($it['invoice_id'] ?? 0);
                        $pay   = round((float)($it['amount_paid'] ?? 0), 2);
                        $no    = 'Baris ' . ($n + 1);
                        if ($invId <= 0 || $pay <= 0) throw new PpBusinessError("{$no}: termin dan nilai bayar (> 0) wajib diisi.");
                        if (isset($seen[$invId])) throw new PpBusinessError("{$no}: termin dobel.");
                        $seen[$invId] = true;

                        $stInv->execute([':id' => $invId]);
                        $inv = $stInv->fetch(PDO::FETCH_ASSOC);
                        if (!$inv) throw new PpBusinessError("{$no}: termin tidak ditemukan.");
                        if ((int)$inv['client_id'] !== $clientId) throw new PpBusinessError("{$no}: termin {$inv['inv_no']} ({$inv['project_name']}) bukan milik client ini.");
                        $sisa = round((float)$inv['amount'] - (float)$inv['paid_amount'], 2);
                        // [MULTI-CURRENCY] $pay = mata uang pembayaran; sisa tagihan = IDR -> bandingkan setara IDR
                        $payIdr = round($pay * $rate, 2);
                        if (abs($payIdr - $sisa) <= 0.005 * $rate + PP_EPS) $payIdr = $sisa;      // selisih hanya pembulatan -> lunas
                        if ($payIdr > $sisa + PP_EPS) {
                            throw new PpBusinessError("Termin {$inv['inv_no']} ({$inv['project_name']}): nilai bayar " . number_format($pay, 2) . " {$curRow['code']}" .
                                " (setara " . number_format($payIdr, 2) . " IDR) melebihi sisa tagihan (" . number_format($sisa, 2) . " IDR).");
                        }
                        $allocated    += $pay;
                        $allocatedIdr += $payIdr;
                        $clean[] = ['inv' => $invId, 'pay' => $payIdr, 'cur' => $pay];
                    }
                    $allocated = round($allocated, 2);
                    $allocatedIdr = round($allocatedIdr, 2);
                    if ($total <= 0) throw new PpBusinessError('Jumlah diterima harus lebih dari 0.');
                    if ($total + PP_EPS < $allocated) throw new PpBusinessError('Jumlah diterima lebih kecil dari total alokasi termin.');
                    $excessCur = round($total - $allocated, 2);                 // [MULTI-CURRENCY] lebih bayar dalam mata uang pembayaran
                    $excess    = round($excessCur * $rate, 2);                   // ... dan setara IDR
                    $totalIdr  = round($allocatedIdr + $excess, 2);

                    if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                    $payNo = CreateRefNo('project_payments', 'payment_no', 'PPAY');

                    $st = $db->prepare("
                        INSERT INTO public.project_payments
                            (payment_no, client_id, account_id, payment_date, payment_method,
                             total_amount, allocated_amount, excess_amount, deposit_applied,
                             reference_no, notes, created_by, currency_id, exch_rate, total_amount_cur)
                        VALUES (:no, :c, :a, CAST(:d AS date), :m, :t, :al, :ex, 0, :r, :n, :u, :cur, :rate, :tc)
                        RETURNING id");
                    $st->execute([':no' => $payNo, ':c' => $clientId, ':a' => $acc, ':d' => $date, ':m' => $method,
                                  ':t' => $totalIdr, ':al' => $allocatedIdr, ':ex' => $excess, ':r' => $ref, ':n' => $notes,
                                  ':u' => (int)$_SESSION['user_id'] ?: null,
                                  ':cur' => $curId, ':rate' => $rate, ':tc' => $total]);     // [MULTI-CURRENCY] nilai asli + kurs
                    $payId = (int)$st->fetchColumn();

                    $stIns = $db->prepare("INSERT INTO public.project_payment_items (payment_id, invoice_id, amount_paid, amount_cur) VALUES (:p, :i, :a, :ac)");
                    $stUpd = $db->prepare("UPDATE public.project_invoices SET paid_amount = paid_amount + :a WHERE id = :i");
                    foreach ($clean as $c) {
                        $stIns->execute([':p' => $payId, ':i' => $c['inv'], ':a' => $c['pay'], ':ac' => $c['cur']]);     // amount_paid = IDR, amount_cur = mata uang pembayaran
                        $stUpd->execute([':a' => $c['pay'], ':i' => $c['inv']]);
                    }

                    // [JURNAL] posting jurnal otomatis (nilai IDR). Debit Kas/Bank = akun pilihan user; kredit dari fiscal_config.
                    $stCli = $db->prepare("SELECT name FROM public.clients WHERE id = :id");
                    $stCli->execute([':id' => $clientId]);
                    $cliName = (string)$stCli->fetchColumn();
                    $jrnParts = [];
                    if ($allocatedIdr > PP_EPS) $jrnParts[] = ['PRJ_PAY_AR',  $allocatedIdr, "Pembayaran termin {$payNo} - {$cliName}"];
                    if ($excess > PP_EPS)       $jrnParts[] = ['PRJ_PAY_DEP', $excess,       "Lebih bayar (deposit) {$payNo} - {$cliName}"];
                    foreach ($jrnParts as $jp) {
                        $jr = AutoPostJournal($db, [
                            'rule_code'        => $jp[0],
                            'reference_type'   => PP_JOURNAL_REF,
                            'reference_id'     => $payId,
                            'journal_date'     => $date,
                            'amount'           => $jp[1],
                            'description'      => $jp[2],
                            'debit_account_id' => $acc,
                        ]);
                        if (!$jr['success']) throw new PpBusinessError('Gagal posting jurnal: ' . $jr['message']);     // rollback seluruh pembayaran
                    }

                    $db->commit();
                    sendJsonResponse(true, ['id' => $payId, 'payment_no' => $payNo], "Pembayaran {$payNo} berhasil disimpan.");
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    throw $e;
                }
            } catch (PpBusinessError $e) {
                sendJsonResponse(false, [], $e->getMessage());
            }
            break;

        /* ------------------------------------------------------------------
         * [NEW] Hapus termin yang salah entry (hanya bila belum ada pembayaran)
         * ------------------------------------------------------------------ */
        case 'delete_termin':
            $id     = (int)($_POST['id'] ?? 0);
            $cliChk = (int)($_POST['client_id'] ?? 0);
            $db->beginTransaction();
            try {
                $st = $db->prepare("
                    SELECT v.inv_no, v.paid_amount, p.client_id
                      FROM public.project_invoices v
                      JOIN public.projects p ON p.id = v.project_id
                     WHERE v.id = :id FOR UPDATE OF v");
                $st->execute([':id' => $id]);
                $inv = $st->fetch(PDO::FETCH_ASSOC);
                if (!$inv) throw new PpBusinessError('Termin tidak ditemukan.');
                if ($cliChk > 0 && (int)$inv['client_id'] !== $cliChk) throw new PpBusinessError('Termin bukan milik client yang dipilih.');

                $st = $db->prepare("SELECT COUNT(*) FROM public.project_payment_items WHERE invoice_id = :id");
                $st->execute([':id' => $id]);
                if ((float)$inv['paid_amount'] > PP_EPS || (int)$st->fetchColumn() > 0) {
                    throw new PpBusinessError("Termin {$inv['inv_no']} sudah memiliki pembayaran, tidak dapat dihapus. Hapus (reverse) pembayarannya terlebih dahulu.");
                }

                if (pp_invoice_has_journal($db, $id)) ReverseJournalByReference($db, PP_INV_JOURNAL_REF, $id);   // [JURNAL-INVOICE][NEW] hapus jurnal penagihan (termin lama tanpa jurnal dilewati)
                $db->prepare("DELETE FROM public.project_invoices WHERE id = :id")->execute([':id' => $id]);
                $db->commit();
                sendJsonResponse(true, [], "Termin {$inv['inv_no']} dihapus.");
            } catch (PpBusinessError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                sendJsonResponse(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * Hapus (reverse) pembayaran: kembalikan paid_amount tiap termin
         * ------------------------------------------------------------------ */
        case 'delete_payment':
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT payment_no FROM public.project_payments WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $pay = $st->fetch(PDO::FETCH_ASSOC);
                if (!$pay) throw new PpBusinessError('Pembayaran tidak ditemukan.');

                $st = $db->prepare("SELECT invoice_id, amount_paid FROM public.project_payment_items WHERE payment_id = :id");
                $st->execute([':id' => $id]);
                $stUpd = $db->prepare("UPDATE public.project_invoices SET paid_amount = GREATEST(paid_amount - :a, 0) WHERE id = :i");
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $stUpd->execute([':a' => $it['amount_paid'], ':i' => $it['invoice_id']]);
                }
                ReverseJournalByReference($db, PP_JOURNAL_REF, $id);                 // [JURNAL] hapus jurnal pembayaran ini
                $db->prepare("DELETE FROM public.project_payment_items WHERE payment_id = :id")->execute([':id' => $id]);
                $db->prepare("DELETE FROM public.project_payments WHERE id = :id")->execute([':id' => $id]);
                $db->commit();
                sendJsonResponse(true, [], "Pembayaran {$pay['payment_no']} dihapus dan saldo termin dikembalikan.");
            } catch (PpBusinessError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                sendJsonResponse(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        /* ------------------------------------------------------------------
         * [JURNAL-INVOICE][NEW] Backfill sekali jalan: posting jurnal penagihan untuk termin lama yang belum punya jurnal.
         * Idempotent (termin yang sudah berjurnal dilewati). Semua-atau-tidak-sama-sekali (satu transaksi).
         * ------------------------------------------------------------------ */
        case 'backfill_termin_journal':
            if (intval($_SESSION['FormAccess'] ?? 0) < 3) sendJsonResponse(false, [], 'Akses ditolak.');
            $db->beginTransaction();
            try {
                $rows = $db->query("
                    SELECT v.id, v.inv_no, v.invoice_date, v.amount, p.name AS project_name
                      FROM public.project_invoices v
                      JOIN public.projects p ON p.id = v.project_id
                     WHERE v.amount > 0
                       AND NOT EXISTS (SELECT 1 FROM public.journal_entries je
                                        WHERE je.reference_type::text = '" . PP_INV_JOURNAL_REF . "' AND je.reference_id = v.id)
                     ORDER BY v.invoice_date ASC, v.id ASC")->fetchAll(PDO::FETCH_ASSOC);
                $done = [];
                foreach ($rows as $r) {
                    pp_post_invoice_journal($db, (int)$r['id'], (string)$r['inv_no'], (string)$r['invoice_date'], (float)$r['amount'], (string)$r['project_name']);
                    $done[] = $r['inv_no'];
                }
                $db->commit();
                sendJsonResponse(true, ['posted' => $done], count($done) . ' jurnal penagihan termin diposting' . (count($done) ? ': ' . implode(', ', $done) : '.'));
            } catch (PpBusinessError $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                sendJsonResponse(false, [], $e->getMessage());
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PDOException $e) {
    error_log('[project.paymentCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[project.paymentCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'System Error: ' . $e->getMessage());
}