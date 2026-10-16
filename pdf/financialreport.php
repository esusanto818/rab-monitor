<?php
/*
  Document      : pdf/financialreport.php   [PRINT][NEW]
  Description   : Cetak Financial Report = Neraca (kumulatif s/d akhir periode) + Laba Rugi (mutasi periode).
                  Rumus = php-crud/financialreportCRD.php. Akun bersaldo nol disembunyikan.
  Parameter     : period_id (wajib)
*/
require_once __DIR__ . '/print.common.php';
$db = pr_boot();
$period = pr_period($db, intval($_GET['period_id'] ?? 0));
$s = $period['start_date']; $e = $period['end_date'];

// Neraca
$st = $db->prepare("
    SELECT c.account_code, c.account_name, c.account_type,
           COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL THEN
                   CASE WHEN c.account_type IN ('asset', 'bank', 'cash') THEN l.debit - l.credit
                        ELSE l.credit - l.debit END
               ELSE 0 END
           ), 0) AS balance
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed') AND j.journal_date <= :e
     WHERE c.is_active = true AND c.account_type IN ('asset', 'bank', 'cash', 'liability', 'equity')
     GROUP BY c.id, c.account_code, c.account_name, c.account_type
     ORDER BY c.account_code ASC");
$st->execute(['e' => $e]);
$A = []; $L = []; $Q = []; $tA = 0.0; $tL = 0.0; $tQ = 0.0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $b = floatval($r['balance']);
    if (abs($b) < 0.004) continue;
    $it = [$r['account_code'], $r['account_name'], $b];
    if (in_array($r['account_type'], ['asset', 'bank', 'cash'])) { $A[] = $it; $tA += $b; }
    elseif ($r['account_type'] === 'liability')                  { $L[] = $it; $tL += $b; }
    else                                                         { $Q[] = $it; $tQ += $b; }
}
// Laba/rugi kumulatif s/d akhir periode -> ekuitas
$st = $db->prepare("
    SELECT COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL THEN
                   CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                        ELSE -(l.debit - l.credit) END
               ELSE 0 END
           ), 0) AS ni
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed') AND j.journal_date <= :e
     WHERE c.account_type IN ('revenue', 'expense')");
$st->execute(['e' => $e]);
$niCum = floatval($st->fetchColumn());
if (abs($niCum) > 0.004) { $Q[] = ['-', 'Laba (Rugi) Kumulatif s/d Periode Ini', $niCum]; }
$tQ += $niCum;
$balanced = abs($tA - ($tL + $tQ)) < 0.01;

// Laba Rugi periode
$st = $db->prepare("
    SELECT c.account_code, c.account_name, c.account_type,
           COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL THEN
                   CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                        ELSE l.debit - l.credit END
               ELSE 0 END
           ), 0) AS amount
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed') AND j.journal_date BETWEEN :s AND :e
     WHERE c.is_active = true AND c.account_type IN ('revenue', 'expense')
     GROUP BY c.id, c.account_code, c.account_name, c.account_type
     ORDER BY c.account_code ASC");
$st->execute(['s' => $s, 'e' => $e]);
$R = []; $X = []; $tR = 0.0; $tX = 0.0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $a = floatval($r['amount']);
    if ($r['account_type'] === 'revenue') { $tR += $a; if (abs($a) > 0.004) $R[] = [$r['account_code'], $r['account_name'], $a]; }
    else                                  { $tX += $a; if (abs($a) > 0.004) $X[] = [$r['account_code'], $r['account_name'], $a]; }
}
$netP = $tR - $tX;

pr_open('Financial Report - ' . $period['period_name'], 'FINANCIAL REPORT',
    ['Periode: ' . pr_e($period['period_name']) . ' (' . pr_e(pr_date($s)) . ' s/d ' . pr_e(pr_date($e)) . ')'],
    '[ NERACA: ' . ($balanced ? 'BALANCE' : 'TIDAK BALANCE (selisih ' . pr_n($tA - $tL - $tQ) . ')') . ' ]', $balanced);
?>
<h2 style="font-size:15px; margin:10px 0 0 0;">NERACA (per <?php echo pr_e(pr_date($e)); ?>)</h2>
<?php
echo pr_section('ASET', $A, 'TOTAL ASET', $tA);
echo pr_section('LIABILITAS', $L, 'TOTAL LIABILITAS', $tL);
echo pr_section('EKUITAS', $Q, 'TOTAL EKUITAS', $tQ);
?>
<div class="grand"><span>TOTAL LIABILITAS &amp; EKUITAS</span><span><?php echo pr_n($tL + $tQ); ?></span></div>

<h2 style="font-size:15px; margin:22px 0 0 0;">LAPORAN LABA RUGI (<?php echo pr_e(pr_date($s)); ?> s/d <?php echo pr_e(pr_date($e)); ?>)</h2>
<?php
echo pr_section('PENDAPATAN', $R, 'TOTAL PENDAPATAN', $tR);
echo pr_section('BEBAN / BIAYA', $X, 'TOTAL BEBAN', $tX);
?>
<div class="grand"><span><?php echo $netP >= 0 ? 'LABA BERSIH PERIODE' : 'RUGI BERSIH PERIODE'; ?></span><span><?php echo pr_n($netP); ?></span></div>
<?php pr_close(); ?>