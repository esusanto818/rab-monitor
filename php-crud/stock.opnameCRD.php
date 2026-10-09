<?php
/**
 * stockopnameCRD.php — Stock Opname
 * Alur: draft (bisa diedit) -> posted (selisih masuk ke stock_movements) -> reversed.
 *   selisih > 0 : IN  dengan biaya terakhir
 *   selisih < 0 : OUT dengan alokasi FIFO
 * Stok sistem dihitung ulang saat SAVE dan saat POST (stok bisa berubah sejak draft dibuat).
 * Action: list | detail | lookup_warehouses | lookup_items | load_system | save | post | reverse | delete
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';

define('OPNAME_REF_TYPE', 'STOCK_OPNAME');

$action = WhReq('action', 'list');

try {
    switch ($action) {
        case 'list':              OpList(); break;
        case 'detail':            OpDetail(); break;
        case 'lookup_warehouses': OpLookupWarehouses(); break;
        case 'lookup_items':      OpLookupItems(); break;
        case 'load_system':       OpLoadSystem(); break;
        case 'save':              OpSave(); break;
        case 'post':              OpPost(); break;
        case 'reverse':           OpReverse(); break;
        case 'delete':            OpDelete(); break;
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    @pg_query($conn, 'ROLLBACK');
    WhFail($e->getMessage());
} catch (Exception $e) {
    @pg_query($conn, 'ROLLBACK');
    WhLog('stockopnameCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server. Hubungi administrator.');
}

/* ------------------------------------------------------------------ */
function OpList() {
    list($start, $limit) = WhPage();
    $q = WhReq('query');
    $params = array();
    $where = '1=1';
    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $where .= ' AND (h.ref_no ILIKE $1 OR w.name ILIKE $1)';
    }
    $c = WhRow("SELECT COUNT(*) AS n FROM stock_opname_hdr h JOIN warehouses w ON w.id=h.warehouse_id WHERE $where", $params);
    $params[] = $limit;  $pl = '$' . count($params);
    $params[] = $start;  $ps = '$' . count($params);
    $rows = WhRows("SELECT h.id, h.ref_no, h.opname_date, h.warehouse_id, w.name AS warehouse_name,
                           h.status, h.remark,
                           COALESCE(t.line_count,0) AS line_count, COALESCE(t.diff_value,0) AS diff_value
                    FROM stock_opname_hdr h
                    JOIN warehouses w ON w.id=h.warehouse_id
                    LEFT JOIN (SELECT opname_hdr_id, COUNT(*) AS line_count, SUM(diff_qty*unit_cost) AS diff_value
                               FROM stock_opname_dtl GROUP BY opname_hdr_id) t ON t.opname_hdr_id=h.id
                    WHERE $where ORDER BY h.opname_date DESC, h.id DESC
                    LIMIT $pl OFFSET $ps", $params);
    WhJson(array('success' => true, 'totalCount' => (int)$c['n'], 'data' => $rows));
}

