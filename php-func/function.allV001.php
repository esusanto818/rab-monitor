<?php
error_reporting(E_ALL & ~E_NOTICE);
if (!isset($_SESSION)) { session_start(); }

$dbHost = "127.0.0.1";
$dbUser = "postgres";
$dbPass = "merdeka";
$dbDatabase = "univ";

// Inisialisasi koneksi DB
$dbconn = pg_connect("host=$dbHost port=5432 dbname=$dbDatabase user=$dbUser password=$dbPass")
    or die('Could not connect: ' . pg_last_error());

// Alias $conn dari $dbconn agar kompatibel di seluruh sistem
$conn = $dbconn;

// Set application username
//$sqlSetUsername = "SET app.username='" . pg_escape_string($conn, $_SESSION["username"] ?? '') . "'";
//$outputSetUsername = pg_query($conn, $sqlSetUsername) or die('Connection failed: ' . pg_last_error($conn));

$days = array('Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday');

function searchData($findThis, $findInFields, $limit = null) {
  if (isset($findThis) && $findThis <> '' && $findThis <> null) {
    $limit = 99999;
    $searchDtl = explode("|", $findThis);
    $searchField = "";
    for ($c = 0; $c < count($searchDtl); $c++) {
      $inFileds = NULL;
      $searchField .= " and (";
      for ($x = 0; $x < count($findInFields); $x++) {
        if ($x > 0) { $inFileds .= " or "; }
        $inFileds .= " lower(" . $findInFields[$x] . ") ~~ '%" . strtolower($searchDtl[$c]) . "%' ";
      }
      $searchField .= $inFileds . ")";
    }
  } else {
    $limit = ($limit == null) ? 250 : $limit;
    $searchField = NULL;
  }
  return array("limit" => $limit, "searchSQL" => $searchField);
}

function srcDataGrid($fields, $find) {
  $rSreach = "( "; $r = 0;
  $findData = explode("%", $find);
  foreach ($findData as $s) {
    foreach ($fields as $value) {
      if ($r > 0) { $rSreach .= ' or '; }
      $rSreach .= "lower(" . $value . ") like '%" . strtolower($s) . "%'";
      $r++;
    }
  }
  $rSreach .= " )";
  return $rSreach;
}

function insertQ($table, $rows) {
    global $conn;

    $command = "INSERT INTO " . $table;
    $row = "";
    $value = "";

    foreach ($rows as $key => $values) {
        $row .= "," . $key;
        $valStr = $values ?? '';

        if (!empty($values) && is_string($valStr) && substr($valStr, 0, 8) === "currval(") {
            $value .= "," . $valStr;
        }
        else if ($values === '0' || $values === 0) {
            $value .= ",'" . $values . "'";
        }
        else if (!empty($values)) {
            $escapedVal = pg_escape_string($conn, (string)$valStr);
            $value .= ",'" . $escapedVal . "'";
        }
        else {
            $value .= ",NULL";
        }
    }

    $command .= "(" . substr($row, 1) . ")";
    $command .= " VALUES (" . substr($value, 1) . ") RETURNING id";

    return $command;
}

function updateQ($table, $data, $where = null) {
    global $conn;

    $command = "UPDATE " . $table . " SET ";
    $set = "";

    foreach ($data as $key => $values) {
        $valStr = $values ?? '';

        if ($values === '0' || $values === 0) {
            $set .= "," . $key . "='" . $values . "'";
        }
        else if (!empty($values)) {
            $escapedVal = pg_escape_string($conn, (string)$valStr);
            $set .= "," . $key . "='" . $escapedVal . "'";
        }
        else {
            $set .= "," . $key . "=NULL";
        }
    }

    $command .= substr(trim($set), 1);

    if ($where != null) {
        $command .= " WHERE " . $where;
    }

    return $command;
}
function CreateRefNo($tableName, $FieldCount, $RefInit) {
  global $conn;
  $sql = "
    (
    select CASE (count(".$FieldCount.")) WHEN 0
      THEN '".$RefInit."-".date('ym')."-00001'
      ELSE '".$RefInit."-".date('ym')."-'
        ||(select (substring('00000'||cast(CAST(max(substring(".$FieldCount." from '.....$'))AS int)+1 as varchar) from '.....$'))
        from ".$tableName."
        where substring(".$FieldCount.",length(".$FieldCount.")-9,2)='".date('y')."' and substring(".$FieldCount.",length(".$FieldCount.")-7,2)='".date('m')."')
      END  AS ref_no
    from ".$tableName." where substring(".$FieldCount.",length(".$FieldCount.")-9,2)='".date('y')."' and substring(".$FieldCount.",length(".$FieldCount.")-7,2)='".date('m')."'
    )
  ";
  $output = pg_query($conn, $sql) or die('Connection failed: ' . pg_last_error($conn));
  $AddRefNo = '';
  if (pg_num_rows($output) > 0) {
    while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
      $AddRefNo = $objid["ref_no"];
    }
  }
  return $AddRefNo;
}

