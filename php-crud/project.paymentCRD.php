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
 *  1. Satu pembayaran = satu proyek (client otomatis dari projects.client_id).
 *  2. Alokasi per termin tidak boleh melebihi sisa tagihan (amount - paid_amount).
 *  3. total_amount >= allocated_amount; selisihnya disimpan di excess_amount (lebih bayar).
 *  4. Simpan / hapus pembayaran memperbarui project_invoices.paid_amount dalam satu transaksi.
 *  5. Pembayaran tidak bisa diedit; koreksi = hapus (reverse) lalu input ulang.
 *  6. Termin baru: total semua termin proyek tidak boleh melebihi contract_value (bila diisi).
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
const PP_METHODS = ['BANK_TRANSFER', 'CASH', 'GIRO', 'CEK']; // metode bayar yang diizinkan (varchar(20))

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
            $prj = (int)($_REQUEST['project_id'] ?? 0);
            if ($prj <= 0) sendJsonResponse(true, ['data' => [], 'totalCount' => 0]);

            $st = $db->prepare("
                SELECT id AS invoice_id, inv_no, termin_name, invoice_date, percentage,
                       amount, paid_amount, (amount - paid_amount) AS remaining
                  FROM public.project_invoices
                 WHERE project_id = :p AND (amount - paid_amount) > 0
                 ORDER BY invoice_date ASC, id ASC");
            $st->execute([':p' => $prj]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['invoice_id']  = (int)$r['invoice_id'];
                $r['percentage']  = $r['percentage'] !== null ? (float)$r['percentage'] : null;
                $r['amount']      = (float)$r['amount'];
                $r['paid_amount'] = (float)$r['paid_amount'];
                $r['remaining']   = (float)$r['remaining'];
            }

            $st = $db->prepare("
                SELECT COALESCE((SELECT contract_value FROM public.projects WHERE id = :p1), 0) AS contract_value,
                       COALESCE(SUM(amount), 0)      AS total_termin,
                       COALESCE(SUM(paid_amount), 0) AS total_paid
                  FROM public.project_invoices WHERE project_id = :p2");
            $st->execute([':p1' => $prj, ':p2' => $prj]);
            $sm = $st->fetch(PDO::FETCH_ASSOC);
            $summary = [
                'contract_value' => (float)$sm['contract_value'],
                'total_termin'   => (float)$sm['total_termin'],
                'total_paid'     => (float)$sm['total_paid'],
            ];
            sendJsonResponse(true, ['data' => $rows, 'totalCount' => count($rows)], '', ['summary' => $summary]);
            break;

        /* ------------------------------------------------------------------
         * Tambah termin (tagihan) baru untuk proyek
         * ------------------------------------------------------------------ */
        case 'save_termin':
            $prj  = (int)($_POST['project_id'] ?? 0);
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
                    $st = $db->prepare("SELECT COALESCE(contract_value, 0) FROM public.projects WHERE id = :id FOR UPDATE");
                    $st->execute([':id' => $prj]);
                    $contract = $st->fetchColumn();
                    if ($contract === false) throw new PpBusinessError('Proyek tidak ditemukan.');
                    $contract = (float)$contract;

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
                       (SELECT string_agg(DISTINCT p.name, ', ')
                          FROM public.project_payment_items i
                          JOIN public.project_invoices v ON v.id = i.invoice_id
                          JOIN public.projects p ON p.id = v.project_id
                         WHERE i.payment_id = pp.id) AS project_names
                  FROM public.project_payments pp
                  JOIN public.clients c ON c.id = pp.client_id
                  LEFT JOIN public.chart_of_accounts a ON a.id = pp.account_id
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
                       e.employee_name AS created_by_name
                  FROM public.project_payments pp
                  JOIN public.clients c ON c.id = pp.client_id
                  LEFT JOIN public.chart_of_accounts a ON a.id = pp.account_id
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

            // sisa_after = sisa tagihan termin setelah pembayaran ini (pembayaran dengan id <= ini)
            $st = $db->prepare("
                SELECT i.id, i.invoice_id, v.inv_no, v.termin_name, v.invoice_date, v.percentage, v.amount AS invoice_amount,
                       p.id AS project_id, p.name AS project_name, i.amount_paid,
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
                $it['sisa_after'] = (float)$it['sisa_after'];
            }
            sendJsonResponse(true, ['header' => $hdr, 'items' => $items]);
            break;

        /* ------------------------------------------------------------------
         * Simpan pembayaran baru (header + alokasi termin) dalam satu transaksi
         * ------------------------------------------------------------------ */
        case 'save_payment':
            $prj    = (int)($_POST['project_id'] ?? 0);
            $date   = (string)($_POST['payment_date'] ?? '');
            $acc    = (int)($_POST['account_id'] ?? 0) ?: null;
            $method = strtoupper(trim((string)($_POST['payment_method'] ?? 'BANK_TRANSFER')));
            $total  = round((float)($_POST['total_amount'] ?? 0), 2);
            $ref    = trim((string)($_POST['reference_no'] ?? '')) ?: null;
            $notes  = trim((string)($_POST['notes'] ?? '')) ?: null;
            $items  = json_decode($_POST['items'] ?? '[]', true);

            try {
                if (!pp_valid_date($date)) throw new PpBusinessError('Tanggal pembayaran tidak valid.');
                if ($prj <= 0) throw new PpBusinessError('Proyek wajib dipilih.');
                if (!in_array($method, PP_METHODS, true)) throw new PpBusinessError('Metode pembayaran tidak valid.');
                if (!is_array($items) || !count($items)) throw new PpBusinessError('Alokasikan pembayaran ke minimal satu termin.');

                $db->beginTransaction();
                try {
                    $st = $db->prepare("SELECT client_id FROM public.projects WHERE id = :id");
                    $st->execute([':id' => $prj]);
                    $prjRow = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$prjRow) throw new PpBusinessError('Proyek tidak ditemukan.');
                    if (!$prjRow['client_id']) throw new PpBusinessError('Proyek ini belum memiliki client. Lengkapi data proyek terlebih dahulu.');
                    $clientId = (int)$prjRow['client_id'];

                    if ($acc) {
                        $st = $db->prepare("SELECT 1 FROM public.chart_of_accounts WHERE id = :id");
                        $st->execute([':id' => $acc]);
                        if (!$st->fetchColumn()) throw new PpBusinessError('Akun kas/bank tidak ditemukan.');
                    }

                    // Kunci & validasi setiap termin
                    $stInv = $db->prepare("
                        SELECT id, inv_no, project_id, amount, paid_amount
                          FROM public.project_invoices WHERE id = :id FOR UPDATE");
                    $clean = []; $seen = []; $allocated = 0.0;
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
                        if ((int)$inv['project_id'] !== $prj) throw new PpBusinessError("{$no}: termin {$inv['inv_no']} bukan milik proyek ini.");
                        $sisa = round((float)$inv['amount'] - (float)$inv['paid_amount'], 2);
                        if ($pay > $sisa + PP_EPS) {
                            throw new PpBusinessError("Termin {$inv['inv_no']}: nilai bayar " . number_format($pay, 2) . " melebihi sisa tagihan (" . number_format($sisa, 2) . ").");
                        }
                        $allocated += $pay;
                        $clean[] = ['inv' => $invId, 'pay' => $pay];
                    }
                    $allocated = round($allocated, 2);
                    if ($total <= 0) throw new PpBusinessError('Jumlah diterima harus lebih dari 0.');
                    if ($total + PP_EPS < $allocated) throw new PpBusinessError('Jumlah diterima lebih kecil dari total alokasi termin.');
                    $excess = round($total - $allocated, 2);

                    if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                    $payNo = CreateRefNo('project_payments', 'payment_no', 'PPAY');

                    $st = $db->prepare("
                        INSERT INTO public.project_payments
                            (payment_no, client_id, account_id, payment_date, payment_method,
                             total_amount, allocated_amount, excess_amount, deposit_applied,
                             reference_no, notes, created_by)
                        VALUES (:no, :c, :a, CAST(:d AS date), :m, :t, :al, :ex, 0, :r, :n, :u)
                        RETURNING id");
                    $st->execute([':no' => $payNo, ':c' => $clientId, ':a' => $acc, ':d' => $date, ':m' => $method,
                                  ':t' => $total, ':al' => $allocated, ':ex' => $excess, ':r' => $ref, ':n' => $notes,
                                  ':u' => (int)$_SESSION['user_id'] ?: null]);
                    $payId = (int)$st->fetchColumn();

                    $stIns = $db->prepare("INSERT INTO public.project_payment_items (payment_id, invoice_id, amount_paid) VALUES (:p, :i, :a)");
                    $stUpd = $db->prepare("UPDATE public.project_invoices SET paid_amount = paid_amount + :a WHERE id = :i");
                    foreach ($clean as $c) {
                        $stIns->execute([':p' => $payId, ':i' => $c['inv'], ':a' => $c['pay']]);
                        $stUpd->execute([':a' => $c['pay'], ':i' => $c['inv']]);
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