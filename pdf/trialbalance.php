<?php
/*
  Document      : pdf/trialbalance.php   [PRINT][NEW]
  Description   : Cetak Trial Balance per periode (saldo kumulatif s/d akhir periode, jurnal posted/reversed).
                  Rumus = php-crud/trialbalanceCRD.php (akun bank & cash debit-normal).
  Parameter     : period_id (wajib), hide_zero=0 untuk menampilkan akun bersaldo nol (default disembunyikan)
*/
require_once __DIR__ . '/print.common.php';
$db = pr_boot();
$period = pr_period($db, intval($_GET['period_id'] ?? 0));
$asOf = $period['end_date'];
$hideZero = (($_GET['hide_zero'] ?? '1') !== '0');

$st = $db->prepare("
    SELECT c.account_code, c.account_name, c.account_type,
           COALESCE(SUM(
               CASE WHEN c.account_type IN ('asset','bank','cash','expense') THEN l.debit - l.credit
                    ELSE l.credit - l.debit END
           ), 0) AS balance
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed')
           AND j.journal_date <= :as_of
     WHERE c.is_active = true
     GROUP BY c.id, c.account_code, c.account_name, c.account_type
     ORDER BY c.account_code ASC");
$st->execute(['as_of' => $asOf]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$data = []; $tD = 0.0; $tK = 0.0;
foreach ($rows as $r) {
    $debitNormal = in_array($r['account_type'], ['asset', 'bank', 'cash', 'expense']);
    $b = floatval($r['balance']);
    $d = 0.0; $k = 0.0;
    if ($debitNormal) { if ($b >= 0) $d = $b; else $k = abs($b); }
    else              { if ($b >= 0) $k = $b; else $d = abs($b); }
    $tD += $d; $tK += $k;
    if ($hideZero && abs($d) < 0.004 && abs($k) < 0.004) continue;
    $data[] = [$r['account_code'], $r['account_name'], ucfirst($r['account_type']), $d, $k];
}
$ok = abs($tD - $tK) < 0.01;

pr_open('Trial Balance - ' . $period['period_name'], 'TRIAL BALANCE',
    ['Per Tanggal: ' . pr_e(pr_date($asOf)) . ' (' . pr_e($period['period_name']) . ')'],
    '[ STATUS: ' . ($ok ? 'BALANCE' : 'TIDAK BALANCE (selisih ' . pr_n($tD - $tK) . ')') . ' ]', $ok);
?>
<table class="rpt">
    <tr><th>Kode</th><th>Nama Akun</th><th>Tipe</th><th class="r">Debit</th><th class="r">Kredit</th></tr>
<?php
$i = 0;
foreach ($data as $r) {
    echo '<tr' . ($i++ % 2 ? ' class="z"' : '') . '><td>' . pr_e($r[0]) . '</td><td>' . pr_e($r[1]) . '</td><td>' . pr_e($r[2]) . '</td>' .
         '<td class="r">' . ($r[3] > 0.004 ? pr_n($r[3]) : '') . '</td><td class="r">' . ($r[4] > 0.004 ? pr_n($r[4]) : '') . '</td></tr>';
}
if (!count($data)) { echo '<tr><td colspan="5" class="muted">Tidak ada saldo.</td></tr>'; }
?>
    <tr class="tot"><td colspan="3">TOTAL</td><td class="r"><?php echo pr_n($tD); ?></td><td class="r"><?php echo pr_n($tK); ?></td></tr>
</table>
<?php pr_close(); ?>