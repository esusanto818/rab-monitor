<?php
    /*
    Document        : Retur Pembelian (Purchase Return)
    Description     : Form retur barang ke supplier, link ke GRN, otomatis catat stok keluar (CRUD Lengkap).
    File related    : - purchase_return.js
                      - purchase_returnCRD.php (CRUD)
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
        <style>
            #editorForm {top: 50%; width: 90%; height: 75%;}
            #addForm {top: 50%; width: 85%; height: 70%;}
        </style>
        <title id="page-title">Retur Pembelian</title>
        <link rel="stylesheet" type="text/css" href="/proyek/css/header.black.css" />
        <script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
        <link rel="stylesheet" type="text/css" href="/proyek/css/grddetail.css" />
    </head>
    <body>
    </body>
    <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
    <script type="text/javascript" src="/proyek/js/purchase.return.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Retur <em>Pembelian</em>";
    </script>
</html>
