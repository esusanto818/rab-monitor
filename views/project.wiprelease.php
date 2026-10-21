<?php
    /*
    Document        : Pelepasan WIP
    Description     : Lepas WIP proyek ke HPP per progress / proyek selesai.
    File related    : - project.wiprelease.js
                      - project.wipreleaseCRD.php
    */
    include "../php-func/user.valid.php";
    include "../includes/many-template-icn.php";
?>
<html>
    <head>
        <title id="page-title">WIP Release</title>
        <link rel="stylesheet" type="text/css" href="/rab-project/ext-3.4.1/resources/css/ext-all.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/mainCSS.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/header.black.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/grddetail.css" />
        <link rel="stylesheet" type="text/css" href="/rab-project/css/frm.css" />
        <style>
            #editorForm { top: 50%; width: 70%; height: 55%; }
            #addForm { top: 50%; width: 70%; height: 55%; }
        </style>
    </head
    <body>
    </body>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
    </script>
    <script type="text/javascript" src="/rab-project/js/project.wiprelease.js"></script>
    <script type="text/javascript">
        if (document.getElementById('menu')) {
            document.getElementById('menu').innerHTML = <?php echo json_encode($_SESSION["linkMenu"] ?? ''); ?>;
        }
        if (document.getElementById('mainHeaderText')) {
            document.getElementById("mainHeaderText").innerHTML = "Pelepasan <em>WIP</em>";
        }
    </script>
</html>