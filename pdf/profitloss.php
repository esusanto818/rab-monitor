<?php
/*
  Document      : pdf/profitloss.php   [PRINT][NEW]
  Description   : Cetak Laporan Laba Rugi per periode (jurnal posted/reversed). Kolom ganda: "Periode Ini" (mutasi dalam
                  periode) dan "Tahun Berjalan (YTD)" (1 Januari tahun periode s/d akhir periode). Rumus = php-crud/profitlossCRD.php.
                  Akun yang kedua kolomnya nol disembunyikan.
  [ALIGN]       : Seluruh laporan (pendapatan, beban, laba bersih) dirender dalam SATU tabel dengan satu colgroup,
                  sehingga nilai Laba Bersih pasti tepat di bawah kolom Periode Ini dan Tahun Berjalan (YTD).
  Parameter     : period_id (wajib)
*/
require_once __DIR__ . '/print.common.php';
$db = pr_boot();
$period = pr_period($db, intval($_GET['period_id'] ?? 0));

// [YTD] awal tahun berjalan = 1 Januari tahun akhir periode (bila periode mulai sebelum itu, pakai awal periode)
$ytdStart = date('Y', strtotime($period['end_date'])) . '-01-01';
if ($period['start_date'] < $ytdStart) { $ytdStart = $period['start_date']; }

$st = $db->prepare("
    SELECT c.account_code, c.account_name, c.account_type,
           COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL AND j.journal_date BETWEEN :s AND :e THEN
                   CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                        WHEN c.account_type = 'expense' THEN l.debit - l.credit
                        ELSE 0 END
               ELSE 0 END
           ), 0) AS amount,
           COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL THEN
                   CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                        WHEN c.account_type = 'expense' THEN l.debit - l.credit
                        ELSE 0 END
               ELSE 0 END
           ), 0) AS amount_cum
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed')
           AND j.journal_date BETWEEN :ys AND :ce
     WHERE c.is_active = true AND c.account_type IN ('revenue', 'expense')
     GROUP BY c.id, c.account_code, c.account_name, c.account_type
     ORDER BY c.account_code ASC");
$st->execute(['s' => $period['start_date'], 'e' => $period['end_date'], 'ys' => $ytdStart, 'ce' => $period['end_date']]);

$rev = []; $exp = []; $tRev = 0.0; $tExp = 0.0; $tRevC = 0.0; $tExpC = 0.0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $a = floatval($r['amount']); $c = floatval($r['amount_cum']);
    $show = (abs($a) > 0.004 || abs($c) > 0.004);
    if ($r['account_type'] === 'revenue') { $tRev += $a; $tRevC += $c; if ($show) $rev[] = [$r['account_code'], $r['account_name'], $a, $c]; }
    else                                  { $tExp += $a; $tExpC += $c; if ($show) $exp[] = [$r['account_code'], $r['account_name'], $a, $c]; }
}
$net = $tRev - $tExp; $netC = $tRevC - $tExpC;

// Baris-baris satu seksi di dalam tabel tunggal (judul seksi, header kolom, item, total)
function pl_rows($title, $items, $totalLabel, $t, $tc) {
    $h = '<tr><td colspan="4" class="sec">' . pr_e($title) . '</td></tr>' .
         '<tr><th>Kode</th><th>Nama Akun</th><th class="r">Periode Ini</th><th class="r">Tahun Berjalan (YTD)</th></tr>';
    $i = 0;
    foreach ($items as $it) {
        $h .= '<tr' . ($i++ % 2 ? ' class="z"' : '') . '><td class="c">' . pr_e($it[0]) . '</td><td>' . pr_e($it[1]) . '</td><td class="r">' . pr_n($it[2]) . '</td><td class="r">' . pr_n($it[3]) . '</td></tr>';
    }
    if (!count($items)) { $h .= '<tr><td colspan="4" class="muted">Tidak ada saldo.</td></tr>'; }
    return $h . '<tr class="tot"><td colspan="2">' . pr_e($totalLabel) . '</td><td class="r">' . pr_n($t) . '</td><td class="r">' . pr_n($tc) . '</td></tr>';
}

pr_open('Laba Rugi - ' . $period['period_name'], 'LAPORAN LABA RUGI',
    ['Periode: ' . pr_e($period['period_name']) . ' (' . pr_e(pr_date($period['start_date'])) . ' s/d ' . pr_e(pr_date($period['end_date'])) . ')',
     'YTD: ' . pr_e(pr_date($ytdStart)) . ' s/d ' . pr_e(pr_date($period['end_date']))]);
?>
<table class="rpt fx">
    <colgroup><col style="width:70px"><col><col style="width:150px"><col style="width:170px"></colgroup>
<?php
echo pl_rows('PENDAPATAN', $rev, 'TOTAL PENDAPATAN', $tRev, $tRevC);
echo pl_rows('BEBAN / BIAYA', $exp, 'TOTAL BEBAN', $tExp, $tExpC);
?>
    <tr class="net">
        <td colspan="2"><?php echo $net >= 0 ? 'LABA BERSIH' : 'RUGI BERSIH'; ?></td>
        <td class="r"><?php echo pr_n($net); ?></td>
        <td class="r"><?php echo pr_n($netC); ?></td>
    </tr>
</table>
<?php pr_close(); ?>
<!-- profitloss print tpl v3: tabel tunggal -->