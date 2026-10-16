<?php
/*
  Document      : pdf/profitloss.php   [PRINT][NEW]
  Description   : Cetak Laporan Laba Rugi per periode (mutasi DALAM periode, jurnal posted/reversed).
                  Rumus = php-crud/profitlossCRD.php. Akun bernilai nol disembunyikan.
  Parameter     : period_id (wajib)
*/
require_once __DIR__ . '/print.common.php';
$db = pr_boot();
$period = pr_period($db, intval($_GET['period_id'] ?? 0));

$st = $db->prepare("
    SELECT c.account_code, c.account_name, c.account_type,
           COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL THEN
                   CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                        WHEN c.account_type = 'expense' THEN l.debit - l.credit
                        ELSE 0 END
               ELSE 0 END
           ), 0) AS amount
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed')
           AND j.journal_date BETWEEN :s AND :e
     WHERE c.is_active = true AND c.account_type IN ('revenue', 'expense')
     GROUP BY c.id, c.account_code, c.account_name, c.account_type
     ORDER BY c.account_code ASC");
$st->execute(['s' => $period['start_date'], 'e' => $period['end_date']]);

$rev = []; $exp = []; $tRev = 0.0; $tExp = 0.0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $a = floatval($r['amount']);
    if ($r['account_type'] === 'revenue') { $tRev += $a; if (abs($a) > 0.004) $rev[] = [$r['account_code'], $r['account_name'], $a]; }
    else                                  { $tExp += $a; if (abs($a) > 0.004) $exp[] = [$r['account_code'], $r['account_name'], $a]; }
}
$net = $tRev - $tExp;

pr_open('Laba Rugi - ' . $period['period_name'], 'LAPORAN LABA RUGI',
    ['Periode: ' . pr_e($period['period_name']) . ' (' . pr_e(pr_date($period['start_date'])) . ' s/d ' . pr_e(pr_date($period['end_date'])) . ')']);
echo pr_section('PENDAPATAN', $rev, 'TOTAL PENDAPATAN', $tRev);
echo pr_section('BEBAN / BIAYA', $exp, 'TOTAL BEBAN', $tExp);
?>
<div class="grand"><span><?php echo $net >= 0 ? 'LABA BERSIH' : 'RUGI BERSIH'; ?></span><span><?php echo pr_n($net); ?></span></div>
<?php pr_close(); ?>