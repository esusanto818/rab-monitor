<?php
  /*
  Document      : Purchase Order
  Created on    : Jun 21, 2016
  Author        : Eddy Susanto
  Description   : Entry Sales Forecast
  File related  : - purchase.order.php (CRUD)
                  - purchase.order.php (Printing)
  */
	require "../php-func/user.valid.php";
  include "../includes/many-template-icn.php";
?>
<html>
  <!DOCTYPE html>
	<head>
		<title id="page-title">Puchase Order</title>
    <link rel="stylesheet" type="text/css" href="../css/header.black.css" />
    <style>
			table {border-color: transparent;}
      #editorForm {top: 50%; width: 98%; height: 85%;}
      #addForm {top: 50%; width: 75%; height: 70%;}
      #addFormHeaderMenu, #addFormHeaderSearch, #addFormHeaderText {width: 32%;}
      #gridHeaderText {font-family: sans-serif; font-size: 18px; color: #000; content: ""; display: block; width: 5%; padding-top: 5px;border-bottom: 3px solid #ffa234;}
      #editorFormGrid .x-toolbar .x-toolbar-cell {width: 300px;text-align: left;}

      .x-grid3-gridEditor .x-panel-tbar .x-toolbar {background-color: transparent;}
      .x-grid3-gridEditor .x-toolbar div.xtb-text {background-color: transparent;height: 36px;}
      .x-grid3-gridEditor .x-grid3-hd-row td, .x-grid3-gridEditor .x-grid3-header-offset, .x-grid3-gridEditor .x-grid3-header, .x-grid3-gridEditor .x-grid3-header-inner {height: 45px;}

      .x-grid3-hd-row-user-3f474c .x-panel-tbar .x-toolbar {background-color: transparent;}
      .x-grid3-hd-row-user-3f474c .x-toolbar div.xtb-text {background-color: transparent; height: 36px;}
      .x-grid3-hd-row-user-3f474c .x-grid3-hd-row td, .x-grid3-hd-row-user-3f474c .x-grid3-header-offset, .x-grid3-hd-row-user-3f474c .x-grid3-header, .x-grid3-hd-row-user-3f474c .x-grid3-header-inner {height: 45px;}
    </style>
	</head>
	<body>
	</body>
  <script type="text/javascript" src="../js-ext/ux/CheckColumn.js"></script>
  <script type="text/javascript" src="../js/purchase.order.js"></script>
  <script type="text/javascript">
    document.getElementById('menu').innerHTML='<?php echo $_SESSION["linkMenu"] ?>';
    var formAccess = '<?php echo $_SESSION["FormAccess"] ?>';
  </script>
</html>
