<?php
    /*
    Document        : Cetak Tanda Terima Pembayaran Termin & Lampiran Analisis AI
    Description     : Halaman cetak (popup) tanda terima pembayaran. 
                      - Lembar 1: Tanda Terima & Rincian Termin.
                      - Lembar 2 dst: Lampiran Analisis Varian AI per Proyek (tiap proyek di halaman baru).
    Pemakaian       : project.payment.print.php?id=<project_payments.id>
    */
    include "../php-func/user.valid.php";
    $paymentId = intval($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8" />
        <title>Tanda Terima Pembayaran &amp; Analisis AI</title>
        <style>
            @page { size: A4; margin: 15mm; }
            * { box-sizing: border-box; }
            body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #000; background: #eee; margin: 0; }
            
            .toolbar { padding: 10px; text-align: center; background: #333; position: sticky; top: 0; z-index: 1000; }
            .toolbar button { padding: 8px 20px; font-size: 13px; cursor: pointer; margin: 0 4px; font-weight: bold; background: #00bcd4; border: none; border-radius: 4px; color: #000; }
            .toolbar button:hover { background: #26c6da; }
            
            .sheet { width: 190mm; min-height: 270mm; margin: 12px auto; padding: 12mm; background: #fff; border: 1px solid #999; position: relative; }
            
            .hdr { display: table; width: 100%; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 15px; }
            .hdr .l, .hdr .r { display: table-cell; vertical-align: top; }
            .hdr .r { text-align: right; }
            .co-name { font-size: 16px; font-weight: bold; color: #111; }
            .co-addr { font-size: 11px; color: #555; margin-top: 2px; }
            
            .title { font-size: 18px; font-weight: bold; letter-spacing: 1px; text-align: center; margin: 15px 0; }
            
            .meta { width: 100%; margin-bottom: 15px; font-size: 12px; }
            .meta td { padding: 4px 0; vertical-align: top; }
            .meta td.k { width: 140px; font-weight: bold; color: #333; }
            
            table.items { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 20px; font-size: 12px; }
            table.items th, table.items td { border: 1px solid #000; padding: 6px 8px; }
            table.items th { background: #f0f0f0; text-align: left; }
            .r-al { text-align: right; }
            
            .sign { display: table; width: 100%; margin-top: 40px; text-align: center; }
            .sign div { display: table-cell; width: 50%; }
            .sign .line { margin-top: 65px; border-top: 1px solid #000; display: inline-block; min-width: 180px; padding-top: 3px; font-weight: bold; }
            
            .muted { color: #64717d; font-size: 10px; margin-top: 10px; }
            .footer { position: absolute; bottom: 12mm; left: 12mm; right: 12mm; border-top: 1px solid #d8dee4; padding-top: 8px; color: #7a858e; text-align: center; font-size: 10px; }
            .err { padding: 30px; text-align: center; color: #c0392b; font-size: 14px; }
            
            @media print {
                body { background: #fff; }
                .toolbar { display: none; }
                .sheet { border: none; margin: 0; width: auto; padding: 0; box-shadow: none; min-height: auto; page-break-after: always; }
                .sheet:last-child { page-break-after: avoid; }
            }
        </style>
    </head>
    <body>
        <div class="toolbar">
            <button type="button" onclick="window.print();">&#128438; Cetak Dokumen</button>
            <button type="button" onclick="window.close();" style="background:#555; color:#fff;">Tutup</button>
        </div>
        
        <div id="receipt">
            <div class="err" style="color:#666;">Memuat tanda terima pembayaran &amp; analisis AI...</div>
        </div>

        <script type="text/javascript">
            window.PAYMENT_ID = <?php echo json_encode($paymentId); ?>;
        </script>
        <script type="text/javascript" src="/rab-project/js/project.payment.print.js"></script>
    </body>
</html>