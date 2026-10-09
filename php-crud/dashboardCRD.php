<?php
// php-crud/dashboardCRD.php
// Semua output DIJAMIN JSON valid (warning/notice PHP tidak lagi merusak respons).
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';
require_once __DIR__ . '/../cron/fetch_kurs_pajak.php';

function out(array $data, int $flags = 0): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    out(['success' => false, 'message' => 'Sesi berakhir, silakan login ulang.']);
}

/*
 * Sumber data "Performa Keuangan". Matikan salah satu jika ternyata
 * tabelnya sudah saling mirror (mencegah hitung ganda).
 */
const SRC_FIN_INCOME   = true;  // financial_incomes
const SRC_PRJ_PAYMENT  = true;  // project_payment_items (pembayaran termin klien)
const SRC_FIN_EXPENSE  = true;  // financial_expenses
const SRC_MATERIAL_OUT = true;  // material_out_items x unit_cost (biaya material ke proyek)
const SRC_MATERIAL_IN  = false; // [BARU] material_receipt_items x unit_cost (pembelian diterima). Aktifkan bila ingin
                                //        pengeluaran dihitung saat barang MASUK; matikan SRC_MATERIAL_OUT agar tidak ganda.

$db     = new crud();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // 1. LIST PROYEK
    case 'get_projects':
        try {
            $stmt = $db->prepare("SELECT id, name AS project_name FROM projects ORDER BY name ASC");
            $stmt->execute();
            out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            out(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // 2. KURS PAJAK KMK TERBARU
    case 'read_tax_rates':
        try {
            $stmt = $db->prepare("SELECT id, kmk_no, start_date, end_date FROM tax_exchange_rate_hdr ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $header = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$header) {
                out(['success' => true, 'kmk_number' => '-', 'start_date' => '-', 'end_date' => '-', 'rates' => []]);
            }

            $stmt = $db->prepare("
                SELECT currency_code, currency_name, unit_multiplier, rate, change_amount, change_direction
                FROM tax_exchange_rate_dtl
                WHERE hdr_id = :hdr
                ORDER BY currency_code ASC
            ");
            $stmt->bindValue(':hdr', (int)$header['id'], PDO::PARAM_INT);
            $stmt->execute();

            out([
                'success'    => true,
                'kmk_number' => $header['kmk_no'],
                'start_date' => date('d/m/Y', strtotime($header['start_date'])),
                'end_date'   => date('d/m/Y', strtotime($header['end_date'])),
                'rates'      => $stmt->fetchAll(PDO::FETCH_ASSOC),
            ], JSON_NUMERIC_CHECK);
        } catch (Exception $e) {
            out(['success' => false, 'message' => 'Gagal membaca kurs pajak: ' . $e->getMessage()]);
        }
        break;

    // 3. SYNC KURS DARI BEA CUKAI
    case 'sync_tax_rates':
        try {
            if (!function_exists('fetchAndStoreTaxExchangeRates')) {
                out(['success' => false, 'message' => 'Fungsi fetchAndStoreTaxExchangeRates tidak ditemukan.']);
            }
            out((array)fetchAndStoreTaxExchangeRates($db));
        } catch (Exception $e) {
            out(['success' => false, 'message' => 'Gagal mengambil data dari server Bea Cukai: ' . $e->getMessage()]);
        }
        break;

    // 4a. [BARU] FINANCIAL AGING (UMUR PIUTANG & HUTANG)
    //     AR = sisa tagihan termin klien   (project_invoices.amount - terbayar)
    //     AP = sisa invoice supplier       (purchase_invoice_hdr.total_amount - paid_amount)
    //     Umur dihitung dari tanggal invoice (tabel belum punya kolom jatuh tempo).
    //     Filter proyek pada AP dilakukan proporsional lewat purchase_invoice_dtl -> grn_hdr -> purchase_orders.
    case 'read_financial_aging':
        $projectId = (int)($_GET['project_id'] ?? 0);

        // Bucket umur: <=30, 31-60, 61-90, >90 hari
        $bucketSql = "
            SELECT COALESCE(SUM(CASE WHEN age <= 30 THEN os END), 0)               AS age_30,
                   COALESCE(SUM(CASE WHEN age BETWEEN 31 AND 60 THEN os END), 0)   AS age_60,
                   COALESCE(SUM(CASE WHEN age BETWEEN 61 AND 90 THEN os END), 0)   AS age_90,
                   COALESCE(SUM(CASE WHEN age > 90 THEN os END), 0)                AS age_over_90,
                   COALESCE(SUM(os), 0)                                            AS total,
                   COUNT(*)                                                        AS cnt
            FROM (%s) t";

        // AR: sisa = amount - GREATEST(paid_amount, total alokasi pembayaran) -> aman bila paid_amount belum tersinkron
        $arInner = "
            SELECT (pinv.amount - GREATEST(pinv.paid_amount,
                        COALESCE((SELECT SUM(x.amount_paid) FROM project_payment_items x WHERE x.invoice_id = pinv.id), 0))) AS os,
                   (CURRENT_DATE - pinv.invoice_date) AS age
            FROM project_invoices pinv
            WHERE (pinv.amount - GREATEST(pinv.paid_amount,
                        COALESCE((SELECT SUM(x.amount_paid) FROM project_payment_items x WHERE x.invoice_id = pinv.id), 0))) > 0.005"
            . ($projectId ? " AND pinv.project_id = :pid" : "");

        $apStatus = "COALESCE(LOWER(h.status),'') NOT IN ('void','cancelled','canceled','dibatalkan','reversed')";
        if ($projectId) {
            // porsi invoice yang menjadi milik proyek terpilih (berdasar nilai matched per PO.project_id)
            $apInner = "
                SELECT ((h.total_amount - h.paid_amount) * COALESCE(sh.share, 0)) AS os,
                       (CURRENT_DATE - h.invoice_date) AS age
                FROM purchase_invoice_hdr h
                JOIN (
                    SELECT d.invoice_hdr_id,
                           SUM(CASE WHEN po.project_id = :pid THEN d.matched_amount ELSE 0 END)
                               / NULLIF(SUM(d.matched_amount), 0) AS share
                    FROM purchase_invoice_dtl d
                    JOIN grn_hdr g ON g.id = d.grn_hdr_id
                    JOIN purchase_orders po ON po.id = g.po_id
                    GROUP BY d.invoice_hdr_id
                ) sh ON sh.invoice_hdr_id = h.id
                WHERE {$apStatus}
                  AND (h.total_amount - h.paid_amount) > 0.005";
        } else {
            $apInner = "
                SELECT (h.total_amount - h.paid_amount) AS os,
                       (CURRENT_DATE - h.invoice_date) AS age
                FROM purchase_invoice_hdr h
                WHERE {$apStatus}
                  AND (h.total_amount - h.paid_amount) > 0.005";
        }

        $fetchBucket = function (string $inner) use ($db, $bucketSql, $projectId) {
            $stmt = $db->prepare(sprintf($bucketSql, $inner));
            if ($projectId) {
                $stmt->bindValue(':pid', $projectId, PDO::PARAM_INT);
            }
            $stmt->execute();
            $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'age_30'      => (float)($r['age_30'] ?? 0),
                'age_60'      => (float)($r['age_60'] ?? 0),
                'age_90'      => (float)($r['age_90'] ?? 0),
                'age_over_90' => (float)($r['age_over_90'] ?? 0),
                'total'       => (float)($r['total'] ?? 0),
                'count'       => (int)($r['cnt'] ?? 0),
            ];
        };

        try {
            out([
                'success' => true,
                'as_of'   => date('Y-m-d'),
                'ar'      => $fetchBucket($arInner),
                'ap'      => $fetchBucket($apInner),
            ]);
        } catch (Exception $e) {
            error_log('[dashboardCRD] financial_aging: ' . $e->getMessage());
            out(['success' => false, 'message' => 'Query aging gagal: ' . $e->getMessage()]);
        }
        break;

    // 4b. PERFORMA KEUANGAN (PENDAPATAN VS PENGELUARAN PER BULAN)
    //     [DIUBAH] tiap sumber punya parameter sendiri -> bisa dirinci per sumber (?debug=1) dan
    //     respons memuat available_years agar pilihan tahun di UI mengikuti data yang ada.
    case 'read_financial_performance':
        $year      = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $projectId = (int)($_GET['project_id'] ?? 0);
        $from      = sprintf('%04d-01-01', $year);
        $to        = sprintf('%04d-01-01', $year + 1);
        $cancelled = "('VOID','CANCELLED','CANCELED','BATAL')";

        $n = 0;
        $mk = function ($v, array &$bag) use (&$n) {   // buat placeholder unik & simpan nilainya
            $k = ':p' . ($n++);
            $bag[$k] = $v;
            return $k;
        };
        $sources = [];   // tiap elemen: type (inc|exp), name, sql, params

        if (SRC_FIN_INCOME) {
            $bag = [];
            $sources[] = ['type' => 'inc', 'name' => 'financial_incomes', 'params' => &$bag, 'sql' =>
                "SELECT EXTRACT(MONTH FROM income_date)::int AS bln, amount
                 FROM financial_incomes
                 WHERE income_date >= CAST({$mk($from, $bag)} AS date) AND income_date < CAST({$mk($to, $bag)} AS date)
                   AND COALESCE(UPPER(status),'') NOT IN {$cancelled}"
                . ($projectId ? " AND project_id = {$mk($projectId, $bag)}" : "")];
            unset($bag);
        }
        if (SRC_PRJ_PAYMENT) {
            $bag = [];
            $sources[] = ['type' => 'inc', 'name' => 'project_payment_items', 'params' => &$bag, 'sql' =>
                "SELECT EXTRACT(MONTH FROM pp.payment_date)::int AS bln, ppi.amount_paid AS amount
                 FROM project_payment_items ppi
                 JOIN project_payments pp ON pp.id = ppi.payment_id
                 JOIN project_invoices pinv ON pinv.id = ppi.invoice_id
                 WHERE pp.payment_date >= CAST({$mk($from, $bag)} AS date) AND pp.payment_date < CAST({$mk($to, $bag)} AS date)"
                . ($projectId ? " AND pinv.project_id = {$mk($projectId, $bag)}" : "")];
            unset($bag);
        }
        if (SRC_FIN_EXPENSE) {
            $bag = [];
            $sources[] = ['type' => 'exp', 'name' => 'financial_expenses', 'params' => &$bag, 'sql' =>
                "SELECT EXTRACT(MONTH FROM expense_date)::int AS bln, amount
                 FROM financial_expenses
                 WHERE expense_date >= CAST({$mk($from, $bag)} AS date) AND expense_date < CAST({$mk($to, $bag)} AS date)
                   AND COALESCE(UPPER(status),'') NOT IN {$cancelled}"
                . ($projectId ? " AND project_id = {$mk($projectId, $bag)}" : "")];
            unset($bag);
        }
        if (SRC_MATERIAL_OUT) {
            $bag = [];
            $sources[] = ['type' => 'exp', 'name' => 'material_out', 'params' => &$bag, 'sql' =>
                "SELECT EXTRACT(MONTH FROM h.issue_date)::int AS bln, (i.qty_out * i.unit_cost) AS amount
                 FROM material_out_items i
                 JOIN material_out_header h ON h.id = i.material_out_id
                 WHERE h.issue_date >= CAST({$mk($from, $bag)} AS date) AND h.issue_date < CAST({$mk($to, $bag)} AS date)
                   AND h.status = 'posted' AND h.reversed_at IS NULL"
                . ($projectId ? " AND h.project_id = {$mk($projectId, $bag)}" : "")];
            unset($bag);
        }
        if (SRC_MATERIAL_IN) {   // [BARU] opsional
            $bag = [];
            $sources[] = ['type' => 'exp', 'name' => 'material_receipt', 'params' => &$bag, 'sql' =>
                "SELECT EXTRACT(MONTH FROM h.receipt_date)::int AS bln, (i.qty_in * i.unit_cost) AS amount
                 FROM material_receipt_items i
                 JOIN material_receipt_header h ON h.id = i.receipt_id
                 WHERE h.receipt_date >= CAST({$mk($from, $bag)} AS date) AND h.receipt_date < CAST({$mk($to, $bag)} AS date)
                   AND h.status = 'posted' AND h.reversed_at IS NULL"
                . ($projectId ? " AND h.project_id = {$mk($projectId, $bag)}" : "")];
            unset($bag);
        }

        $empty = "SELECT 0::int AS bln, 0::numeric AS amount WHERE false";
        $incParts = [];
        $expParts = [];
        $params   = [];
        foreach ($sources as $s) {
            if ($s['type'] === 'inc') { $incParts[] = $s['sql']; } else { $expParts[] = $s['sql']; }
            $params += $s['params'];
        }
        $incSql = $incParts ? implode(' UNION ALL ', $incParts) : $empty;
        $expSql = $expParts ? implode(' UNION ALL ', $expParts) : $empty;

        $sql = "
            WITH inc AS ({$incSql}), exp AS ({$expSql})
            SELECT g.bln,
                   (SELECT COALESCE(SUM(amount),0) FROM inc WHERE inc.bln = g.bln) AS total_inc,
                   (SELECT COALESCE(SUM(amount),0) FROM exp WHERE exp.bln = g.bln) AS total_exp
            FROM generate_series(1,12) AS g(bln)
            ORDER BY g.bln
        ";

        $pendapatan  = array_fill(0, 12, 0.0);
        $pengeluaran = array_fill(0, 12, 0.0);

        try {
            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $idx = (int)$r['bln'] - 1;
                $pendapatan[$idx]  = (float)$r['total_inc'];
                $pengeluaran[$idx] = (float)$r['total_exp'];
            }
        } catch (Exception $e) {
            error_log('[dashboardCRD] financial_performance: ' . $e->getMessage());
            out(['success' => false, 'message' => 'Query performa keuangan gagal: ' . $e->getMessage()]);
        }

        // [BARU] daftar tahun yang punya transaksi (selalu sertakan tahun berjalan & tahun yang diminta)
        $years = [(int)date('Y'), $year];
        try {
            $yrSql = "SELECT DISTINCT y FROM (
                        SELECT EXTRACT(YEAR FROM income_date)::int AS y FROM financial_incomes
                        UNION SELECT EXTRACT(YEAR FROM expense_date)::int FROM financial_expenses
                        UNION SELECT EXTRACT(YEAR FROM payment_date)::int FROM project_payments
                        UNION SELECT EXTRACT(YEAR FROM issue_date)::int FROM material_out_header
                      ) t WHERE y IS NOT NULL ORDER BY y DESC";
            foreach ($db->query($yrSql)->fetchAll(PDO::FETCH_COLUMN) as $y) {
                $years[] = (int)$y;
            }
        } catch (Exception $e) {
            error_log('[dashboardCRD] available_years: ' . $e->getMessage());
        }
        $years = array_values(array_unique($years));
        rsort($years);

        $resp = [
            'success'         => true,
            'year'            => $year,
            'labels'          => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
            'datasets'        => ['pendapatan' => $pendapatan, 'pengeluaran' => $pengeluaran],
            'available_years' => $years,
        ];

        // ?debug=1 -> jumlah baris tabel + total per sumber untuk tahun terpilih (diagnosa "kenapa 0")
        if (!empty($_GET['debug'])) {
            $dbg = ['rows' => [], 'per_source_year_total' => []];
            foreach (['financial_incomes', 'project_payment_items', 'financial_expenses', 'material_out_items', 'material_out_header'] as $t) {
                try {
                    $dbg['rows'][$t] = (int)$db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
                } catch (Exception $e) {
                    $dbg['rows'][$t] = 'ERR: ' . $e->getMessage();
                }
            }
            foreach ($sources as $s) {
                try {
                    $st = $db->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM ({$s['sql']}) x");
                    foreach ($s['params'] as $k => $v) {
                        $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
                    }
                    $st->execute();
                    $row = $st->fetch(PDO::FETCH_NUM);
                    $dbg['per_source_year_total'][$s['type'] . ':' . $s['name']] = ['total' => (float)$row[0], 'rows' => (int)$row[1]];
                } catch (Exception $e) {
                    $dbg['per_source_year_total'][$s['type'] . ':' . $s['name']] = 'ERR: ' . $e->getMessage();
                }
            }
            $resp['debug'] = $dbg;
        }
        out($resp, JSON_NUMERIC_CHECK);
        break;

    // 5. MONITORING PENYERAPAN MATERIAL (RAB vs MATERIAL OUT) - tanpa view
    case 'read_material_monitoring':
        $projectId = (int)($_GET['project_id'] ?? 0);

        $params = [];
        $P = function ($v) use (&$params) {
            $k = ':p' . count($params);
            $params[$k] = $v;
            return $k;
        };

        // RAB: gabung per (proyek, material). Baris tanpa material_id tetap dihitung (key = -id).
        $budWhere = "(LOWER(b.category) = 'material' OR b.material_id IS NOT NULL)"
                  . ($projectId ? " AND b.project_id = {$P($projectId)}" : "");
        $outWhere = "h.status = 'posted' AND h.reversed_at IS NULL"
                  . ($projectId ? " AND h.project_id = {$P($projectId)}" : "");

        $sql = "
            WITH bud AS (
                SELECT b.project_id,
                       b.material_id,
                       MAX(b.item_name)                       AS item_name,
                       MAX(NULLIF(b.unit,''))                 AS unit,
                       SUM(b.qty_plan)                        AS qty_plan,
                       SUM(b.qty_plan * b.unit_price_plan)    AS budget_value,
                       MAX(b.tolerance_pct)                   AS tolerance_pct
                FROM budget_items b
                WHERE {$budWhere}
                GROUP BY b.project_id, COALESCE(b.material_id, -b.id), b.material_id
            ),
            outs AS (
                SELECT h.project_id,
                       i.material_id,
                       SUM(i.qty_out)                AS qty_out,
                       SUM(i.qty_out * i.unit_cost)  AS out_value
                FROM material_out_items i
                JOIN material_out_header h ON h.id = i.material_out_id
                WHERE {$outWhere}
                GROUP BY h.project_id, i.material_id
            )
            SELECT COALESCE(b.project_id, o.project_id)          AS project_id,
                   COALESCE(b.material_id, o.material_id)        AS material_id,
                   COALESCE(m.material_code, '')                 AS item_code,
                   COALESCE(m.material_name, b.item_name, '(Tanpa nama)') AS item_name,
                   COALESCE(b.unit, NULLIF(m.unit,''), '')       AS unit,
                   COALESCE(b.qty_plan, 0)                       AS budget_qty,
                   COALESCE(b.budget_value, 0)                   AS budget_value,
                   COALESCE(b.tolerance_pct, 0)                  AS tolerance_pct,
                   COALESCE(o.qty_out, 0)                        AS out_qty,
                   COALESCE(o.out_value, 0)                      AS out_value
            FROM bud b
            FULL OUTER JOIN outs o
                   ON o.project_id = b.project_id AND o.material_id = b.material_id
            LEFT JOIN materials m ON m.id = COALESCE(b.material_id, o.material_id)
            ORDER BY item_name ASC
        ";

        try {
            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, PDO::PARAM_INT);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('[dashboardCRD] material_monitoring: ' . $e->getMessage());
            out(['success' => false, 'message' => 'Query monitoring material gagal: ' . $e->getMessage()]);
        }

        $kpi    = ['budget_rab' => 0.0, 'material_out' => 0.0, 'sisa_budget' => 0.0, 'persentase' => 0.0];
        $counts = ['ON_TRACK' => 0, 'WARNING' => 0, 'OVERBUDGET' => 0];
        $items  = [];

        foreach ($rows as $r) {
            $plan  = (float)$r['budget_qty'];
            $used  = (float)$r['out_qty'];
            $tol   = (float)$r['tolerance_pct'];
            $noRab = $plan <= 0;

            $kpi['budget_rab']   += (float)$r['budget_value'];
            $kpi['material_out'] += (float)$r['out_value'];

            $usage = $noRab ? ($used > 0 ? 100.0 : 0.0) : ($used / $plan) * 100;

            if ($noRab && $used > 0)                 $status = 'OVERBUDGET';   // keluar tanpa RAB
            elseif ($used > $plan * (1 + $tol / 100)) $status = 'OVERBUDGET';
            elseif ($usage >= 80)                    $status = 'WARNING';
            else                                     $status = 'ON_TRACK';

            $counts[$status]++;

            $items[] = [
                'item_code'     => $r['item_code'],
                'item_name'     => $r['item_name'],
                'unit'          => $r['unit'],
                'budget_qty'    => $plan,
                'budget_value'  => (float)$r['budget_value'],
                'out_qty'       => $used,
                'out_value'     => (float)$r['out_value'],
                'remaining_qty' => $plan - $used,
                'usage_pct'     => round($usage, 1),
                'no_rab'        => $noRab,
                'status'        => $status,
            ];
        }

        usort($items, fn($a, $b) => $b['usage_pct'] <=> $a['usage_pct']);

        $kpi['sisa_budget'] = $kpi['budget_rab'] - $kpi['material_out'];
        $kpi['persentase']  = $kpi['budget_rab'] > 0 ? round($kpi['material_out'] / $kpi['budget_rab'] * 100, 2) : 0.0;

        out(['success' => true, 'kpi' => $kpi, 'status_counts' => $counts, 'items' => $items], JSON_NUMERIC_CHECK);
        break;

    default:
        out(['success' => false, 'message' => 'Aksi tidak ditemukan']);
}