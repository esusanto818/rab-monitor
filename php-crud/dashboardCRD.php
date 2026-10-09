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

    // 4. PERFORMA KEUANGAN (PENDAPATAN VS PENGELUARAN PER BULAN)
    case 'read_financial_performance':
        $year      = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $projectId = (int)($_GET['project_id'] ?? 0);
        $from      = sprintf('%04d-01-01', $year);
        $to        = sprintf('%04d-01-01', $year + 1);

        $params = [];
        $P = function ($v) use (&$params) {
            $k = ':p' . count($params);
            $params[$k] = $v;
            return $k;
        };
        $cancelled = "('VOID','CANCELLED','CANCELED','BATAL')";

        $inc = [];
        $exp = [];

        if (SRC_FIN_INCOME) {
            $inc[] = "SELECT EXTRACT(MONTH FROM income_date)::int AS bln, amount
                      FROM financial_incomes
                      WHERE income_date >= CAST({$P($from)} AS date) AND income_date < CAST({$P($to)} AS date)
                        AND COALESCE(UPPER(status),'') NOT IN {$cancelled}"
                   . ($projectId ? " AND project_id = {$P($projectId)}" : "");
        }
        if (SRC_PRJ_PAYMENT) {
            $inc[] = "SELECT EXTRACT(MONTH FROM pp.payment_date)::int AS bln, ppi.amount_paid AS amount
                      FROM project_payment_items ppi
                      JOIN project_payments pp ON pp.id = ppi.payment_id
                      JOIN project_invoices pi ON pi.id = ppi.invoice_id
                      WHERE pp.payment_date >= CAST({$P($from)} AS date) AND pp.payment_date < CAST({$P($to)} AS date)"
                   . ($projectId ? " AND pi.project_id = {$P($projectId)}" : "");
        }
        if (SRC_FIN_EXPENSE) {
            $exp[] = "SELECT EXTRACT(MONTH FROM expense_date)::int AS bln, amount
                      FROM financial_expenses
                      WHERE expense_date >= CAST({$P($from)} AS date) AND expense_date < CAST({$P($to)} AS date)
                        AND COALESCE(UPPER(status),'') NOT IN {$cancelled}"
                   . ($projectId ? " AND project_id = {$P($projectId)}" : "");
        }
        if (SRC_MATERIAL_OUT) {
            $exp[] = "SELECT EXTRACT(MONTH FROM h.issue_date)::int AS bln, (i.qty_out * i.unit_cost) AS amount
                      FROM material_out_items i
                      JOIN material_out_header h ON h.id = i.material_out_id
                      WHERE h.issue_date >= CAST({$P($from)} AS date) AND h.issue_date < CAST({$P($to)} AS date)
                        AND h.status = 'posted' AND h.reversed_at IS NULL"
                   . ($projectId ? " AND h.project_id = {$P($projectId)}" : "");
        }

        $empty  = "SELECT 0::int AS bln, 0::numeric AS amount WHERE false";
        $incSql = $inc ? implode(' UNION ALL ', $inc) : $empty;
        $expSql = $exp ? implode(' UNION ALL ', $exp) : $empty;

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
            // Jangan disembunyikan lagi: kirim error ke frontend agar terlihat.
            error_log('[dashboardCRD] financial_performance: ' . $e->getMessage());
            out(['success' => false, 'message' => 'Query performa keuangan gagal: ' . $e->getMessage()]);
        }

        $resp = [
            'success'  => true,
            'year'     => $year,
            'labels'   => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
            'datasets' => ['pendapatan' => $pendapatan, 'pengeluaran' => $pengeluaran],
        ];

        // ?debug=1 -> tampilkan jumlah baris tiap sumber (untuk diagnosa "kenapa 0")
        if (!empty($_GET['debug'])) {
            $dbg = [];
            foreach (['financial_incomes', 'project_payment_items', 'financial_expenses', 'material_out_items', 'material_out_header'] as $t) {
                try {
                    $dbg[$t] = (int)$db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
                } catch (Exception $e) {
                    $dbg[$t] = 'ERR: ' . $e->getMessage();
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