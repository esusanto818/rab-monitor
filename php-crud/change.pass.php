<?php
session_start();
include_once "../php-class/crudClass.php";

$searchField=isset($_POST["query"]) ? $_POST["query"] : NULL;
$offset=($_POST["start"]==null)? 0 : $_POST["start"]; $limit=($_POST["limit"]==null)? 250: $_POST["limit"];

$dataArray=json2Array($_POST["iteminfo"]);
$CheckItemNum = (empty($dataArray)) ?"[0]":"[".$dataArray."]";;
$CheckItemChar = (empty($dataArray)) ?"['0']":"[".$dataArray."]";

switch ($_POST['task']){
	case ("read_edit"):
		$db = new crud();
		$field  = "
			s.id,s.login_name,s.employee_id,s.active,s.password,
			CONVERT_FROM(DECODE(s.password, 'BASE64'), 'UTF-8') as password,
			CONVERT_FROM(DECODE(s.password, 'BASE64'), 'UTF-8') as confirm_password,
			e.employee_name, e.gender, e.from_date, d.department_name, d.status_name
		";
		$table = "
			m_user_system s
			left join m_employee e on e.id=s.employee_id
			left join view_employee_depart_status d on coalesce(d.id,0)=coalesce(e.id,0)
		";
		$where = "s.id=".$_POST['id'];
		$db->select('data',$table,$field,$where);
		$result = $db->getResult();
		echo $result;
	break;
	case ('update'):
		$db = new crud();
		$tabel = "m_user_system";
		$data = array(
			"password"=>EncodeData($_POST["password"])
		);
		$where = "employee_id=".$_POST['employee_id'];
		$db->update($tabel, $data, $where);
		$result = $db->getResult();
		echo $result;
	break;
}
?>
