<?php
/*
  Document      : pdf/inventoryrecon.php   [PRINT][NEW]
  Description   : Cetak Rekonsiliasi Persediaan: ringkasan (buku vs stok), dokumen bermasalah, per item, per gudang.
                  Logika = php-func/function.inventory.recon.php (sama dengan layar).
  Parameter     : as_of (YYYY-MM-DD, default hari ini), account_id (default = akun persediaan rule ISSUE_PROJECT)
*/
require_once __DIR__ . '/print.common.php';
$db = pr_boot();
require_once __DIR__ . '/../php-func/function.inventory.recon.php';

$asOf = (string)($_GET['as_of'] ?? date('Y-m-d'));
$accId = intval($_GET['account_id'] ?? 0);
try {
    $r = ir_reconcile($db, $asOf, $accId);
} catch (Throwable $e) {
    echo pr_e($e->getMessage());
    exit;
}
$s = $r['summary'];
$ok = ($s['status'] === 'SESUAI');

pr_open('Rekonsiliasi Persediaan - ' . $asOf, 'REKONSILIASI PERSEDIAAN',
    ['Per Tanggal: ' . pr_e(pr_date($asOf)) . ' &nbsp;|&nbsp; Akun: ' . pr_e($s['account_label'])],
    '[ STATUS: ' . ($ok ? 'SESUAI' : 'SELISIH ' . pr_n($s['diff'])) . ' ]', $ok);
?>
<table class="rpt fx">
    <colgroup><col><col style="width:170px"></colgroup>
    <tr><td>Nilai Buku (saldo akun persediaan)</td><td class="r"><?php echo pr_n($s['book_balance']); ?></td></tr>
    <tr class="z"><td>Nilai Stok (stock_movements, IN - OUT)</td><td class="r"><?php echo pr_n($s['stock_value']); ?></td></tr>
    <tr class="tot"><td>Selisih (Buku - Stok)</td><td class="r"><?php echo pr_n($s['diff']); ?></td></tr>
    <tr><td>Dijelaskan oleh dokumen bermasalah</td><td class="r"><?php echo pr_n($s['explained']); ?></td></tr>
    <tr class="z"><td>Tidak terjelaskan (jurnal manual / penyesuaian langsung)</td><td class="r"><?php echo pr_n($s['unexplained']); ?></td></tr>
</table>

<?php
// dokumen bermasalah
$prob = array_values(array_filter($r['docs'], function ($d) { return $d['status'] === 'TIDAK ADA JURNAL' || $d['status'] === 'NILAI BEDA'; }));
echo '<h3>DOKUMEN BERMASALAH (' . count($prob) . ' dari ' . $s['docs_total'] . ' dokumen)</h3>';
echo '<table class="rpt fx"><colgroup><col style="width:80px"><col style="width:120px"><col><col style="width:130px"><col style="width:130px"><col style="width:130px"></colgroup>' .
     '<tr><th>Tanggal</th><th>Jenis</th><th>No. Dokumen</th><th class="r">Nilai Stok</th><th class="r">Nilai Jurnal</th><th>Status</th></tr>';
$i = 0;
foreach ($prob as $d) {
    echo '<tr' . ($i++ % 2 ? ' class="z"' : '') . '><td>' . pr_e(pr_date($d['doc_date'])) . '</td><td>' . pr_e($d['reference_type']) . '</td><td>' . pr_e($d['reference_no']) .
         '</td><td class="r">' . pr_n($d['value']) . '</td><td class="r">' . ($d['journal_amount'] === null ? '-' : pr_n($d['journal_amount'])) . '</td><td>' . pr_e($d['status']) . '</td></tr>';
}
if (!count($prob)) echo '<tr><td colspan="6" class="muted">Tidak ada dokumen bermasalah.</td></tr>';
echo '</table>';

// per item
echo '<h3>NILAI STOK PER ITEM</h3>';
echo '<table class="rpt fx"><colgroup><col style="width:90px"><col><col style="width:60px"><col style="width:110px"><col style="width:110px"><col style="width:130px"></colgroup>' .
     '<tr><th>Kode</th><th>Material</th><th>Sat</th><th class="r">Qty</th><th class="r">Biaya Rata-rata</th><th class="r">Nilai</th></tr>';
$i = 0; $tot = 0.0;
foreach ($r['items'] as $it) {
    $tot += $it['value'];
    echo '<tr' . ($i++ % 2 ? ' class="z"' : '') . '><td>' . pr_e($it['material_code']) . '</td><td>' . pr_e($it['material_name']) . '</td><td>' . pr_e($it['unit']) .
         '</td><td class="r">' . number_format($it['qty'], 4, '.', ',') . '</td><td class="r">' . pr_n($it['avg_cost']) . '</td><td class="r">' . pr_n($it['value']) . '</td></tr>';
}
if (!count($r['items'])) echo '<tr><td colspan="6" class="muted">Tidak ada saldo stok.</td></tr>';
echo '<tr class="tot"><td colspan="5">TOTAL</td><td class="r">' . pr_n($tot) . '</td></tr></table>';

// per gudang
echo '<h3>NILAI STOK PER GUDANG</h3>';
echo '<table class="rpt fx"><colgroup><col><col style="width:150px"><col style="width:150px"></colgroup>' .
     '<tr><th>Gudang</th><th class="r">Item Bersaldo</th><th class="r">Nilai</th></tr>';
$i = 0;
foreach ($r['warehouses'] as $w) {
    echo '<tr' . ($i++ % 2 ? ' class="z"' : '') . '><td>' . pr_e($w['warehouse_name']) . '</td><td class="r">' . (int)$w['item_count'] . '</td><td class="r">' . pr_n($w['value']) . '</td></tr>';
}
echo '</table>';
pr_close();