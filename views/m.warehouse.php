<!--
	Document   		: Master warehouse
    Created on 		: Feb 17, 2016
    Author     		: Eddy Susanto
    Description		: Entry master of warehouse
    File related 	: - m.warehouse.php (CRUD)
					          - m.warehouse.pdf.php (Printing)
-->
<?php
  include "../php-func/user.valid.php";
  include('../includes/many-template-icn.php');
?>
<html>
    <head>
      <style>#editorForm {top: 40%; width: 85%;}</style>
      <title id="page-title">Warehouse</title>
      <link rel="stylesheet" type="text/css" href="../css/header.black.css" />
    </head>
    <body>
    <script type="text/javascript" src="../js/m.warehouse.js"></script>
    <script type="text/javascript">
      document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
      var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    </script>
  </body>
</html>