function CreateWHRefNo($tableName, $FieldCount, $RefInit) {
  global $conn;
  $sql = "
    (
    select CASE (count(".$FieldCount.")) WHEN 0
      THEN '".$RefInit."-".date('ym')."-00001'
      ELSE '".$RefInit."-".date('ym')."-'
        ||(select (substring('00000'||cast(CAST(max(substring(".$FieldCount." from '.....$'))AS int)+1 as varchar) from '.....$'))
        from ".$tableName."
        where substring(".$FieldCount.",0,position('-' in ".$FieldCount."))='".$RefInit."' and substring(".$FieldCount.",length(ref_no)-9,2)='".date('y')."' and substring(".$FieldCount.",length(ref_no)-7,2)='".date('m')."')
      END  AS ref_no
    from ".$tableName." where substring(".$FieldCount.",0,position('-' in ".$FieldCount."))='".$RefInit."' and substring(".$FieldCount.",length(ref_no)-9,2)='".date('y')."' and substring(".$FieldCount.",length(ref_no)-7,2)='".date('m')."'
    )
  ";
  $output = pg_query($conn, $sql) or die('Connection failed: ' . pg_last_error($conn));
  $AddRefNo = '';
  if (pg_num_rows($output) > 0) {
    while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
      $AddRefNo = $objid["ref_no"];
    }
  }
  return $AddRefNo;
}

function CreateNikNo($unit, $status, $fromdate) {
  global $conn;
  $sql = "
    select (nikunitinit((select init from m_location where id in (select location_id from m_department where id=".$unit.")),(select name from m_employee_status where id=".$status."))||'".date('y',strtotime($fromdate))."'||(substring('0000'||cast((coalesce(cast(max(substring(nik::varchar(8) from '....$'))as int),0)+1) as varchar) from '....$')))::varchar(8) AS ref_no
    from m_employee
    where substring(nik::varchar(8),3,2)='".date('y',strtotime($fromdate))."'
  ";
  $output = pg_query($conn, $sql) or die('Connection failed: ' . pg_last_error($conn));
  $AddRefNo = '';
  if (pg_num_rows($output) > 0) {
    while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
      $AddRefNo = $objid["ref_no"];
    }
  }
  return $AddRefNo;
}

function json2Array($jsonData) {
    global $conn;

    $jArray = json_decode(stripslashes((string)($jsonData ?? '')), true);

    if (is_array($jArray) && count($jArray) > 0) {
        $escapedItems = array();

        foreach ($jArray as $item) {
            $itemStr = (string)($item ?? '');
            $escapedItems[] = "'" . pg_escape_string($conn, $itemStr) . "'";
        }

        return implode(",", $escapedItems);
    }

    return "";
}

function String_to_float($string_obj) {
  $string = $string_obj;
  $a = explode(".", $string);
  $a2 = explode(",", $a[0]);
  $int = implode("", $a2);
  $dec = isset($a[1]) ? $a[1] : 0;
  $lengthofnum = strlen($dec);
  $divider = "1";
  $i = 0;
  while ($i < $lengthofnum) {
    $divider .= "0";
    $i++;
  }
  $divider = (int)$divider;
  return $int + ($dec / $divider);
}

function EncodeData($MyData) { return base64_encode($MyData); }
function DecodeData($MyData) { return base64_decode($MyData); }

function rNum($number, $round) { return floor($number / $round) * $round; }

function rAmountReport($number, $round) {
  if ($number > 0) {
    return number_format(floor($number / $round) * $round, 0, '.', ',');
  } elseif ($number < 0) {
    return number_format(floor((($number * -1) / $round) * $round) * -1, 0, '.', ',');
  } else {
    return '-';
  }
}

