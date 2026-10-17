<?php
/*
  Document      : pdf/print.common.php   [PRINT][NEW]
  Description   : Helper bersama halaman cetak laporan keuangan (trialbalance.php, profitloss.php,
                  financialreport.php, journal.php): boot sesi + koneksi, periode, kerangka HTML A4, format angka.
                  Halaman cetak = HTML siap cetak; dialog cetak browser baru muncul saat tombol "Cetak / Simpan PDF" diklik.
*/

function pr_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pr_n($v) { return number_format((float)$v, 2, '.', ','); }

// Sesi + koneksi PDO. Mengembalikan objek crud.
function pr_boot() {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    require_once __DIR__ . '/../php-func/function.all.php';
    require_once __DIR__ . '/../php-class/crudClass.php';
    header('Content-Type: text/html; charset=utf-8');
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo 'Sesi berakhir, silakan login kembali.';
        exit;
    }
    return new crud();
}

// Ambil periode akuntansi; keluar dengan pesan bila tidak valid.
function pr_period($db, $periodId) {
    if ($periodId <= 0) { echo 'Periode wajib dipilih.'; exit; }
    $st = $db->prepare("SELECT id, period_name, start_date, end_date FROM accounting_periods WHERE id = :id");
    $st->execute(['id' => $periodId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { echo 'Periode tidak ditemukan.'; exit; }
    return $p;
}

function pr_date($d) { return $d ? date('d/m/Y', strtotime($d)) : '-'; }

// Buka halaman: $lines = baris sub-judul, $status = teks status (opsional), $ok = warna status
function pr_open($docTitle, $heading, $lines = [], $status = '', $ok = true) {
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title><?php echo pr_e($docTitle); ?></title>
<style>
    @page { size: A4 portrait; margin: 15mm; }
    body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; font-size: 12px; margin: 0; padding: 16px; }
    h1 { text-align: center; font-size: 18px; margin: 0 0 4px 0; }
    .sub { text-align: center; color: #333; margin-bottom: 4px; }
    .status { text-align: center; font-weight: bold; margin: 6px 0 14px 0; }
    h3 { font-size: 14px; margin: 16px 0 6px 0; border-bottom: 2px solid #000; padding-bottom: 3px; }
    table.rpt { width: 100%; border-collapse: collapse; }
    table.rpt th { text-align: left; border-bottom: 1px solid #000; padding: 4px 6px; }
    table.rpt td { padding: 4px 6px; }
    table.rpt tr.z td { background: #f1f1f1; }                         /* zebra, tetap terlihat saat cetak */
    table.rpt tr.tot td { font-weight: bold; border-top: 1px solid #000; background: #fff; }
    td.c { width: 70px; } td.r, th.r { text-align: right; white-space: nowrap; }
    table.rpt th.r { text-align: right; }                               /* [ALIGN] 'table.rpt th' (rata kiri) lebih spesifik dari 'th.r' -> header kolom angka ikut rata kanan */
    table.fx { table-layout: fixed; }                                   /* [ALIGN] lebar kolom tetap agar baris total/laba bersih sejajar dengan kolom di atasnya */
    .muted { color: #777; font-style: italic; }
    .grand { margin-top: 14px; padding: 8px 6px; border-top: 2px solid #000; border-bottom: 2px solid #000; font-size: 14px; font-weight: bold; display: flex; justify-content: space-between; }
    .jv { margin-top: 14px; page-break-inside: avoid; }
    .jvh { font-weight: bold; background: #e4e4e4; padding: 4px 6px; display: flex; justify-content: space-between; }
    .foot { margin-top: 16px; font-size: 10px; color: #555; }
    .noprint { margin-bottom: 12px; text-align: right; }
    .noprint button { padding: 6px 14px; cursor: pointer; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @media print { .noprint { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">Cetak / Simpan PDF</button></div>
<h1><?php echo pr_e($heading); ?></h1>
<?php foreach ($lines as $l) { echo '<div class="sub">' . $l . '</div>'; } ?>
<?php if ($status !== '') { echo '<div class="status" style="color:' . ($ok ? '#1b7a3a' : '#b00020') . ';">' . pr_e($status) . '</div>'; } ?>
<?php
}

function pr_close() {
    echo '<div class="foot">Dicetak: ' . date('d/m/Y H:i') . '</div></body></html>';
}

// Blok seksi: judul + daftar [kode, nama, nilai] + baris total. Baris bernilai ~0 sudah disaring pemanggil.
function pr_section($title, $items, $totalLabel, $total) {
    $h = '<h3>' . pr_e($title) . '</h3><table class="rpt">';
    $i = 0;
    foreach ($items as $it) {
        $h .= '<tr' . ($i++ % 2 ? ' class="z"' : '') . '><td class="c">' . pr_e($it[0]) . '</td><td>' . pr_e($it[1]) . '</td><td class="r">' . pr_n($it[2]) . '</td></tr>';
    }
    if (!count($items)) { $h .= '<tr><td colspan="3" class="muted">Tidak ada saldo.</td></tr>'; }
    $h .= '<tr class="tot"><td colspan="2">' . pr_e($totalLabel) . '</td><td class="r">' . pr_n($total) . '</td></tr></table>';
    return $h;
}