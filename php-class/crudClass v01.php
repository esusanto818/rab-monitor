<?php
if (!isset($_SESSION)) { session_start(); }
date_default_timezone_set('Asia/Jakarta');

// Mengaktifkan pelaporan error agar mudah melacak kutu selama pengembangan
error_reporting(E_ALL & ~E_NOTICE);
$wwwdoc=$_SERVER["DOCUMENT_ROOT"];

// Memastikan berkas fungsi global termuat
//echo $_SERVER["DOCUMENT_ROOT"];
//include_once $_SERVER["DOCUMENT_ROOT"]."/kmgi/php-func/function.all.php";
//$wwwdoc=$_SERVER["DOCUMENT_ROOT"]."/kmgi/";

include_once $wwwdoc."/php-func/function.all.php";
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
      $this->host     = "127.0.0.1";
      $this->database = "kmgi";
      $this->user     = "postgres";
      $this->pass     = "merdeka";

      // Sinkronisasi port ke 5432 agar sama dengan function.all.php
      $dns = $this->engine.':dbname='.$this->database.";port=5432;host=".$this->host;

      try {
          parent::__construct($dns, $this->user, $this->pass);
          parent::setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
          parent::setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
      } catch (PDOException $e) {
          die(json_encode([
              "success" => "false",
              "status" => "Error",
              "message" => "Koneksi database via PDO gagal: " . $e->getMessage()
          ]));
      }
    }

    /**
     * Helper internal untuk menset session username pada level PostgreSQL Core.
     * Mencegah crash jika session kosong.
     */
    private function setAuditUser() {
      $username = (isset($_SESSION["username"]) && $_SESSION["username"] != '') ? $_SESSION["username"] : 'system';
      $sqlSetUsername = "SET app.username to " . parent::quote($username);
      $setUser = parent::prepare($sqlSetUsername);
      $setUser->execute();
    }

    /*
    * FUNGSI ASLI FRAMEWORK: Insert values into the table
    */
    public function insert($table, $rows = null) {
      $this->setAuditUser();

      $insert = 'INSERT INTO '.$table;
      if($rows != null) {
        $insert .= ' ('.implode(',',array_keys($rows)).')';
        for($i = 0; $i < count($rows); $i++) {
          if(is_string($rows[array_keys($rows)[$i]]))
            $rows[array_keys($rows)[$i]] =	"'".$rows[array_keys($rows)[$i]]."'";
        }
        $insert .= ' VALUES ('.implode(',',array_values($rows)).')';
      }
      $query = parent::prepare($insert);
      $query->execute();
    }

    /*
    * FUNGSI ASLI FRAMEWORK: Delete table or rows
    */
    public function delete($table, $where = null) {
      $this->setAuditUser();

      $delete = 'DELETE FROM '.$table;
      if($where != null) {
        $delete .= ' WHERE '.$where;
      }
      $query = parent::prepare($delete);
      $query->execute();
    }

    /*
    * FUNGSI ASLI FRAMEWORK: Update values inside the table
    */
    public function update($table, $rows, $where) {
      $this->setAuditUser();

      $update = 'UPDATE '.$table.' SET ';
      $keys = array_keys($rows);
      for($i = 0; $i < count($rows); $i++) {
        if(is_string($rows[$keys[$i]])) {
          $update .= $keys[$i].'=\''.$rows[$keys[$i]].'\'';
        } else {
          $update .= $keys[$i].'='.$rows[$keys[$i]];
        }
        if($i != count($rows)-1) {
          $update .= ',';
        }
      }
      $update .= ' WHERE '.$where;
      $query = parent::prepare($update);
      $query->execute();
    }

    /*
    * FUNGSI ASLI FRAMEWORK: Mengakomodasi fleksibilitas urutan parameter select ExtJS lama maupun baru
    */
    /*
    * PERBAIKAN FATAL: Mengakomodasi fleksibilitas urutan parameter select ExtJS bawaan sistem Anda
    */
    public function select($arg1, $arg2 = '*', $arg3 = null, $arg4 = null, $arg5 = 'data', $arg6 = 0, $arg7 = 250) {

      // Deteksi otomatis jika parameter pertama diisi 'data' atau 'history' (Pola m.item.grade.php / m.employee.status.php)
      if ($arg1 === 'data' || $arg1 === 'history') {
          $jsonName = $arg1;
          $table    = $arg2;
          $rows     = $arg3;
          $where    = $arg4;
          $order    = $arg5 !== 'data' ? $arg5 : null;
          // Pada kode asli m.item.grade.php: arg6 adalah limit, arg7 adalah offset
          $limit    = $arg6;
          $offset   = $arg7;
      } else {
          // Pola penulisan standar framework
          $table    = $arg1;
          $rows     = $arg2;
          $where    = $arg3;
          $order    = $arg4;
          $jsonName = $arg5;
          $offset   = $arg6;
          $limit    = $arg7;
      }

      // Pastikan limit dan offset bertipe integer murni
      $limit  = ($limit === null || $limit === '') ? 250 : intval($limit);
      $offset = ($offset === null || $offset === '') ? 0 : intval($offset);

      $q = 'SELECT '.$rows.' FROM '.$table;
      if($where != null && trim($where) != '') $q .= ' WHERE '.$where;
      if($order != null && trim($order) != '') $q .= ' ORDER BY '.$order;
      $q .= ' LIMIT '.$limit.' OFFSET '.$offset;

      $query = parent::prepare($q);
      $query->execute();
      $response = $query->fetchAll(PDO::FETCH_ASSOC);

      // Hitung total data keseluruhan tanpa limit untuk penomoran halaman ExtJS paging toolbar
      $commandAll = 'SELECT '.$rows.' FROM '.$table;
      if($where != null && trim($where) != '') $commandAll .= ' WHERE '.$where;

      $queryAll = parent::prepare($commandAll);
      $queryAll->execute();
      $totalRows = $queryAll->rowCount();

      // Output string JSON murni yang sesuai dengan konfigurasi JsonReader ExtJS
      $this->result = "{'totalRecord':'".$totalRows."','".$jsonName."':".json_encode($response)."}";
    }
    /*public function select($arg1, $arg2 = '*', $arg3 = null, $arg4 = null, $arg5 = 'data', $arg6 = 0, $arg7 = 250) {
      // Deteksi jika parameter pertama diisi 'data' atau 'history' (Pola Penulisan Terbalik bawaan m.employee.status.php)
      if ($arg1 === 'data' || $arg1 === 'history') {
          $jsonName = $arg1;
          $table    = $arg2;
          $rows     = $arg3;
          $where    = $arg4;
          $order    = $arg5 !== 'data' ? $arg5 : null;
          $limit    = $arg7;
          $offset   = $arg6;
      } else {
          // Pola penulisan standard standard
          $table    = $arg1;
          $rows     = $arg2;
          $where    = $arg3;
          $order    = $arg4;
          $jsonName = $arg5;
          $offset   = $arg6;
          $limit    = $arg7;
      }

      $q = 'SELECT '.$rows.' FROM '.$table;
      if($where != null && $where != '') $q .= ' WHERE '.$where;
      if($order != null && $order != '') $q .= ' ORDER BY '.$order;
      if($limit != null) $q .= ' LIMIT '.$limit;
      if($offset != null) $q .= ' OFFSET '.$offset;

      $query = parent::prepare($q);
      $query->execute();
      $response = $query->fetchAll(PDO::FETCH_ASSOC);

      $commandAll = 'SELECT '.$rows.' FROM '.$table;
      if($where != null && $where != '') $commandAll .= ' WHERE '.$where;
      $queryAll = parent::prepare($commandAll);
      $queryAll->execute();
      $rowsAll = $queryAll->fetchAll(PDO::FETCH_ASSOC);

      $this->result = "{'totalRecord':'".count($rowsAll)."','".$jsonName."':".json_encode($response)."}";
    }*/

    /*
    * FUNGSI ASLI FRAMEWORK: Menjalankan Raw Multi-Query teks lama
    */
    public function cmdQ($command) {
      try {
        $this->setAuditUser();

        parent::beginTransaction();
        $query = parent::prepare($command);
        $query->execute();

        $rowcount = $query->rowCount();
        parent::commit();

        $result["success"] = "true";
        $result["status"] = "Success";
        $result["message"] = $rowcount." row(s) updated in database...";
        $this->result = json_encode($result);
        return true;
      } catch (PDOException $e) {
        if (parent::inTransaction()) {
            parent::rollback();
        }
        $result["success"] = "false";
        $result["status"] = "Error";
        $result["message"] = "Gagal memproses data: " . $e->getMessage();
        $this->result = json_encode($result);
        return false;
      }
    }

    /**
     * FITUR TAMBAHAN BARU: Digunakan khusus untuk transaksi aman berbasis parameter terikat (Anti SQL Injection)
     */
    public function safeTransaction($queriesAndParams) {
      try {
        $this->setAuditUser();

        parent::beginTransaction();

        $totalUpdated = 0;
        foreach ($queriesAndParams as $item) {
            $stmt = parent::prepare($item['query']);
            $stmt->execute($item['params']);
            $totalUpdated += $stmt->rowCount();
        }

        parent::commit();

        $result["success"] = "true";
        $result["status"] = "Success";
        $result["message"] = "Data berhasil disimpan.";
        $this->result = json_encode($result);
        return true;
      } catch (PDOException $e) {
        if (parent::inTransaction()) {
            parent::rollback();
        }
        $result["success"] = "false";
        $result["status"] = "Error";
        $result["message"] = "Transaksi Gagal: " . $e->getMessage();
        $this->result = json_encode($result);
        return false;
      }
    }

    public function getResult() {
      return $this->result;
    }
  }
}
?>
