<?php
/*
  Document      : pdf/generalledger.php   [PRINT][NEW]
  Description   : Cetak General Ledger (buku besar) per akun, dengan saldo berjalan.
                  Rumus = php-crud/generalledgerCRD.php (jurnal posted/reversed; akun asset/bank/cash/expense debit-normal;
                  saldo awal per akun bila periode difilter).
  Parameter     : account_id = 0/kosong -> semua akun (dikelompokkan per akun)
                  period_id  = 0/kosong -> semua histori (saldo awal 0)
  Catatan       : Sama seperti layar GL, akun yang hanya punya saldo awal tanpa mutasi dalam periode tidak ditampilkan.
*/
require_once __DIR__ . '/print.common.php';
$db = pr_boot();

$accountId = intval($_GET['account_id'] ?? 0);
$periodId  = intval($_GET['period_id'] ?? 0);

$startDate = null; $endDate = null; $periodLabel = 'Semua Periode';
if ($periodId > 0) {
    $period = pr_period($db, $periodId);
    $startDate = $period['start_date']; $endDate = $period['end_date'];
    $periodLabel = $period['period_name'] . ' (' . pr_date($startDate) . ' s/d ' . pr_date($endDate) . ')';
}
$accountLabel = 'Semua Akun';
if ($accountId > 0) {
    $st = $db->prepare("SELECT account_code, account_name FROM chart_of_accounts WHERE id = :id");
    $st->execute(['id' => $accountId]);
    $acc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$acc) { echo 'Akun tidak ditemukan.'; exit; }
    $accountLabel = $acc['account_code'] . ' - ' . $acc['account_name'];
}

// Kondisi dinamis (nama parameter dibedakan: PDO pgsql tidak mengizinkan nama yang sama dipakai dua kali)
$conds = ["j.status IN ('posted', 'reversed')"]; $params = [];
if ($accountId > 0) { $conds[] = 'l.account_id = :acc'; $params['acc'] = $accountId; }
if ($startDate !== null) {
    $conds[] = 'j.journal_date BETWEEN :start_date AND :end_date';
    $params['start_date'] = $startDate; $params['end_date'] = $endDate;
}
$where = implode(' AND ', $conds);

$opening = '0';
if ($startDate !== null) {
    $oc = "l2.account_id = l.account_id AND j2.status IN ('posted','reversed') AND j2.journal_date < :ob_start_date";
    if ($accountId > 0) { $oc .= ' AND l2.account_id = :ob_acc'; $params['ob_acc'] = $accountId; }
    $opening = "(SELECT COALESCE(SUM(CASE WHEN c2.account_type IN ('asset','bank','cash','expense') THEN l2.debit - l2.credit ELSE l2.credit - l2.debit END), 0)
                   FROM journal_lines l2
                   JOIN journal_entries j2 ON j2.id = l2.journal_id
                   JOIN chart_of_accounts c2 ON c2.id = l2.account_id
                  WHERE {$oc})";
    $params['ob_start_date'] = $startDate;
}

$st = $db->prepare("
    SELECT j.journal_date, j.ref_no, l.description AS line_desc, j.description AS header_desc,
           l.debit, l.credit, l.account_id, c.account_code, c.account_name,
           {$opening} AS opening_balance,
           SUM(CASE WHEN c.account_type IN ('asset','bank','cash','expense') THEN l.debit - l.credit ELSE l.credit - l.debit END)
             OVER (PARTITION BY l.account_id ORDER BY j.journal_date, j.id, l.id
                   ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS cum_in_range
      FROM journal_lines l
      JOIN journal_entries j ON j.id = l.journal_id
      JOIN chart_of_accounts c ON c.id = l.account_id
     WHERE {$where}
     ORDER BY c.account_code ASC, j.journal_date ASC, j.id ASC, l.id ASC");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// kelompokkan per akun
$groups = [];
foreach ($rows as $r) { $groups[$r['account_id']][] = $r; }

// [LANDSCAPE] GL punya 6 kolom -> A4 landscape agar kolom Keterangan tidak sempit/terbungkus 3 baris
pr_open('General Ledger', 'GENERAL LEDGER',
    ['Akun: ' . pr_e($accountLabel) . ' &nbsp;|&nbsp; Periode: ' . pr_e($periodLabel)],
    '', true, '@page { size: A4 landscape; margin: 12mm; }');

$cols = '<colgroup><col style="width:85px"><col style="width:110px"><col><col style="width:125px"><col style="width:125px"><col style="width:135px"></colgroup>';
$grandD = 0.0; $grandK = 0.0;
foreach ($groups as $accRows) {
    $first = $accRows[0];
    $bal0 = floatval($first['opening_balance']);
    $sd = 0.0; $sk = 0.0; $bal = $bal0;
    echo '<h3>Akun: ' . pr_e($first['account_code'] . ' - ' . $first['account_name']) . '</h3>';
    echo '<table class="rpt fx">' . $cols .
         '<tr><th>Tanggal</th><th>No. Jurnal</th><th>Keterangan</th><th class="r">Debit</th><th class="r">Kredit</th><th class="r">Saldo</th></tr>';
    if ($startDate !== null) {
        echo '<tr><td colspan="5"><i>Saldo awal</i></td><td class="r">' . pr_n($bal0) . '</td></tr>';
    }
    $i = 0;
    foreach ($accRows as $r) {
        $d = floatval($r['debit']); $k = floatval($r['credit']); $sd += $d; $sk += $k;
        $bal = $bal0 + floatval($r['cum_in_range']);
        $desc = $r['line_desc'] ?: $r['header_desc'];
        echo '<tr' . ($i++ % 2 ? ' class="z"' : '') . '><td>' . pr_e(pr_date($r['journal_date'])) . '</td><td>' . pr_e($r['ref_no']) . '</td><td>' . pr_e($desc) .
             '</td><td class="r">' . ($d > 0.004 ? pr_n($d) : '') . '</td><td class="r">' . ($k > 0.004 ? pr_n($k) : '') . '</td><td class="r">' . pr_n($bal) . '</td></tr>';
    }
    echo '<tr class="tot"><td colspan="3">TOTAL / SALDO AKHIR</td><td class="r">' . pr_n($sd) . '</td><td class="r">' . pr_n($sk) . '</td><td class="r">' . pr_n($bal) . '</td></tr></table>';
    $grandD += $sd; $grandK += $sk;
}
if (!count($groups)) { echo '<p class="muted">Tidak ada transaksi.</p>'; }
else { echo '<div class="grand"><span>TOTAL ' . count($rows) . ' BARIS (Debit / Kredit)</span><span>' . pr_n($grandD) . ' / ' . pr_n($grandK) . '</span></div>'; }
pr_close();