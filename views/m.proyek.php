<?php
    /*
    Document        : Master Material
    Description     : Entry data master material (kode, nama, satuan,
                      estimasi harga) yang jadi sumber combo Material
                      di modul Master Proyek (RAB) & transaksi WMS.
    File related    : - m.materials.js
                      - m.materialsCRD.php (task: list, read_edit,
                        create, update, delete)
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <style>
            #editorForm {top: 30%; width: 90%; }
        </style>
        <title id="page-title">PROYEK</title>
         <link rel="stylesheet" type="text/css" href="/rab-project/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/frm.css" />
    </head>
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="/rab-project/js/m.proyek.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Master <em>Material</em>";
    </script>
</html>