<?php
/**
 * function.stock.php
 * Helper stok bersama untuk: Warehouse Issuing, Stock Transfer, Stock Card, Stock Opname.
 *
 * Pemakaian di endpoint (setelah function.all.php yang menyediakan $conn):
 *     require_once '../php-func/function.all.php';
 *     require_once '../php-class/crudClass.php';
 *     require_once '../php-func/function.stock.php';
 *
 * ASUMSI (mohon diverifikasi):
 *   - stock_movements.movement_type berisi 'IN' / 'OUT', qty SELALU positif.
 *   - Saldo = SUM(IN) - SUM(OUT) per (item_id, warehouse_id).
 *   - Costing FIFO: layer = baris 'IN' berurutan (movement_date, id); total OUT historis
 *     "memakan" layer dari yang tertua.
 */

if (!class_exists('WhException')) {
    // Error bisnis (pesan aman ditampilkan ke user). Exception lain -> pesan generik + log.
    class WhException extends Exception {}
}

/* ------------------------------------------------------------------ */
/* Response & logging                                                  */
/* ------------------------------------------------------------------ */
if (!function_exists('WhJson')) {
    function WhJson($arr) {
        while (ob_get_level() > 0) { ob_end_clean(); }   // buang output liar agar JSON tetap valid
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($arr);
        exit;
    }
}
function WhFail($msg) { WhJson(array('success' => false, 'message' => $msg)); }
function WhOk($msg, $extra = array()) { WhJson(array_merge(array('success' => true, 'message' => $msg), $extra)); }

function WhLog($msg) {
    $dir = dirname(__FILE__) . '/../logs';              // simpan di file log terpisah
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @error_log(date('Y-m-d H:i:s') . ' ' . $msg . "\n", 3, $dir . '/stock-error.log');
}

/* ------------------------------------------------------------------ */
/* Query helpers (semua pakai pg_query_params)                         */
/* ------------------------------------------------------------------ */
function WhQ($sql, $params = array()) {
    global $conn;
    $r = @pg_query_params($conn, $sql, $params);
    if ($r === false) { throw new Exception(pg_last_error($conn) . ' | SQL: ' . $sql); }
    return $r;
}
function WhRows($sql, $params = array()) {
    $r = WhQ($sql, $params);
    $o = array();
    while ($x = pg_fetch_assoc($r)) { $o[] = $x; }
    return $o;
}
function WhRow($sql, $params = array()) {
    $rows = WhRows($sql, $params);
    return count($rows) ? $rows[0] : null;
}

/* ------------------------------------------------------------------ */
/* Request helpers                                                     */
/* ------------------------------------------------------------------ */
function WhReq($k, $d = '') { return isset($_REQUEST[$k]) ? trim($_REQUEST[$k]) : $d; }
function WhPost($k, $d = '') { return isset($_POST[$k]) ? trim($_POST[$k]) : $d; }
function WhRequirePost() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new WhException('Metode request harus POST.'); }
}
function WhPage() {
    $s = isset($_REQUEST['start']) ? max(0, (int)$_REQUEST['start']) : 0;
    $l = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 25;
    if ($l < 1 || $l > 500) { $l = 25; }
    return array($s, $l);
}
function WhDate($s, $label = 'Tanggal') {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new WhException($label . ' tidak valid (format harus YYYY-MM-DD).');
    }
    return $s;
}
function WhDetails() {
    $d = json_decode(isset($_POST['details']) ? $_POST['details'] : '', true);
    if (!is_array($d) || !count($d)) { throw new WhException('Detail item tidak boleh kosong.'); }
    return $d;
}
function WhNum($v) { return (is_numeric($v)) ? round((float)$v, 4) : 0.0; }
function WhFmt($n) { return rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.'); }
function WhBool($v) { return ($v === 't' || $v === true || $v === 'true' || $v === '1'); }
function WhUserId() {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    foreach (array('user_id', 'userid', 'id') as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k])) { return (int)$_SESSION[$k]; }
    }
    return null;   // SESUAIKAN dengan key session login Anda
}

/* ------------------------------------------------------------------ */
/* Stok                                                                */
/* ------------------------------------------------------------------ */
// Kunci transaksi per (item, gudang) agar dua posting bersamaan tidak membuat stok minus.
function WhLock($itemId, $whId) {
    WhQ('SELECT pg_advisory_xact_lock($1,$2)', array((int)$itemId, (int)$whId));
}

