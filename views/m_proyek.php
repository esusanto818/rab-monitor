<?php
    /*
    Document        : Master Proyek Entry
    Description     : Pengelolaan Data Proyek (terhubung ke tabel projects, clients, & pics)
    File related    : - m.master_proyek.js
                      - master_proyekCRD.php
    */
    include "../php-func/user.valid.php";
    include('../includes/many-template-icn.php');
?>
<html>
    <head>
        <style>
            #editorForm {top: 30%; width: 80%; }
        </style>
        <title id="page-title">Proyek</title>
        <link rel="stylesheet" type="text/css" href="/proyek/css/header.black.css" />
        <script type="text/javascript">
            window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        </script>
    </head>
    <body>
        <div id="divMainGrid"></div>
        <div id="editorWdw" style="display:none;">
        <div id="editorFormHeaderText"></div>
        <div id="editorFormMain"></div>
        </div>
    </body>
    <script type="text/javascript" src="/proyek/js/m_proyek.js"></script>
    <script type="text/javascript">
        document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        document.getElementById("mainHeaderText").innerHTML = "Master Data <em>Proyek & Kontrak</em>";
    </script>
</html>