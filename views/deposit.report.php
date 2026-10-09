<?php
    /*
    Document        : Deposit Monitoring
    Description     : Laporan saldo & mutasi Customer Deposit / Supplier Deposit (read-only)
    File related    : - deposit_reportCRD.php (report handler)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">Deposit Monitoring</title>
        <link rel="stylesheet" type="text/css" href="/proyek/ext/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/proyek/css/header.black.css" />
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="/proyek/js/deposit.report.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Deposit <em>Monitoring</em>";
        if (document.getElementById('editorWdw')) {
            document.getElementById('editorWdw').style.display = 'none';
        }
    </script>
</html>