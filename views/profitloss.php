<?php
    /*
    Document        : Income Statement / Profit Loss
    Description     : Laporan Rugi Laba per periode (read-only)
    File related    : - financial_profit_lossCRD.php (report handler)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">[PL] Profit & Loss</title>
        <link rel="stylesheet" type="text/css" href="/proyek/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/proyek/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/proyek/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/proyek/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/proyek/css/frm.css" />
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="/proyek/js/profitloss.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Profit & <em>Loss</em>";
        if (document.getElementById('editorWdw')) {
            document.getElementById('editorWdw').style.display = 'none';
        }
    </script>
</html>