<?php
    /*
    Document        : Cetak Tanda Terima Pembayaran Termin
    Description     : Halaman cetak (popup) tanda terima. Hanya kerangka + CSS cetak;
                      data diambil project.payment.print.js dari project.paymentCRD.php (task=load_payment).
    Pemakaian       : project.payment.print.php?id=<project_payments.id>
    */
    include "../php-func/user.valid.php";
    $paymentId = intval($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8" />
        <title>Tanda Terima Pembayaran</title>
        <style>
            @page { size: A4; margin: 15mm; }
            * { box-sizing: border-box; }
            body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #000; background: #eee; margin: 0; }
            .toolbar { padding: 10px; text-align: center; background: #333; }
            .toolbar button { padding: 8px 20px; font-size: 13px; cursor: pointer; margin: 0 4px; }
            .sheet { width: 190mm; min-height: 120mm; margin: 12px auto; padding: 12mm; background: #fff; border: 1px solid #999; }
            .hdr { display: table; width: 100%; border-bottom: 2px solid #000; padding-bottom: 8px; }
            .hdr .l, .hdr .r { display: table-cell; vertical-align: top; }
            .hdr .r { text-align: right; }
            .co-name { font-size: 16px; font-weight: bold; }
            .co-addr { font-size: 11px; color: #333; margin-top: 2px; }
            .title { font-size: 18px; font-weight: bold; letter-spacing: 1px; }
            .meta td { padding: 2px 0; vertical-align: top; }
            .meta td.k { width: 140px; }
            .meta td.s { width: 12px; }
            .amount-box { border: 2px solid #000; padding: 8px 12px; margin: 10px 0; font-size: 15px; font-weight: bold; }
            .terbilang { font-style: italic; margin: 4px 0 12px 0; }
            table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
            table.items th, table.items td { border: 1px solid #000; padding: 4px 6px; }
            table.items th { background: #f0f0f0; text-align: left; }
            .r-al { text-align: right; }
            .sign { display: table; width: 100%; margin-top: 28px; text-align: center; }
            .sign div { display: table-cell; width: 50%; }
            .sign .line { margin-top: 60px; border-top: 1px solid #000; display: inline-block; min-width: 160px; padding-top: 3px; }
            .err { padding: 30px; text-align: center; color: #c0392b; font-size: 14px; }
            @media print {
                body { background: #fff; }
                .toolbar { display: none; }
                .sheet { border: none; margin: 0; width: auto; padding: 0; }
            }
        </style>
    </head>
    <body>
        <div class="toolbar">
            <button type="button" onclick="window.print();">Cetak</button>
            <button type="button" onclick="window.close();">Tutup</button>
        </div>
        <div id="receipt" class="sheet"><div class="err" style="color:#666;">Memuat tanda terima...</div></div>
        <script type="text/javascript">
            window.PAYMENT_ID = <?php echo json_encode($paymentId); ?>;
        </script>
        <script type="text/javascript" src="/proyek/js/project.payment.print.js"></script>
    </body>
</html>