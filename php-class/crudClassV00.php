<?php
if (!isset($_SESSION)) { session_start(); }
date_default_timezone_set('Asia/Jakarta');
error_reporting(0);
include_once $_SERVER["DOCUMENT_ROOT"]."/kmgi/php-func/function.all.php";

if (!class_exists('crud')) {

  class crud extends PDO {
    private $engine;
    private $host;
    private $database;
    private $user;
    private $pass;

    private $result;

    public function __construct() {
      $this->engine   = 'pgsql';
      //$this->host = getenv('DB_HOST'); $this->user = getenv('DB_USER'); $this->pass = getenv('DB_PASS');
      //$this->database = getenv('ERP_DB_DATABASE');
      //$this->database = getenv('KMGI_DB_DATABASE');
      $this->host = "127.0.0.1"; $this->database ="kmgi"; $this->user = "postgres"; $this->pass = "merdeka";
      $dns = $this->engine.':dbname='.$this->database.";port=5433;host=".$this->host;
      parent::__construct( $dns, $this->user, $this->pass );
      parent::setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    }

    /*
    * Insert values into the table
    */
    public function insert($table,$rows=null) {
      $sqlSetUsername = "SET app.username to '".$_SESSION["username"]."'";
      $setUser = parent::prepare($sqlSetUsername);
      $setUser->execute();

      $command = 'INSERT INTO '.$table;
      $row = null; $value=null;
      foreach ($rows as $key => $values) {
        $row  .=",".$key;
        $value.=", :".$key;
      }

      $command .="(".substr($row,1).")";
      $command .="VALUES(".substr($value,1).") RETURNING id";

      $query =  parent::prepare($command);
      $query->execute($rows);
      $lastId = $query->fetch(PDO::FETCH_ASSOC);

      $rowcount = $query->rowCount();
      if($rowcount<=0){
        $errors = $query->errorInfo();
        $result["success"] = "false";
        $result["status"] = "Error";
        $result["message"] = $errors[2];
        $result["lastId"] = null;
      }else{
        $result["success"] = "true";
        $result["status"] = "Success";
        $result["message"] = $rowcount." row(s) inserted in database";
        $result["lastId"] = $lastId['id'];
      }
      $this->result = json_encode($result)."";
    }

    /*
    * Delete records from the database.
    */
    public function delete($table,$where) {
      $sqlSetUsername = "SET app.username to '".$_SESSION["username"]."'";
      $setUser = parent::prepare($sqlSetUsername);
      $setUser->execute();

      $command = 'DELETE FROM '.$table.' WHERE '.$where;
      $query = parent::prepare($command);
      $query->execute();

      $rowcount = $query->rowCount();
      if($rowcount<=0){
        $errors = $query->errorInfo();
        $result["success"] = "false";
        $result["status"] = "Error";
        $result["message"] = $errors[2];
      }else{
        $result["success"] = "true";
        $result["status"] = "Success";
        $result["message"] = $rowcount." row(s) deleted in database";
      }
      $this->result = json_encode($result);
    }

    /*
    * Update Record
    */
    public function update($table, $data = null ,$where = null) {
        $a=array();
        $result=array();
        $sqlSetUsername = "SET app.username to '".$_SESSION["username"]."'";
        $setUser = parent::prepare($sqlSetUsername);
        $setUser->execute();

        $update = 'UPDATE '.$table.' SET ';
        $set=null; $value=null;
        foreach($data as $key => $values) {
          $set .= ','.$key. ' = :'.$key;
          $a[":".$key] = $values;
        };
        $update .= substr(trim($set),1);
        if($where != null) {$update .= ' WHERE '.$where;}
        $query = parent::prepare($update);
        $query->execute($a);

        $rowcount = $query->rowCount();
        if($rowcount<=0){
          $errors = $query->errorInfo();
          $result["success"] = "false";
          $result["status"] = "Error";
          $result["message"] = $errors[2];
        }else{
          $result["success"] = "true";
          $result["status"] = "Success";
          $result["message"] = $rowcount." row(s) updated in database";
        }
      $this->result = json_encode($result);
    }

    /*
    * Selects information from the database.
    */
    public function select($jsonName, $table, $fields, $where = null, $order = null, $limit= null, $offset= null, $group = null) {
      $command = 'SELECT '.$fields.' FROM '.$table;
      if($where != null)
        $command .= ' WHERE '.$where;
      if($group != null)
        $command .= ' GROUP BY '.$group;
      if($order != null)
        $command .= ' ORDER BY '.$order;
      if($limit != null)
        $command .= ' LIMIT '.$limit. ' OFFSET '.$offset;

      $query = parent::prepare($command);
      $query->execute();
      $rows = $query->fetchAll(PDO::FETCH_ASSOC);
      $response = $rows;

      $commandAll = 'SELECT '.$fields.' FROM '.$table;
      if($where != null) $commandAll .= ' WHERE '.$where;
      $queryAll = parent::prepare($commandAll);
      $queryAll->execute();
      $rowsAll = $queryAll->fetchAll(PDO::FETCH_ASSOC);

      $rowcount = $query->rowCount();
      if($rowcount<=0 && !$query->errorInfo()){
          $errors = $query->errorInfo();
          $result["success"] = "false";
          $result["status"] = "Error";
          $result["message"] = $errors[2];
          $this->result = json_encode($result);
      } else {
        $this->result = "{'totalRecord':'".count($rowsAll)."','".$jsonName."':".json_encode($response)."}";
      }

    }

    public function cmdQ($command) {
      $sqlSetUsername = "SET app.username to '".$_SESSION["username"]."'";
      $setUser = parent::prepare($sqlSetUsername);
      $setUser->execute();

      parent::beginTransaction();
      $query = parent::prepare($command);
      $query->execute();

      $rowcount = $query->rowCount();
      if($rowcount <= 0){
        parent::rollback();
        $errors = $query->errorInfo();
        $result["success"] = "false";
        $result["status"] = "Error";
        $result["message"] = $errors[2];
      } else {
        parent::commit();
        $result["success"] = "true";
        $result["status"] = "Success";
        $result["message"] = $rowcount." row(s) updated in database";
      }
      $this->result = json_encode($result);
    }

    public function getResult() { return $this->result; }
  }

} // End of class_exists check
?>
