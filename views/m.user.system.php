<?php
  /*
    Document     : Master User
    Created on   : Feb 12, 2016
    Author       : Eddy Susanto
    Description  : Entry User
    File related : - m.user.system.php (CRUD)
                   - m.user.system.php (Printing)
	include "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";*/
	include __DIR__ . "/../php-func/user.valid.php";
	include __DIR__ . "/../includes/many-template-icn.php";
	?>
	<!DOCTYPE html>
	<html>
	  <head>
    <link rel="stylesheet" type="text/css" href="../css/header.black.css" />
		<style>
			#editorForm {top: 50%; width: 95%; height: 90%;}
			#addForm {top: 50%; width: 90%; height: 80%;}
		</style>
		<title id="page-title">User List</title>
	</head>
	<body>
  <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
  <script type="text/javascript" src="../js/m.user.system.js"></script>
  <script type="text/javascript">
    document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
    var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
    var UserCurrId = '<?php echo $_SESSION["user_id"] ?>';
  </script>
	</body>
</html>
