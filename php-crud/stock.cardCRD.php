<?php
/**
 * stockcardCRD.php — Stock Card (kartu stok per item, per gudang / semua gudang)
 * Output: baris "Saldo Awal" + mutasi periode + running balance, plus ringkasan.
 * Action: card | lookup_warehouses | lookup_items   (read-only)
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';

$action = WhReq('action', 'card');

try {
    switch ($action) {
        case 'card':              CardLoad(); break;
        case 'lookup_warehouses': CardLookupWarehouses(); break;
        case 'lookup_items':      CardLookupItems(); break;
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    WhFail($e->getMessage());
} catch (Exception $e) {
    WhLog('stockcardCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server. Hubungi administrator.');
}

/* ------------------------------------------------------------------ */
function CardLookupWarehouses() {
    $rows = WhRows("SELECT id, name FROM warehouses WHERE active=true ORDER BY name");
    array_unshift($rows, array('id' => 0, 'name' => '-- Semua Gudang --'));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// Item yang pernah punya mutasi (opsional dibatasi gudang)
function CardLookupItems() {
    $wid = (int)WhReq('warehouse_id');
    $q   = WhReq('query');
    $params = array('%' . $q . '%');
    $wf = '';
    if ($wid > 0) { $params[] = $wid; $wf = ' AND s.warehouse_id=$2'; }
    $rows = WhRows("SELECT m.id AS item_id, m.material_code, m.material_name, m.unit
                    FROM materials m
                    WHERE (m.material_name ILIKE $1 OR m.material_code ILIKE $1)
                      AND EXISTS (SELECT 1 FROM stock_movements s WHERE s.item_id=m.id $wf)
                    ORDER BY m.material_name LIMIT 50", $params);
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function CardLoad() {
    $itemId = (int)WhReq('item_id');
    $wid    = (int)WhReq('warehouse_id');
    if ($itemId < 1) { throw new WhException('Item wajib dipilih.'); }
    $from = WhDate(WhReq('date_from'), 'Tanggal awal');
    $to   = WhDate(WhReq('date_to'), 'Tanggal akhir');
    if ($from > $to) { throw new WhException('Tanggal awal tidak boleh melebihi tanggal akhir.'); }

    $wf1 = ''; $wf2 = '';
    $p1 = array($itemId, $from);               // saldo awal
    $p2 = array($itemId, $from, $to);          // mutasi periode
    if ($wid > 0) {
        $p1[] = $wid; $wf1 = ' AND warehouse_id=$3';
        $p2[] = $wid; $wf2 = ' AND s.warehouse_id=$4';
    }

    $o = WhRow("SELECT COALESCE(SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END),0) AS q
                FROM stock_movements WHERE item_id=$1 AND movement_date < $2 $wf1", $p1);
    $balance = round((float)$o['q'], 4);

    $mv = WhRows("SELECT s.id, s.movement_date, s.movement_type, s.qty, s.unit_cost,
                         s.reference_type, s.reference_no, s.remark, w.name AS warehouse_name
                  FROM stock_movements s JOIN warehouses w ON w.id=s.warehouse_id
                  WHERE s.item_id=$1 AND s.movement_date BETWEEN $2 AND $3 $wf2
                  ORDER BY s.movement_date, s.id", $p2);

    $data = array(array(            // baris saldo awal
        'row_type' => 'OPEN', 'movement_date' => $from, 'reference_type' => '', 'reference_no' => '',
        'warehouse_name' => '', 'remark' => 'Saldo Awal', 'qty_in' => null, 'qty_out' => null,
        'unit_cost' => null, 'balance' => $balance
    ));
    $totIn = 0; $totOut = 0;
    foreach ($mv as $r) {
        $q = (float)$r['qty'];
        $isIn = ($r['movement_type'] === 'IN');
        $balance = round($balance + ($isIn ? $q : -$q), 4);
        if ($isIn) { $totIn += $q; } else { $totOut += $q; }
        $data[] = array(
            'row_type' => 'MOV', 'movement_date' => $r['movement_date'],
            'reference_type' => $r['reference_type'], 'reference_no' => $r['reference_no'],
            'warehouse_name' => $r['warehouse_name'], 'remark' => $r['remark'],
            'qty_in' => $isIn ? $q : null, 'qty_out' => $isIn ? null : $q,
            'unit_cost' => (float)$r['unit_cost'], 'balance' => $balance
        );
    }
    WhJson(array('success' => true, 'totalCount' => count($data), 'data' => $data,
                 'summary' => array('opening' => (float)$o['q'], 'total_in' => round($totIn, 4),
                                    'total_out' => round($totOut, 4), 'ending' => $balance)));
}