function WhOnHand($itemId, $whId) {
    $r = WhRow("SELECT COALESCE(SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END),0) AS q
                FROM stock_movements WHERE item_id=$1 AND warehouse_id=$2",
               array((int)$itemId, (int)$whId));
    return round((float)$r['q'], 4);
}

// Alokasi FIFO: kembalikan daftar layer [qty, unit_cost] yang dipakai untuk mengeluarkan $qty.
function WhFifoAllocate($itemId, $whId, $qty) {
    $need = round((float)$qty, 4);
    $r = WhRow("SELECT COALESCE(SUM(qty),0) AS q FROM stock_movements
                WHERE item_id=$1 AND warehouse_id=$2 AND movement_type='OUT'",
               array((int)$itemId, (int)$whId));
    $skip = (float)$r['q'];                              // layer tertua sudah terpakai sebanyak ini
    $layers = WhRows("SELECT qty, unit_cost FROM stock_movements
                      WHERE item_id=$1 AND warehouse_id=$2 AND movement_type='IN'
                      ORDER BY movement_date, id", array((int)$itemId, (int)$whId));
    $out = array();
    foreach ($layers as $l) {
        $lq = (float)$l['qty'];
        if ($skip >= $lq - 0.00005) { $skip -= $lq; continue; }
        $avail = $lq - $skip;
        $skip = 0;
        $take = min($avail, $need);
        if ($take > 0) {
            $out[] = array('qty' => round($take, 4), 'unit_cost' => (float)$l['unit_cost']);
            $need = round($need - $take, 4);
        }
        if ($need <= 0.00005) { break; }
    }
    if ($need > 0.00005) {
        throw new WhException('Layer FIFO tidak mencukupi (kurang ' . WhFmt($need) . '). Periksa konsistensi stock_movements.');
    }
    return $out;
}

function WhPostMovement($date, $itemId, $whId, $type, $qty, $cost, $refType, $refId, $refNo, $remark, $userId) {
    WhQ("INSERT INTO stock_movements
            (movement_date, item_id, grade_id, warehouse_id, movement_type, qty, unit_cost,
             reference_type, reference_id, reference_no, remark, created_by)
         VALUES ($1,$2,NULL,$3,$4,$5,$6,$7,$8,$9,$10,$11)",
        array($date, (int)$itemId, (int)$whId, $type, $qty, $cost, $refType, (int)$refId, $refNo, $remark, $userId));
}

// Reverse = hapus baris ledger milik dokumen (menjaga urutan layer FIFO tetap benar;
// jejak audit tetap ada di data_log_delete). Tolak jika membuat stok minus.
function WhDeleteMovementsByRef($refType, $refId) {
    $pairs = WhRows("SELECT DISTINCT item_id, warehouse_id FROM stock_movements
                     WHERE reference_type=$1 AND reference_id=$2 ORDER BY item_id, warehouse_id",
                    array($refType, (int)$refId));
    foreach ($pairs as $p) { WhLock($p['item_id'], $p['warehouse_id']); }   // urut -> aman dari deadlock
    WhQ("DELETE FROM stock_movements WHERE reference_type=$1 AND reference_id=$2", array($refType, (int)$refId));
    foreach ($pairs as $p) {
        $oh = WhOnHand($p['item_id'], $p['warehouse_id']);
        if ($oh < -0.00005) {
            $m = WhRow('SELECT material_name FROM materials WHERE id=$1', array((int)$p['item_id']));
            throw new WhException('Reverse ditolak: stok ' . ($m ? $m['material_name'] : $p['item_id']) .
                ' akan minus (' . WhFmt($oh) . ') karena sudah dipakai transaksi lain.');
        }
    }
}

// Biaya satuan untuk penambahan stok tanpa layer asal (mis. selisih opname plus).
function WhLastCost($itemId, $whId) {
    $r = WhRow("SELECT unit_cost FROM stock_movements
                WHERE item_id=$1 AND movement_type='IN' AND unit_cost>0
                ORDER BY (warehouse_id=$2) DESC, movement_date DESC, id DESC LIMIT 1",
               array((int)$itemId, (int)$whId));
    if ($r) { return (float)$r['unit_cost']; }
    $m = WhRow('SELECT COALESCE(estimated_price,0) AS p FROM materials WHERE id=$1', array((int)$itemId));
    return $m ? (float)$m['p'] : 0.0;
}