function GetFormId($formURl) {
  global $conn;
  $formName = explode("/", $formURl);
  $getFormName = end($formName);
  $sql = "select * from m_form_name where php_url='" . pg_escape_string($conn, $getFormName) . "'";
  $output = pg_query($conn, $sql) or die('Connection failed: ' . pg_last_error($conn));
  $formId = null;
  if (pg_num_rows($output) > 0) {
    while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
      $formId = $objid["id"];
    }
  }
  return $formId;
}

function CheckCRUD($task, $userId, $formId) {
  global $conn;
  $newTask = 'xxx';
  $sql = "select * from m_user_access_menu where user_id='" . pg_escape_string($conn, $userId) . "' and form_id=" . intval($formId);
  $output = pg_query($conn, $sql) or die('Connection failed: ' . pg_last_error($conn));
  $formAccess = 0;
  if (pg_num_rows($output) > 0) {
    while ($objid = pg_fetch_array($output, null, PGSQL_ASSOC)) {
      $formAccess = $objid["form_access"];
    }
  }
  switch ($formAccess) {
    case (1):
      if ($task == 'create' || $task == 'update' || $task == 'delete') { $newTask = 'xxx'; } else { $newTask = $task; }
      break;
    case (2):
      if ($task == 'delete') { $newTask = 'xxx'; } else { $newTask = $task; }
      break;
    case (3):
      $newTask = $task;
      break;
  }
  return $newTask;
}

function Str2N($StrData) {
  return floatval(preg_replace("/[^-0-9\.]/", "", $StrData));
}

function checksinglequote($txt) {
    global $conn;
    if ($txt === null || is_bool($txt)) {
        return '';
    }
    $quotes = array("'", '"');
    return str_replace($quotes, '', pg_escape_string($conn, (string)$txt));
}

function deleteQ($table, $where = null) {
    global $conn;

    $command = "DELETE FROM " . $table;

    if ($where != null && $where !== '') {
        $command .= " WHERE " . $where;
    }

    return $command;
};

/*
  Fungsi   : DbOrFail()
  Kenapa   : crud::insert() / crud::update() / crud::delete() (di
             crudClass.php) MENANGKAP PDOException sendiri dan hanya
             `return false` -- pesan errornya tersimpan di $db->getResult(),
             TIDAK pernah dilempar ulang sebagai exception. Akibatnya kalau
             kode pemanggil tidak mengecek return value-nya secara eksplisit,
             kegagalan insert/update jadi senyap: transaksi Postgres sudah
             aborted, tapi PHP tetap lanjut ke statement berikutnya sampai
             akhirnya meledak sebagai "25P02: current transaction is aborted"
             di query lain yang sebenarnya tidak bersalah.

  Cara pakai: bungkus SETIAP panggilan $db->insert()/update()/delete() yang
  ada di dalam try/beginTransaction() dengan fungsi ini, supaya kegagalan
  langsung ketahuan & transaksi langsung rollback dengan pesan asli dari
  Postgres -- bukan pesan 25P02 yang membingungkan.

      DbOrFail($db, $db->insert('journal_entries', [...]), 'insert journal_entries');

  Throws Exception dengan pesan asli dari $db->getResult() jika $ok === false.
*/
function DbOrFail($db, $ok, $context = '') {
    if ($ok === false) {
        $raw = $db->getResult();
        $decoded = json_decode($raw, true);
        $msg = $decoded['message'] ?? $raw ?? 'Operasi database gagal (tanpa pesan detail).';
        throw new Exception(($context ? "[{$context}] " : '') . $msg);
    }
    return $ok;
}
/*
  Fungsi      : AutoPostJournal()
  Lokasi      : Tambahkan ke function.all.php (shared, dipakai lintas modul)
  Tujuan      : Generate jurnal otomatis dari modul transaksi (Sales Invoice,
                Purchase GRN, Customer Payment, dll) berdasarkan rule yang
                sudah didefinisikan di tabel fiscal_config.

  Cara pakai dari modul lain (contoh setelah simpan Sales Invoice baru):

      require_once __DIR__ . '/../php-func/function.all.php';
      $result = AutoPostJournal($db, [
          'rule_code'      => 'SLSCASH',           // cocokkan ke fiscal_config.rule_code
          'reference_type' => 'sales_univoice',      // harus salah satu nilai enum reference_type
          'reference_id'   => $newInvoiceId,        // id record di tabel sumber (sales_univoices dkk)
          'journal_date'   => $univoiceDate,         // tanggal transaksi
          'amount'         => $totalAmount,         // nominal transaksi
          'description'    => 'Invoice ' . $univoiceNo
      ]);

      if (!$result['success']) {
          // tangani error -- misal rule tidak ditemukan, atau periode closed.
          // Modul pemanggil TETAP bisa lanjut simpan transaksinya (jurnal
          // gagal dibuat bukan berarti transaksi sumber harus dibatalkan --
          // itu keputusan bisnis, sesuaikan sesuai kebutuhan Anda).
      }

  --------------------------------------------------------------------------
  UPGRADE: Override akun per transaksi (mis. Payment Entry dengan combo
  pilihan Kas/Bank dinamis dari user, bukan akun tetap dari fiscal_config).

  rule_code TETAP WAJIB diisi dan tetap divalidasi ke fiscal_config -- rule
  dipakai untuk kontrol/audit bahwa hanya kombinasi posting yang sudah
  didaftarkan yang boleh jalan, dan sebagai fallback account bila override
  tidak dikirim. Tambahkan parameter opsional:

      'debit_account_id'  => int   // override akun debit rule utk transaksi ini
      'credit_account_id' => int   // override akun kredit rule utk transaksi ini

  Contoh Payment Entry (Kas/Bank dipilih user, kredit ikut rule AR/Uang Muka):

      AutoPostJournal($db, [
          'rule_code'         => 'PAY_AR',
          'reference_type'    => 'payment_entry',
          'reference_id'      => $paymentId,
          'journal_date'      => $payDate,
          'amount'            => $allocatedTotal,
          'description'       => 'Pelunasan Invoice ' . $paymentNo,
          'debit_account_id'  => $accountId,   // akun Kas/Bank pilihan user
      ]);

  Return: ['success' => bool, 'message' => string, 'journal_id' => int|null]
*/

