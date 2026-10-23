<?php
/*
  Document      : pdf/balancesheet.php   [PRINT-BS][NEW]
  Description   : Halaman cetak Neraca Keuangan (HTML siap cetak, A4). Dibuka dari tombol Printing di Balance Sheet
                  (balancesheet.js -> ../pdf/balancesheet.php?period_id=ID). Dialog cetak browser hanya muncul saat tombol
                  "Cetak / Simpan PDF" diklik (pilih "Save as PDF" bila ingin file PDF).
  Catatan       : - Rumus sama dengan php-crud/balancesheetCRD.php (saldo kumulatif s/d akhir periode, jurnal posted/reversed;
                    laba berjalan = pendapatan - beban).
                  - Baris akun bersaldo nol disembunyikan agar cetakan ringkas.
                  - Sebelumnya file ini membuka halaman Journal Entry (salah isi); file ini menggantikannya.
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Sesi berakhir, silakan login kembali.';
    exit;
}

$periodId = intval($_GET['period_id'] ?? $_REQUEST['period_id'] ?? 0);
if ($periodId <= 0) { echo 'Periode wajib dipilih.'; exit; }

$db = new crud();

$st = $db->prepare("SELECT period_name, start_date, end_date FROM accounting_periods WHERE id = :id");
$st->execute(['id' => $periodId]);
$period = $st->fetch(PDO::FETCH_ASSOC);
if (!$period) { echo 'Periode tidak ditemukan.'; exit; }
$asOf = $period['end_date'];

// Saldo akun neraca s/d akhir periode (sama dengan balancesheetCRD.php)
$st = $db->prepare("
    SELECT c.account_code, c.account_name, c.account_type,
           COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL THEN
                   CASE WHEN c.account_type IN ('asset', 'bank', 'cash') THEN l.debit - l.credit
                        WHEN c.account_type IN ('liability', 'equity') THEN l.credit - l.debit
                        ELSE 0 END
               ELSE 0 END
           ), 0) AS balance
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed')
           AND j.journal_date <= :as_of
     WHERE c.is_active = true
       AND c.account_type IN ('asset', 'bank', 'cash', 'liability', 'equity')
     GROUP BY c.id, c.account_code, c.account_name, c.account_type
     ORDER BY c.account_code ASC");
$st->execute(['as_of' => $asOf]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Laba berjalan kumulatif = pendapatan (kredit - debit) - beban (debit - kredit)
$st = $db->prepare("
    SELECT COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL THEN
                   CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                        WHEN c.account_type = 'expense' THEN l.credit - l.debit
                        ELSE 0 END
               ELSE 0 END
           ), 0) AS net_profit
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed')
           AND j.journal_date <= :as_of
     WHERE c.account_type IN ('revenue', 'expense')");
$st->execute(['as_of' => $asOf]);
$earnings = floatval($st->fetch(PDO::FETCH_ASSOC)['net_profit'] ?? 0);

$assets = []; $liabs = []; $equities = [];
$tAsset = 0.0; $tLiab = 0.0; $tEquity = 0.0;
foreach ($rows as $r) {
    $bal = floatval($r['balance']);
    $tipe = $r['account_type'];
    if (in_array($tipe, ['asset', 'bank', 'cash'])) { $tAsset += $bal; if (abs($bal) > 0.004) $assets[] = [$r['account_code'], $r['account_name'], $bal]; }
    elseif ($tipe === 'liability')                  { $tLiab  += $bal; if (abs($bal) > 0.004) $liabs[]  = [$r['account_code'], $r['account_name'], $bal]; }
    elseif ($tipe === 'equity')                     { $tEquity += $bal; if (abs($bal) > 0.004) $equities[] = [$r['account_code'], $r['account_name'], $bal]; }
}
if (abs($earnings) > 0.001) { $equities[] = ['-', 'Laba / (Rugi) Periode Berjalan', $earnings]; $tEquity += $earnings; }
$tLE = $tLiab + $tEquity;
$balanced = abs($tAsset - $tLE) < 0.01;

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function n($v) { return number_format((float)$v, 2, '.', ','); }
function section($title, $items, $totalLabel, $total) {
    $h = '<h3>' . e($title) . '</h3><table class="rpt">';
    foreach ($items as $it) {
        $h .= '<tr><td class="c">' . e($it[0]) . '</td><td>' . e($it[1]) . '</td><td class="r">' . n($it[2]) . '</td></tr>';
    }
    if (!count($items)) { $h .= '<tr><td colspan="3" class="muted">Tidak ada saldo.</td></tr>'; }
    $h .= '<tr class="tot"><td colspan="2">' . e($totalLabel) . '</td><td class="r">' . n($total) . '</td></tr></table>';
    return $h;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Neraca Keuangan - <?php echo e($period['period_name']); ?></title>
<style>
    @page { size: A4 portrait; margin: 15mm; }
    body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; font-size: 12px; margin: 0; padding: 16px; }
    h1 { text-align: center; font-size: 18px; margin: 0 0 4px 0; }
    .sub { text-align: center; color: #333; margin-bottom: 4px; }
    .status { text-align: center; font-weight: bold; margin: 6px 0 14px 0; color: <?php echo $balanced ? '#1b7a3a' : '#b00020'; ?>; }
    h3 { font-size: 14px; margin: 16px 0 6px 0; border-bottom: 2px solid #000; padding-bottom: 3px; }
    table.rpt { width: 100%; border-collapse: collapse; }
    table.rpt td { padding: 4px 6px; }
    table.rpt tr:nth-child(even) td { background: #f1f1f1; }          /* zebra, tetap terlihat saat cetak */
    table.rpt tr.tot td { font-weight: bold; border-top: 1px solid #000; background: #fff; }
    td.c { width: 70px; } td.r { text-align: right; white-space: nowrap; }
    .muted { color: #777; font-style: italic; }
    .grand { margin-top: 18px; padding: 8px 6px; border-top: 2px solid #000; border-bottom: 2px solid #000; font-size: 14px; font-weight: bold; display: flex; justify-content: space-between; }
    .foot { margin-top: 16px; font-size: 10px; color: #555; }
    .noprint { margin-bottom: 12px; text-align: right; }
    .noprint button { padding: 6px 14px; cursor: pointer; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @media print { .noprint { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">Cetak / Simpan PDF</button></div>

<h1>NERACA KEUANGAN</h1>
<div class="sub">Per Tanggal: <?php echo e(date('d/m/Y', strtotime($asOf))); ?> (<?php echo e($period['period_name']); ?>)</div>
<div class="status">[ STATUS: <?php echo $balanced ? 'BALANCE' : 'TIDAK BALANCE (selisih ' . n($tAsset - $tLE) . ')'; ?> ]</div>

<?php
echo section('ASET (AKTIVA)', $assets, 'TOTAL ASET', $tAsset);
echo section('LIABILITAS (KEWAJIBAN)', $liabs, 'TOTAL LIABILITAS', $tLiab);
echo section('EKUITAS (MODAL)', $equities, 'TOTAL EKUITAS', $tEquity);
?>
<div class="grand"><span>TOTAL LIABILITAS &amp; EKUITAS</span><span><?php echo n($tLE); ?></span></div>

<div class="foot">Dicetak: <?php echo date('d/m/Y H:i'); ?></div>
</body>
</html>