function OpDetail() {
    $id = (int)WhReq('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    $rows = WhRows("SELECT d.id, d.item_id, m.material_code, m.material_name, m.unit,
                           d.system_qty, d.counted_qty, d.diff_qty, d.unit_cost,
                           (d.diff_qty*d.unit_cost) AS diff_value, d.remark
                    FROM stock_opname_dtl d JOIN materials m ON m.id=d.item_id
                    WHERE d.opname_hdr_id=$1 ORDER BY m.material_name", array($id));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function OpLookupWarehouses() {
    $rows = WhRows("SELECT id, name FROM warehouses WHERE active=true ORDER BY name");
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// Semua material aktif + stok sistem di gudang (0 bila belum ada) -> untuk menambah item manual
function OpLookupItems() {
    $wid = (int)WhReq('warehouse_id');
    $q   = WhReq('query');
    $rows = WhRows("SELECT m.id AS item_id, m.material_code, m.material_name, m.unit,
                           COALESCE(oh.onhand,0) AS system_qty
                    FROM materials m
                    LEFT JOIN (SELECT item_id, SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END) AS onhand
                               FROM stock_movements WHERE warehouse_id=$1 GROUP BY item_id) oh ON oh.item_id=m.id
                    WHERE m.active IS NOT FALSE AND (m.material_name ILIKE $2 OR m.material_code ILIKE $2)
                    ORDER BY m.material_name LIMIT 50", array($wid, '%' . $q . '%'));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// Muat seluruh item yang berstok di gudang sebagai lembar hitung awal
function OpLoadSystem() {
    $wid = (int)WhReq('warehouse_id');
    if ($wid < 1) { throw new WhException('Gudang wajib dipilih.'); }
    $rows = WhRows("SELECT m.id AS item_id, m.material_code, m.material_name, m.unit, oh.onhand AS system_qty
                    FROM materials m
                    JOIN (SELECT item_id, SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END) AS onhand
                          FROM stock_movements WHERE warehouse_id=$1 GROUP BY item_id) oh ON oh.item_id=m.id
                    WHERE oh.onhand <> 0 ORDER BY m.material_name", array($wid));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function OpSave() {
    WhRequirePost();
    $id      = (int)WhPost('id');
    $whId    = (int)WhPost('warehouse_id');
    $date    = WhDate(WhPost('opname_date'), 'Tanggal opname');
    $remark  = WhPost('remark');
    $details = WhDetails();
    $userId  = WhUserId();
    if ($whId < 1) { throw new WhException('Gudang wajib dipilih.'); }
    $w = WhRow('SELECT id FROM warehouses WHERE id=$1 AND active=true', array($whId));
    if (!$w) { throw new WhException('Gudang tidak ditemukan / tidak aktif.'); }

    $lines = array();
    foreach ($details as $d) {
        $iid = isset($d['item_id']) ? (int)$d['item_id'] : 0;
        if ($iid < 1) { throw new WhException('Item pada detail tidak valid.'); }
        if (!isset($d['counted_qty']) || !is_numeric($d['counted_qty']) || (float)$d['counted_qty'] < 0) {
            throw new WhException('Qty hitung harus berupa angka >= 0.');
        }
        if (isset($lines[$iid])) { throw new WhException('Item duplikat pada detail (ID ' . $iid . ').'); }
        $lines[$iid] = array('counted' => WhNum($d['counted_qty']), 'remark' => isset($d['remark']) ? trim($d['remark']) : '');
    }

    WhQ('BEGIN');
    if ($id > 0) {
        $h = WhRow('SELECT id, status FROM stock_opname_hdr WHERE id=$1 FOR UPDATE', array($id));
        if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
        if ($h['status'] !== 'draft') { throw new WhException('Hanya dokumen draft yang dapat diubah.'); }
        WhQ('UPDATE stock_opname_hdr SET opname_date=$2, warehouse_id=$3, remark=$4 WHERE id=$1',
            array($id, $date, $whId, $remark));
        WhQ('DELETE FROM stock_opname_dtl WHERE opname_hdr_id=$1', array($id));
        $refNo = WhRow('SELECT ref_no FROM stock_opname_hdr WHERE id=$1', array($id));
        $refNo = $refNo['ref_no'];
    } else {
        $refNo = CreateRefNo('stock_opname_hdr', 'ref_no', 'SO');
        $h = WhRow("INSERT INTO stock_opname_hdr (ref_no, opname_date, warehouse_id, status, remark, created_by)
                    VALUES ($1,$2,$3,'draft',$4,$5) RETURNING id", array($refNo, $date, $whId, $remark, $userId));
        $id = (int)$h['id'];
    }
    foreach ($lines as $iid => $ln) {
        $m = WhRow('SELECT id FROM materials WHERE id=$1', array($iid));
        if (!$m) { throw new WhException('Item ID ' . $iid . ' tidak ditemukan.'); }
        $sys = WhOnHand($iid, $whId);
        WhQ("INSERT INTO stock_opname_dtl (opname_hdr_id, item_id, system_qty, counted_qty, diff_qty, unit_cost, remark)
             VALUES ($1,$2,$3,$4,$5,0,$6)",
            array($id, $iid, $sys, $ln['counted'], round($ln['counted'] - $sys, 4), $ln['remark']));
    }
    WhQ('COMMIT');
    WhOk('Draft opname tersimpan. No: ' . $refNo, array('id' => $id, 'ref_no' => $refNo));
}

function OpPost() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    $userId = WhUserId();

    WhQ('BEGIN');
    $h = WhRow('SELECT id, ref_no, opname_date, warehouse_id, status FROM stock_opname_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'draft') { throw new WhException('Hanya dokumen draft yang dapat diposting.'); }
    $whId = (int)$h['warehouse_id'];
    $dtl = WhRows('SELECT id, item_id, counted_qty FROM stock_opname_dtl WHERE opname_hdr_id=$1 ORDER BY item_id', array($id));
    if (!count($dtl)) { throw new WhException('Dokumen opname belum memiliki item.'); }

    foreach ($dtl as $d) {
        $iid = (int)$d['item_id'];
        WhLock($iid, $whId);
        $sys  = WhOnHand($iid, $whId);                       // hitung ulang saat posting
        $diff = round((float)$d['counted_qty'] - $sys, 4);
        $cost = 0.0;
        if ($diff > 0.00005) {                               // lebih -> IN
            $cost = WhLastCost($iid, $whId);
            WhPostMovement($h['opname_date'], $iid, $whId, 'IN', $diff, $cost, OPNAME_REF_TYPE, $id, $h['ref_no'], 'Selisih opname (+)', $userId);
        } elseif ($diff < -0.00005) {                        // kurang -> OUT (FIFO)
            $tot = 0.0;
            foreach (WhFifoAllocate($iid, $whId, -$diff) as $ly) {
                WhPostMovement($h['opname_date'], $iid, $whId, 'OUT', $ly['qty'], $ly['unit_cost'], OPNAME_REF_TYPE, $id, $h['ref_no'], 'Selisih opname (-)', $userId);
                $tot += $ly['qty'] * $ly['unit_cost'];
            }
            $cost = $tot / (-$diff);                         // biaya rata-rata layer yang dikeluarkan
        }
        WhQ('UPDATE stock_opname_dtl SET system_qty=$2, diff_qty=$3, unit_cost=$4 WHERE id=$1',
            array((int)$d['id'], $sys, $diff, round($cost, 4)));
    }
    WhQ("UPDATE stock_opname_hdr SET status='posted', posted_at=now(), posted_by=$2 WHERE id=$1", array($id, $userId));
    WhQ('COMMIT');
    WhOk('Opname ' . $h['ref_no'] . ' berhasil diposting.');
}

function OpReverse() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status FROM stock_opname_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'posted') { throw new WhException('Hanya dokumen posted yang dapat di-reverse.'); }
    WhQ("UPDATE stock_opname_hdr SET status='reversed', reversed_at=now(), reversed_by=$2 WHERE id=$1", array($id, WhUserId()));
    WhDeleteMovementsByRef(OPNAME_REF_TYPE, $id);
    WhQ('COMMIT');
    WhOk('Opname berhasil di-reverse.');
}

function OpDelete() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status FROM stock_opname_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'draft') { throw new WhException('Hanya dokumen draft yang dapat dihapus.'); }
    WhQ('DELETE FROM stock_opname_hdr WHERE id=$1', array($id));   // detail ikut terhapus (ON DELETE CASCADE)
    WhQ('COMMIT');
    WhOk('Draft opname dihapus.');
}