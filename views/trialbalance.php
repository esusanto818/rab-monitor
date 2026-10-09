<?php
    /*
    Document        : Trial Balance
    Description     : Rekap saldo semua akun per periode (read-only)
    File related    : - m.trialbalanceCRD.php (report handler)
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
        <title id="page-title">[TB] Trial Balance</title>
        <link rel="stylesheet" type="text/css" href="/proyek/css/header.black.css" />
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="/proyek/js/trialbalance.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Trial <em>Balance</em>";
        document.getElementById('editorWdw').style.display = 'none';

    </script>
</html>