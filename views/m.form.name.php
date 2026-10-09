<?php
    /*
    Document        : Master Form Name
    Created on      : Jan 20, 2016
    Author          : Eddy Susanto
    Description     : Entry master of form name
    File related    : - m.form.name.php (CRUD)
                      - m.form.name.pdf.php (Printing)
  include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";*/

  include __DIR__ . "/../php-func/user.valid.php";
  include __DIR__ . "/../includes/many-template-icn.php";
?>
</html>
    <head>
      <link rel="stylesheet" type="text/css" href="../css/header.black.css" />
      <style>
        #editorForm {top: 50%; width: 98%; height: 85%;}
        #addForm {top: 50%; width: 90%; height: 80%;}
        #addFormHeaderMenu, #addFormHeaderSearch, #addFormHeaderText {width: 32%;}
      </style>
      <title id="page-title">Form Name</title>
    </head>
    <body>
      <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
      <script type="text/javascript" src="../js/m.form.name.js"></script>
      <script type="text/javascript">
        document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
        var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
      </script>
  </body>
</html>