function AutoPostJournal($db, $params) {
    $ruleCode      = $params['rule_code'] ?? '';
    $referenceType = $params['reference_type'] ?? '';
    $referenceId   = intval($params['reference_id'] ?? 0);
    $journalDate   = $params['journal_date'] ?? date('Y-m-d');
    $amount        = Str2N($params['amount'] ?? 0);
    $description   = $params['description'] ?? '';

    // Override opsional -- kalau diisi, dipakai menggantikan akun dari rule.
    $debitAccountIdOverride  = intval($params['debit_account_id']  ?? 0);
    $creditAccountIdOverride = intval($params['credit_account_id'] ?? 0);

    if (empty($ruleCode) || empty($referenceType) || $amount <= 0) {
        return ['success' => false, 'message' => 'Parameter AutoPostJournal tidak lengkap (rule_code/reference_type/amount).', 'journal_id' => null];
    }

    // 1. Cari rule di fiscal_config (tetap wajib ada & aktif, sebagai kontrol
    //    posting resmi -- walau akunnya nanti bisa di-override per transaksi)
    $stmtRule = $db->prepare("SELECT debit_account_id, credit_account_id, rule_name FROM fiscal_config WHERE rule_code = :code AND is_active = true");
    $stmtRule->execute(['code' => $ruleCode]);
    $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);
    if (!$rule) {
        return ['success' => false, 'message' => "Rule posting '{$ruleCode}' tidak ditemukan atau tidak aktif di fiscal_config.", 'journal_id' => null];
    }

    $debitAccountId  = $debitAccountIdOverride  > 0 ? $debitAccountIdOverride  : intval($rule['debit_account_id']);
    $creditAccountId = $creditAccountIdOverride > 0 ? $creditAccountIdOverride : intval($rule['credit_account_id']);

    if ($debitAccountId <= 0 || $creditAccountId <= 0) {
        return ['success' => false, 'message' => "Akun debit/kredit untuk rule '{$ruleCode}' tidak valid (cek fiscal_config atau override yang dikirim).", 'journal_id' => null];
    }

    // 2. Cari periode akuntansi berdasarkan tanggal transaksi
    $stmtPeriod = $db->prepare("
        SELECT id, is_closed FROM accounting_periods
        WHERE :jdate BETWEEN start_date AND end_date
        LIMIT 1
    ");
    $stmtPeriod->execute(['jdate' => $journalDate]);
    $period = $stmtPeriod->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        return ['success' => false, 'message' => "Tidak ada periode akuntansi yang mencakup tanggal {$journalDate}. Buat periode-nya dulu di menu Fiscal Period.", 'journal_id' => null];
    }
    if ($period['is_closed']) {
        return ['success' => false, 'message' => "Periode akuntansi untuk tanggal {$journalDate} sudah ditutup. Jurnal otomatis tidak bisa dibuat.", 'journal_id' => null];
    }

    // 3. Buat header jurnal -- langsung status 'posted' (bukan draft), karena
    //    ini auto-generate dari transaksi yang sudah tervalidasi di modul asal.
    $refNo = CreateRefNo('journal_entries', 'ref_no', 'JV'); // JV = Journal Voucher (auto)

    try {
        DbOrFail($db, $db->insert('journal_entries', [
            'ref_no'         => $refNo,
            'journal_date'   => $journalDate,
            'period_id'      => $period['id'],
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'description'    => $description ?: $rule['rule_name'],
            'status'         => 'posted',
            'posted_by'      => $_SESSION['user_id'] ?? null,
            'posted_at'      => date('Y-m-d H:i:s')
        ]), 'insert journal_entries');

        $stmtNewId = $db->prepare("SELECT id FROM journal_entries WHERE ref_no = :no");
        $stmtNewId->execute(['no' => $refNo]);
        $journalId = $stmtNewId->fetch(PDO::FETCH_ASSOC)['id'] ?? null;

        if (!$journalId) {
            return ['success' => false, 'message' => 'Gagal membuat header jurnal.', 'journal_id' => null];
        }

        // 4. Insert 2 baris (debit & kredit) -- pakai akun hasil override jika ada
        DbOrFail($db, $db->insert('journal_lines', [
            'journal_id'  => $journalId,
            'account_id'  => $debitAccountId,
            'debit'       => $amount,
            'credit'      => 0,
            'description' => $description
        ]), 'insert journal_lines (debit)');

        DbOrFail($db, $db->insert('journal_lines', [
            'journal_id'  => $journalId,
            'account_id'  => $creditAccountId,
            'debit'       => 0,
            'credit'      => $amount,
            'description' => $description
        ]), 'insert journal_lines (credit)');

    } catch (Exception $e) {
        // Pesan asli dari Postgres (mis. constraint reference_type/status,
        // FK period_id/account_id tidak valid, dsb) dikembalikan ke pemanggil
        // -- bukan dibiarkan jadi 25P02 senyap di query lain.
        return ['success' => false, 'message' => $e->getMessage(), 'journal_id' => null];
    }

    return ['success' => true, 'message' => "Jurnal otomatis {$refNo} berhasil dibuat.", 'journal_id' => $journalId];
}

