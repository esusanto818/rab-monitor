<?php
    /*
    Document        : Warehouse Issuing (Material Out ke Proyek)
    Description     : Issue material dari gudang ke proyek. Hanya dari stok yang
                      sudah di-RCV (WH Receiving), qty tidak boleh melebihi sisa
                      RCV, nilai dihitung FIFO dari harga RCV, dan dicatat untuk
                      proyek tujuan. Issue yang sudah posted dibatalkan lewat Reverse.
    File related    : - warehouse.issuing.js
                      - warehouse.issuingCRD.php (task: list_issues, list_project_options,
                        list_warehouses, stock_available, preview_fifo, load_issue,
                        save_issue, reverse_issue)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">Warehouse Issuing</title>
        <link rel="stylesheet" type="text/css" href="/proyek/ext/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/proyek/css/header.black.css" />
        <style>
            #editorForm {top: 50%; width: 90%; height: auto !important; min-height: 480px !important; max-height: 85vh !important; overflow-y: auto !important;}
            #addFormHeaderText, #editorFormHeaderText{font-size: 1.3rem;text-align: center;}
        </style>
        <link rel="stylesheet" type="text/css" href="/proyek/css/grddetail.css" />

    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="/proyek/js/warehouse.issuing.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Warehouse <em>Issuing</em>";
    </script>
</html>