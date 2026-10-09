<?php
/**
 * supplierreturnCRD.php — Retur Supplier untuk material cacat / rusak
 *
 * Syarat : material sudah di-RCV (ada stok di gudang proyek).
 * Alur   : Diajukan --(Kirim)--> Dikirim --(Selesaikan)--> Diganti | Kredit
 *          Diajukan --(Batalkan)--> Dibatalkan ; Dikirim --(Batal Kirim)--> Diajukan
 * Kirim  : membuat dokumen ISSUE W/H (material_out_header.issue_type = 'SUPPLIER_RETURN'),
 *          stok keluar FIFO lewat stock_movements (reference_type 'MATERIAL_OUT', sama dengan Warehouse Issuing)
 *          sehingga tampil di Stock Card. Issue retur TIDAK menghitung kuota RAB +5%.
 * Action : list | detail | lookup_projects | lookup_warehouses | lookup_suppliers | lookup_pos |
 *          lookup_materials | save | send | unsend | resolve | cancel
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';

define('ISSUE_REF_TYPE', 'MATERIAL_OUT');        // harus sama dengan whissuingCRD.php
define('ISSUE_TYPE_RETURN', 'SUPPLIER_RETURN');

$DEFECT_TYPES = array('Cacat Produksi', 'Rusak Saat Pengiriman', 'Tidak Sesuai Spesifikasi',
                      'Kualitas Di Bawah Standar', 'Lainnya');
$action = WhReq('action', 'list');

try {
    switch ($action) {
        case 'list':              RtList(); break;
        case 'detail':            RtDetail(); break;
        case 'lookup_projects':   RtLookupProjects(); break;
        case 'lookup_warehouses': RtLookupWarehouses(); break;
        case 'lookup_suppliers':  RtLookupSuppliers(); break;
        case 'lookup_pos':        RtLookupPos(); break;
        case 'lookup_materials':  RtLookupMaterials(); break;
        case 'save':              RtSave(); break;
        case 'send':              RtSend(); break;
        case 'unsend':            RtUnsend(); break;
        case 'resolve':           RtResolve(); break;
        case 'cancel':            RtCancel(); break;
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    @pg_query($conn, 'ROLLBACK');
    WhFail($e->getMessage());
} catch (Exception $e) {
    @pg_query($conn, 'ROLLBACK');
    WhLog('supplierreturnCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server. Hubungi administrator.');
}

/* ------------------------------------------------------------------ */
// Qty yang sudah diklaim di retur lain berstatus Diajukan (belum keluar stok) -> dicadangkan
function RtOpenClaim($whId, $materialId, $exclHdrId) {
    $r = WhRow("SELECT COALESCE(SUM(d.qty_claim),0) AS q
                FROM supplier_return_dtl d JOIN supplier_return_hdr h ON h.id=d.return_hdr_id
                WHERE h.warehouse_id=$1 AND d.material_id=$2 AND h.status='Diajukan' AND h.id<>$3",
               array((int)$whId, (int)$materialId, (int)$exclHdrId));
    return round((float)$r['q'], 4);
}

