<?php
if (!isset($_SESSION)) {session_start();}   //to restrict the access
include "../php-class/crudClass.php";

$sql = "update m_user_login_status set login_status=NULL,login_terminal=NULL where id=".$_SESSION["user_id"];
$output = pg_query($sql)or die('connection failed'.pg_last_error());

session_unset();
session_destroy();
if(!isset($_SESSION['user_id']) || is_null($_SESSION['user_id']))
    {
      header("Location: ../index.php");
      exit;
    }
?>