/*
  Fungsi   : ReverseJournalByReference()
  Tujuan   : Hapus semua jurnal (header + lines) yang tercatat untuk sebuah
             reference_type + reference_id tertentu. Dipakai saat modul
             sumber (Payment Entry, Sales Invoice, dll) di-update atau
             dihapus, sebelum AutoPostJournal() dipanggil ulang.

  Catatan  : Satu reference_type+reference_id bisa punya LEBIH DARI SATU
             journal_entries (mis. Payment Entry dengan alokasi Piutang +
             Uang Muka = 2 kali panggil AutoPostJournal = 2 journal_entries).
             Fungsi ini menghapus semuanya sekaligus.

  Return: int  -> jumlah journal_entries yang dihapus
*/
function ReverseJournalByReference($db, $referenceType, $referenceId) {
    $referenceId = intval($referenceId);
    if (empty($referenceType) || $referenceId <= 0) {
        return 0;
    }

    $stmtFind = $db->prepare("SELECT id FROM journal_entries WHERE reference_type = :rtype AND reference_id = :rid");
    $stmtFind->execute(['rtype' => $referenceType, 'rid' => $referenceId]);
    $journalIds = $stmtFind->fetchAll(PDO::FETCH_COLUMN);

    if (!$journalIds) {
        return 0;
    }

    $stmtDelLines  = $db->prepare("DELETE FROM journal_lines WHERE journal_id = :jid");
    $stmtDelHeader = $db->prepare("DELETE FROM journal_entries WHERE id = :jid");

    foreach ($journalIds as $jid) {
        $stmtDelLines->execute(['jid' => $jid]);
        $stmtDelHeader->execute(['jid' => $jid]);
    }

    return count($journalIds);
}

?>