function RtList() {
    list($start, $limit) = WhPage();
    $q = WhReq('query');
    $params = array();
    $where = '1=1';
    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $where .= ' AND (h.ref_no ILIKE $1 OR p.name ILIKE $1 OR s.name ILIKE $1 OR h.supplier_do_no ILIKE $1)';
    }
    $c = WhRow("SELECT COUNT(*) AS n FROM supplier_return_hdr h
                JOIN projects p ON p.id=h.project_id JOIN m_suppliers s ON s.id=h.supplier_id WHERE $where", $params);
    $params[] = $limit;  $pl = '$' . count($params);
    $params[] = $start;  $ps = '$' . count($params);
    $rows = WhRows("SELECT h.id, h.ref_no, h.return_date, h.project_id, p.name AS project_name,
                           h.warehouse_id, w.name AS warehouse_name, h.supplier_id, s.name AS supplier_name,
                           h.po_id, po.po_number, h.supplier_do_no, h.status, h.remark,
                           h.material_out_id, mo.ref_no AS issue_ref_no, h.sent_date, h.carrier,
                           h.resolution_type, h.resolution_date, h.credit_note_no, h.credit_amount, h.resolution_note,
                           COALESCE(t.line_count,0) AS line_count, COALESCE(t.total_qty,0) AS total_qty,
                           COALESCE(t.total_amount,0) AS total_amount
                    FROM supplier_return_hdr h
                    JOIN projects p ON p.id=h.project_id
                    JOIN warehouses w ON w.id=h.warehouse_id
                    JOIN m_suppliers s ON s.id=h.supplier_id
                    LEFT JOIN purchase_orders po ON po.id=h.po_id
                    LEFT JOIN material_out_header mo ON mo.id=h.material_out_id
                    LEFT JOIN (SELECT return_hdr_id, COUNT(*) AS line_count, SUM(qty_claim) AS total_qty,
                                      SUM(qty_claim*unit_cost) AS total_amount
                               FROM supplier_return_dtl GROUP BY return_hdr_id) t ON t.return_hdr_id=h.id
                    WHERE $where ORDER BY h.return_date DESC, h.id DESC
                    LIMIT $pl OFFSET $ps", $params);
    WhJson(array('success' => true, 'totalCount' => (int)$c['n'], 'data' => $rows));
}

function RtDetail() {
    $id = (int)WhReq('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    $rows = WhRows("SELECT d.id, d.material_id, m.material_code, m.material_name, m.unit,
                           d.qty_claim, d.unit_cost, (d.qty_claim*d.unit_cost) AS amount,
                           d.defect_type, d.defect_note, d.evidence_ref
                    FROM supplier_return_dtl d JOIN materials m ON m.id=d.material_id
                    WHERE d.return_hdr_id=$1 ORDER BY d.id", array($id));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function RtLookupProjects() {
    $rows = WhRows("SELECT id, name FROM projects ORDER BY name LIMIT 300");
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}
function RtLookupSuppliers() {
    $rows = WhRows("SELECT id, name FROM m_suppliers WHERE active IS NOT FALSE ORDER BY name LIMIT 500");
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}
function RtLookupWarehouses() {
    $rows = WhRows("SELECT id, code, name FROM warehouses WHERE active=true AND project_id=$1 ORDER BY name",
                   array((int)WhReq('project_id')));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}
// PO referensi (opsional) untuk proyek + supplier terpilih; baris pertama = tanpa PO
function RtLookupPos() {
    $rows = WhRows("SELECT id, po_number, po_date FROM purchase_orders
                    WHERE project_id=$1 AND supplier_id=$2 AND status <> 'Dibatalkan'
                    ORDER BY po_date DESC, id DESC LIMIT 200",
                   array((int)WhReq('project_id'), (int)WhReq('supplier_id')));
    array_unshift($rows, array('id' => 0, 'po_number' => '(tanpa referensi PO)', 'po_date' => ''));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// Material yang sudah RCV (stok > 0) di gudang; bila ada PO -> hanya material pada PO tsb
function RtLookupMaterials() {
    $wid  = (int)WhReq('warehouse_id');
    $poId = (int)WhReq('po_id');
    $excl = (int)WhReq('exclude_id');
    $q    = WhReq('query');
    $params = array($wid, '%' . $q . '%');
    $poF = '';
    if ($poId > 0) { $params[] = $poId; $poF = ' AND EXISTS (SELECT 1 FROM po_items pi WHERE pi.po_id=$3 AND pi.material_id=m.id)'; }
    $rows = WhRows("SELECT m.id AS material_id, m.material_code, m.material_name, m.unit, oh.onhand
                    FROM materials m
                    JOIN (SELECT item_id, SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END) AS onhand
                          FROM stock_movements WHERE warehouse_id=$1 GROUP BY item_id) oh ON oh.item_id=m.id
                    WHERE oh.onhand > 0 AND (m.material_name ILIKE $2 OR m.material_code ILIKE $2) $poF
                    ORDER BY m.material_name LIMIT 50", $params);
    foreach ($rows as &$r) {
        $open = RtOpenClaim($wid, $r['material_id'], $excl);
        $r['open_claim'] = $open;
        $r['available'] = max(0, round((float)$r['onhand'] - $open, 4));
    }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function RtSave() {
    global $DEFECT_TYPES;
    WhRequirePost();
    $id        = (int)WhPost('id');
    $projectId = (int)WhPost('project_id');
    $whId      = (int)WhPost('warehouse_id');
    $supId     = (int)WhPost('supplier_id');
    $poId      = (int)WhPost('po_id');
    $date      = WhDate(WhPost('return_date'), 'Tanggal retur');
    $doNo      = WhPost('supplier_do_no');
    $remark    = WhPost('remark');
    $details   = WhDetails();
    $userId    = WhUserId();

    if ($projectId < 1) { throw new WhException('Proyek wajib dipilih.'); }
    if ($whId < 1)      { throw new WhException('Gudang wajib dipilih.'); }
    if ($supId < 1)     { throw new WhException('Supplier wajib dipilih.'); }
    $wh = WhRow('SELECT project_id, active FROM warehouses WHERE id=$1', array($whId));
    if (!$wh || !WhBool($wh['active']))        { throw new WhException('Gudang tidak ditemukan / tidak aktif.'); }
    if ((int)$wh['project_id'] !== $projectId) { throw new WhException('Gudang tidak terdaftar untuk proyek ini.'); }
    if (!WhRow('SELECT 1 FROM m_suppliers WHERE id=$1', array($supId))) { throw new WhException('Supplier tidak ditemukan.'); }
    if ($poId > 0) {
        $po = WhRow('SELECT project_id, supplier_id FROM purchase_orders WHERE id=$1', array($poId));
        if (!$po) { throw new WhException('PO referensi tidak ditemukan.'); }
        if ((int)$po['project_id'] !== $projectId || (int)$po['supplier_id'] !== $supId) {
            throw new WhException('PO referensi tidak sesuai dengan proyek / supplier.');
        }
    }

    // Validasi baris; total klaim per material dibatasi stok - klaim terbuka lain
    $lines = array(); $sumQty = array();
    foreach ($details as $d) {
        $mid  = isset($d['material_id']) ? (int)$d['material_id'] : 0;
        $qty  = isset($d['qty_claim']) ? WhNum($d['qty_claim']) : 0;
        $type = isset($d['defect_type']) ? trim($d['defect_type']) : '';
        if ($mid < 1)  { throw new WhException('Material pada detail tidak valid.'); }
        if ($qty <= 0) { throw new WhException('Qty retur harus lebih besar dari 0.'); }
        if (!in_array($type, $DEFECT_TYPES, true)) { throw new WhException('Jenis cacat wajib dipilih untuk setiap baris.'); }
        $sumQty[$mid] = round((isset($sumQty[$mid]) ? $sumQty[$mid] : 0) + $qty, 4);
        $lines[] = array('mid' => $mid, 'qty' => $qty, 'type' => $type,
                         'note' => isset($d['defect_note']) ? trim($d['defect_note']) : '',
                         'evid' => isset($d['evidence_ref']) ? trim($d['evidence_ref']) : '');
    }

    WhQ('BEGIN');
    if ($id > 0) {
        $h = WhRow('SELECT id, ref_no, status FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
        if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
        if ($h['status'] !== 'Diajukan') { throw new WhException('Hanya retur berstatus Diajukan yang dapat diubah.'); }
        $refNo = $h['ref_no'];
    }
    foreach ($sumQty as $mid => $tot) {
        WhLock($mid, $whId);
        $m = WhRow('SELECT material_name FROM materials WHERE id=$1', array($mid));
        if (!$m) { throw new WhException('Material ID ' . $mid . ' tidak ditemukan.'); }
        if ($poId > 0 && !WhRow('SELECT 1 FROM po_items WHERE po_id=$1 AND material_id=$2', array($poId, $mid))) {
            throw new WhException($m['material_name'] . ' tidak ada pada PO referensi.');
        }
        $onhand = WhOnHand($mid, $whId);
        if ($onhand <= 0) { throw new WhException($m['material_name'] . ' belum di-RCV / stok di gudang kosong.'); }
        $avail = round($onhand - RtOpenClaim($whId, $mid, $id), 4);
        if ($tot > $avail + 0.00005) {
            throw new WhException('Qty retur ' . $m['material_name'] . ' (' . WhFmt($tot) . ') melebihi stok tersedia (' .
                WhFmt($avail) . ', sudah dikurangi klaim retur lain yang masih Diajukan).');
        }
    }
    if ($id > 0) {
        WhQ("UPDATE supplier_return_hdr SET return_date=$2, project_id=$3, warehouse_id=$4, supplier_id=$5, po_id=$6,
                    supplier_do_no=$7, remark=$8, updated_at=now() WHERE id=$1",
            array($id, $date, $projectId, $whId, $supId, $poId > 0 ? $poId : null, $doNo, $remark));
        WhQ('DELETE FROM supplier_return_dtl WHERE return_hdr_id=$1', array($id));
    } else {
        $refNo = CreateRefNo('supplier_return_hdr', 'ref_no', 'RTN');
        $h = WhRow("INSERT INTO supplier_return_hdr
                        (ref_no, return_date, project_id, warehouse_id, supplier_id, po_id, supplier_do_no, status, remark, created_by)
                    VALUES ($1,$2,$3,$4,$5,$6,$7,'Diajukan',$8,$9) RETURNING id",
                   array($refNo, $date, $projectId, $whId, $supId, $poId > 0 ? $poId : null, $doNo, $remark, $userId));
        $id = (int)$h['id'];
    }
    foreach ($lines as $ln) {
        WhQ("INSERT INTO supplier_return_dtl (return_hdr_id, material_id, qty_claim, defect_type, defect_note, evidence_ref)
             VALUES ($1,$2,$3,$4,$5,$6)", array($id, $ln['mid'], $ln['qty'], $ln['type'], $ln['note'], $ln['evid']));
    }
    WhQ('COMMIT');
    WhOk('Retur supplier tersimpan. No: ' . $refNo, array('id' => $id, 'ref_no' => $refNo));
}

// Kirim = buat issue W/H (SUPPLIER_RETURN) + stok keluar FIFO
function RtSend() {
    WhRequirePost();
    $id      = (int)WhPost('id');
    $date    = WhDate(WhPost('sent_date'), 'Tanggal kirim');
    $carrier = WhPost('carrier');
    $userId  = WhUserId();
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }

    WhQ('BEGIN');
    $h = WhRow('SELECT * FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'Diajukan') { throw new WhException('Hanya retur berstatus Diajukan yang dapat dikirim.'); }
    $whId = (int)$h['warehouse_id'];
    $dtl = WhRows('SELECT id, material_id, qty_claim, defect_type FROM supplier_return_dtl WHERE return_hdr_id=$1 ORDER BY material_id, id', array($id));
    if (!count($dtl)) { throw new WhException('Retur belum memiliki item.'); }

    $issRef = CreateRefNo('material_out_header', 'ref_no', 'RTS');
    $mo = WhRow("INSERT INTO material_out_header
                    (ref_no, project_id, warehouse_id, issue_date, issued_by, received_by_mandor, status, remark, issue_type)
                 VALUES ($1,$2,$3,$4,$5,$6,'posted',$7,$8) RETURNING id",
                array($issRef, (int)$h['project_id'], $whId, $date, $userId, $carrier,
                      'Retur supplier ' . $h['ref_no'], ISSUE_TYPE_RETURN));
    $moId = (int)$mo['id'];

    foreach ($dtl as $d) {
        $mid = (int)$d['material_id'];
        $qty = round((float)$d['qty_claim'], 4);
        WhLock($mid, $whId);
        $m = WhRow('SELECT material_name FROM materials WHERE id=$1', array($mid));
        $onhand = WhOnHand($mid, $whId);
        if ($qty > $onhand + 0.00005) {
            throw new WhException('Stok ' . $m['material_name'] . ' di gudang (' . WhFmt($onhand) .
                ') tidak cukup untuk dikirim (' . WhFmt($qty) . ').');
        }
        $tot = 0.0;
        foreach (WhFifoAllocate($mid, $whId, $qty) as $ly) {
            WhQ("INSERT INTO material_out_items (material_out_id, material_id, qty_out, unit_cost, is_overbudget, notes)
                 VALUES ($1,$2,$3,$4,'f',$5)",
                array($moId, $mid, $ly['qty'], round($ly['unit_cost'], 2), 'Retur cacat: ' . $d['defect_type']));
            WhPostMovement($date, $mid, $whId, 'OUT', $ly['qty'], $ly['unit_cost'], ISSUE_REF_TYPE, $moId, $issRef,
                           'Retur supplier ' . $h['ref_no'], $userId);
            $tot += $ly['qty'] * $ly['unit_cost'];
        }
        WhQ('UPDATE supplier_return_dtl SET unit_cost=$2 WHERE id=$1', array((int)$d['id'], round($tot / $qty, 4)));
    }
    WhQ("UPDATE supplier_return_hdr SET status='Dikirim', material_out_id=$2, sent_date=$3, carrier=$4, updated_at=now() WHERE id=$1",
        array($id, $moId, $date, $carrier));
    WhQ('COMMIT');
    WhOk('Retur dikirim. Issue W/H: ' . $issRef, array('issue_ref_no' => $issRef));
}

// Batal kirim: reverse issue W/H, retur kembali ke Diajukan
function RtUnsend() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status, material_out_id FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'Dikirim') { throw new WhException('Hanya retur berstatus Dikirim yang dapat dibatalkan pengirimannya.'); }
    $moId = (int)$h['material_out_id'];
    WhQ("UPDATE material_out_header SET status='reversed', reversed_at=now(), reversed_by=$2 WHERE id=$1", array($moId, WhUserId()));
    WhDeleteMovementsByRef(ISSUE_REF_TYPE, $moId);      // stok kembali ke gudang
    WhQ("UPDATE supplier_return_dtl SET unit_cost=0 WHERE return_hdr_id=$1", array($id));
    WhQ("UPDATE supplier_return_hdr SET status='Diajukan', material_out_id=NULL, sent_date=NULL, carrier=NULL, updated_at=now() WHERE id=$1",
        array($id));
    WhQ('COMMIT');
    WhOk('Pengiriman dibatalkan, stok dikembalikan ke gudang. Retur kembali berstatus Diajukan.');
}

// Selesaikan: Ganti Barang (barang pengganti diterima lewat RCV biasa) atau Kredit (nota kredit supplier)
function RtResolve() {
    WhRequirePost();
    $id    = (int)WhPost('id');
    $type  = WhPost('resolution_type');
    $date  = WhDate(WhPost('resolution_date'), 'Tanggal penyelesaian');
    $cnNo  = WhPost('credit_note_no');
    $cnAmt = WhNum(WhPost('credit_amount'));
    $note  = WhPost('resolution_note');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    if ($type !== 'Ganti Barang' && $type !== 'Kredit') { throw new WhException('Jenis penyelesaian harus Ganti Barang atau Kredit.'); }
    if ($type === 'Kredit' && ($cnNo === '' || $cnAmt <= 0)) {
        throw new WhException('Penyelesaian Kredit wajib mengisi no. nota kredit dan nilai kredit (> 0).');
    }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'Dikirim') { throw new WhException('Hanya retur berstatus Dikirim yang dapat diselesaikan.'); }
    $newStatus = ($type === 'Kredit') ? 'Kredit' : 'Diganti';
    WhQ("UPDATE supplier_return_hdr SET status=$2, resolution_type=$3, resolution_date=$4, credit_note_no=$5,
                credit_amount=$6, resolution_note=$7, updated_at=now() WHERE id=$1",
        array($id, $newStatus, $type, $date, $type === 'Kredit' ? $cnNo : null, $type === 'Kredit' ? $cnAmt : null, $note));
    WhQ('COMMIT');
    WhOk('Retur diselesaikan dengan status ' . $newStatus . '.');
}

function RtCancel() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'Diajukan') { throw new WhException('Hanya retur berstatus Diajukan yang dapat dibatalkan (untuk Dikirim gunakan Batal Kirim).'); }
    WhQ("UPDATE supplier_return_hdr SET status='Dibatalkan', updated_at=now() WHERE id=$1", array($id));
    WhQ('COMMIT');
    WhOk('Retur dibatalkan.');
}