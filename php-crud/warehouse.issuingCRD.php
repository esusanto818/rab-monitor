<?php
/**
 * warehouse.issuingCRD.php — Warehouse Issuing (material out ke proyek)
 *
 * Aturan issue per material (NEW):
 *   1. Material harus sudah di-RCV di gudang proyek (stok W/H > 0).
 *   2. qty issue <= stok di W/H.
 *   3. Total issue proyek (posted) + qty ini <= RAB qty_plan x (1 + 5%).
 *      Bila melewati qty RAB tapi masih dalam +5% -> is_overbudget = true (allowance terpakai).
 *   Nilai issue = FIFO dari layer RCV (stock_movements IN).
 *
 * [JURNAL][NEW] Issue ke proyek (issue_type = 'PROJECT') memposting jurnal otomatis lewat AutoPostJournal
 *   rule fiscal_config 'ISSUE_PROJECT' (default Dr WIP / Cr Persediaan Bahan Baku), reference_type = 'material_out',
 *   nilai = total FIFO (SUM qty_out x unit_cost). Gagal posting = issue dibatalkan (rollback).
 *   Reverse issue menghapus jurnalnya (ReverseJournalByReference).
 *   Issue Retur Supplier (issue_type = 'SUPPLIER_RETURN') TIDAK dijurnal di sini (diurus modul Retur Supplier).
 *   Catatan: modul ini memakai koneksi pg_* ($conn), AutoPostJournal memakai PDO (class crud) = koneksi terpisah.
 *   Jurnal diposting tepat sebelum COMMIT dokumen; bila jurnal gagal, dokumen di-ROLLBACK.
 *
 * Action: list | detail | lookup_projects | lookup_warehouses | lookup_materials | save | reverse |
 *         backfill_issue_journal ([JURNAL][NEW] sekali jalan: jurnal untuk issue lama yang belum berjurnal)
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }   // [JURNAL] AutoPostJournal membaca $_SESSION['user_id']

define('ISSUE_ALLOWANCE_PCT', 5.0);          // allowance di atas qty RAB (%)
define('ISSUE_REF_TYPE', 'MATERIAL_OUT');    // reference_type di stock_movements
define('ISSUE_JOURNAL_REF', 'material_out'); // [JURNAL][NEW] nilai enum journal_entries.reference_type
define('ISSUE_JOURNAL_RULE', 'ISSUE_PROJECT'); // [JURNAL][NEW] rule_code di fiscal_config

$action = WhReq('action', 'list');

try {
    switch ($action) {
        case 'list':              IssList(); break;
        case 'detail':            IssDetail(); break;
        case 'lookup_projects':   IssLookupProjects(); break;
        case 'lookup_warehouses': IssLookupWarehouses(); break;
        case 'lookup_materials':  IssLookupMaterials(); break;
        case 'save':              IssSave(); break;
        case 'reverse':           IssReverse(); break;
        case 'backfill_issue_journal': IssBackfillJournal(); break;   // [JURNAL][NEW]
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    @pg_query($conn, 'ROLLBACK');
    WhFail($e->getMessage());
} catch (Exception $e) {
    @pg_query($conn, 'ROLLBACK');
    WhLog('warehouse.issuingCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server. Hubungi administrator.');
}

/* ------------------------------------------------------------------ */
// [JURNAL][NEW] Koneksi PDO (class crud) untuk AutoPostJournal / ReverseJournalByReference
function IssJournalDb() {
    static $d = null;
    if ($d === null) { $d = new crud(); }
    return $d;
}

// [JURNAL][NEW] Posting jurnal issue proyek. Nilai <= 0 (mis. unit_cost belum terisi) -> tidak dijurnal.
function IssPostJournal($hdrId, $refNo, $projectId, $date, $total) {
    $total = round((float)$total, 2);
    if ($total <= 0.004) { return false; }
    $p = WhRow('SELECT name FROM projects WHERE id=$1', array((int)$projectId));
    $jr = AutoPostJournal(IssJournalDb(), array(
        'rule_code'      => ISSUE_JOURNAL_RULE,
        'reference_type' => ISSUE_JOURNAL_REF,
        'reference_id'   => (int)$hdrId,
        'journal_date'   => $date,
        'amount'         => $total,
        'description'    => 'Issue material ' . $refNo . ' - ' . ($p ? $p['name'] : ('proyek #' . $projectId)),
    ));
    if (!$jr['success']) { throw new WhException('Gagal posting jurnal issue: ' . $jr['message']); }
    return true;
}

// Info RAB & akumulasi issue proyek untuk satu material
function IssRabInfo($projectId, $materialId) {
    $rab = WhRow("SELECT COALESCE(SUM(qty_plan),0) AS q FROM budget_items
                  WHERE project_id=$1 AND material_id=$2", array((int)$projectId, (int)$materialId));
    $iss = WhRow("SELECT COALESCE(SUM(i.qty_out),0) AS q
                  FROM material_out_items i JOIN material_out_header h ON h.id=i.material_out_id
                  WHERE h.project_id=$1 AND i.material_id=$2 AND h.status='posted'
                    AND h.issue_type='PROJECT'", // [CHANGED] issue retur supplier tidak memakan kuota RAB
                 array((int)$projectId, (int)$materialId));
    return array('rab_qty' => round((float)$rab['q'], 4), 'issued' => round((float)$iss['q'], 4));
}

function IssList() {
    list($start, $limit) = WhPage();
    $q = WhReq('query');
    $params = array();
    $where = '1=1';
    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $where .= ' AND (h.ref_no ILIKE $1 OR p.name ILIKE $1 OR h.received_by_mandor ILIKE $1)';
    }
    $c = WhRow("SELECT COUNT(*) AS n FROM material_out_header h
                JOIN projects p ON p.id=h.project_id WHERE $where", $params);

    $params[] = $limit;  $pl = '$' . count($params);
    $params[] = $start;  $ps = '$' . count($params);
    $rows = WhRows("SELECT h.id, h.ref_no, h.project_id, p.name AS project_name,
                           h.warehouse_id, w.name AS warehouse_name, h.issue_date,
                           h.received_by_mandor, h.status, h.remark, h.issue_type,
                           COALESCE(t.total_amount,0) AS total_amount,
                           COALESCE(t.line_count,0)   AS line_count,
                           COALESCE(t.has_over,false) AS has_overbudget
                    FROM material_out_header h
                    JOIN projects   p ON p.id=h.project_id
                    JOIN warehouses w ON w.id=h.warehouse_id
                    LEFT JOIN (SELECT material_out_id, SUM(qty_out*unit_cost) AS total_amount,
                                      COUNT(*) AS line_count, BOOL_OR(is_overbudget) AS has_over
                               FROM material_out_items GROUP BY material_out_id) t ON t.material_out_id=h.id
                    WHERE $where
                    ORDER BY h.issue_date DESC, h.id DESC
                    LIMIT $pl OFFSET $ps", $params);
    foreach ($rows as &$r) { $r['has_overbudget'] = WhBool($r['has_overbudget']); }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => (int)$c['n'], 'data' => $rows));
}

function IssDetail() {
    $id = (int)WhReq('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    $rows = WhRows("SELECT i.id, i.material_id, m.material_code, m.material_name, m.unit,
                           i.qty_out, i.unit_cost, (i.qty_out*i.unit_cost) AS amount,
                           i.is_overbudget, i.overbudget_reason, i.notes
                    FROM material_out_items i JOIN materials m ON m.id=i.material_id
                    WHERE i.material_out_id=$1 ORDER BY i.id", array($id));
    foreach ($rows as &$r) { $r['is_overbudget'] = WhBool($r['is_overbudget']); }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function IssLookupProjects() {
    $q = WhReq('query');
    $rows = WhRows("SELECT id, name FROM projects WHERE name ILIKE $1 ORDER BY name LIMIT 100",
                   array('%' . $q . '%'));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// Hanya gudang milik proyek tsb (material proyek = stok di gudang proyek)
function IssLookupWarehouses() {
    $pid = (int)WhReq('project_id');
    $rows = WhRows("SELECT id, code, name FROM warehouses
                    WHERE active=true AND project_id=$1 ORDER BY name", array($pid));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// Material yang stoknya ada di gudang + kuota issue (min(stok, RAB+5% - sudah issue))
function IssLookupMaterials() {
    $pid = (int)WhReq('project_id');
    $wid = (int)WhReq('warehouse_id');
    $q   = WhReq('query');
    $rows = WhRows("SELECT m.id AS material_id, m.material_code, m.material_name, m.unit,
                           oh.onhand,
                           COALESCE(rab.rab_qty,0) AS rab_qty,
                           COALESCE(iss.issued,0)  AS issued
                    FROM materials m
                    JOIN (SELECT item_id, SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END) AS onhand
                          FROM stock_movements WHERE warehouse_id=$2 GROUP BY item_id) oh ON oh.item_id=m.id
                    LEFT JOIN (SELECT material_id, SUM(qty_plan) AS rab_qty FROM budget_items
                               WHERE project_id=$1 AND material_id IS NOT NULL GROUP BY material_id) rab
                           ON rab.material_id=m.id
                    LEFT JOIN (SELECT i.material_id, SUM(i.qty_out) AS issued
                               FROM material_out_items i JOIN material_out_header h ON h.id=i.material_out_id
                               WHERE h.project_id=$1 AND h.status='posted' AND h.issue_type='PROJECT'
                               GROUP BY i.material_id) iss
                           ON iss.material_id=m.id
                    WHERE oh.onhand > 0 AND (m.material_name ILIKE $3 OR m.material_code ILIKE $3)
                    ORDER BY m.material_name LIMIT 50",
                   array($pid, $wid, '%' . $q . '%'));
    foreach ($rows as &$r) {
        $rab = (float)$r['rab_qty'];
        $limit = round($rab * (1 + ISSUE_ALLOWANCE_PCT / 100), 4);
        $remain = max(0, round($limit - (float)$r['issued'], 4));
        $r['limit_qty'] = $limit;                                   // RAB + 5%
        $r['max_issue'] = ($rab > 0) ? min((float)$r['onhand'], $remain) : 0;   // tanpa RAB -> 0
    }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function IssSave() {
    WhRequirePost();
    $projectId = (int)WhPost('project_id');
    $whId      = (int)WhPost('warehouse_id');
    $date      = WhDate(WhPost('issue_date'), 'Tanggal issue');
    $mandor    = WhPost('received_by_mandor');
    $remark    = WhPost('remark');
    $details   = WhDetails();
    $userId    = WhUserId();

    if ($projectId < 1) { throw new WhException('Proyek wajib dipilih.'); }
    if ($whId < 1)      { throw new WhException('Gudang wajib dipilih.'); }

    $wh = WhRow('SELECT id, project_id, active FROM warehouses WHERE id=$1', array($whId));
    if (!$wh || !WhBool($wh['active'])) { throw new WhException('Gudang tidak ditemukan / tidak aktif.'); }
    if ((int)$wh['project_id'] !== $projectId) { throw new WhException('Gudang tidak terdaftar untuk proyek ini.'); }

    // Validasi & gabung baris duplikat per material
    $lines = array();
    foreach ($details as $d) {
        $mid = isset($d['material_id']) ? (int)$d['material_id'] : 0;
        $qty = isset($d['qty_out']) ? WhNum($d['qty_out']) : 0;
        if ($mid < 1) { throw new WhException('Material pada detail tidak valid.'); }
        if ($qty <= 0) { throw new WhException('Qty issue harus lebih besar dari 0.'); }
        if (!isset($lines[$mid])) { $lines[$mid] = array('qty' => 0, 'notes' => ''); }
        $lines[$mid]['qty'] = round($lines[$mid]['qty'] + $qty, 4);
        if (!empty($d['notes'])) { $lines[$mid]['notes'] = trim($d['notes']); }
    }

    WhQ('BEGIN');
    $refNo = CreateRefNo('material_out_header', 'ref_no', 'GI');
    $h = WhRow("INSERT INTO material_out_header
                    (ref_no, project_id, warehouse_id, issue_date, issued_by, received_by_mandor, status, remark)
                VALUES ($1,$2,$3,$4,$5,$6,'posted',$7) RETURNING id",
               array($refNo, $projectId, $whId, $date, $userId, $mandor, $remark));
    $hdrId = (int)$h['id'];

    foreach ($lines as $mid => $ln) {
        $qty = $ln['qty'];
        WhLock($mid, $whId);          // kunci stok gudang
        WhLock($mid, 0);              // kunci kuota RAB material lintas gudang
        $mat = WhRow('SELECT material_name FROM materials WHERE id=$1', array($mid));
        if (!$mat) { throw new WhException('Material ID ' . $mid . ' tidak ditemukan.'); }
        $name = $mat['material_name'];

        // (1)(2) harus sudah RCV & tidak melebihi stok W/H
        $onhand = WhOnHand($mid, $whId);
        if ($onhand <= 0) { throw new WhException("Material {$name} belum di-RCV / stok di gudang kosong."); }
        if ($qty > $onhand + 0.00005) {
            throw new WhException("Qty issue {$name} (" . WhFmt($qty) . ") melebihi stok di gudang (" . WhFmt($onhand) . ").");
        }
        // (3) RAB + allowance 5%
        $info = IssRabInfo($projectId, $mid);
        if ($info['rab_qty'] <= 0) { throw new WhException("Material {$name} tidak ada di RAB proyek ini."); }
        $limit = round($info['rab_qty'] * (1 + ISSUE_ALLOWANCE_PCT / 100), 4);
        $after = round($info['issued'] + $qty, 4);
        if ($after > $limit + 0.00005) {
            throw new WhException("Issue {$name} melebihi batas RAB +" . ISSUE_ALLOWANCE_PCT . "% (RAB " .
                WhFmt($info['rab_qty']) . ", batas " . WhFmt($limit) . ", sudah di-issue " . WhFmt($info['issued']) . ").");
        }
        $over   = ($after > $info['rab_qty'] + 0.00005);
        $reason = $over ? ('Allowance +' . ISSUE_ALLOWANCE_PCT . '% dari RAB') : null;

        // Nilai berdasarkan FIFO layer RCV -> satu baris item per layer agar biaya akurat
        $layers = WhFifoAllocate($mid, $whId, $qty);
        foreach ($layers as $ly) {
            WhQ("INSERT INTO material_out_items
                    (material_out_id, material_id, qty_out, unit_cost, is_overbudget, overbudget_reason, notes)
                 VALUES ($1,$2,$3,$4,$5,$6,$7)",
                array($hdrId, $mid, $ly['qty'], round($ly['unit_cost'], 2), $over ? 't' : 'f', $reason, $ln['notes']));
            WhPostMovement($date, $mid, $whId, 'OUT', $ly['qty'], $ly['unit_cost'],
                           ISSUE_REF_TYPE, $hdrId, $refNo, 'Issue ke proyek', $userId);
        }
    }

    // [JURNAL][NEW] posting jurnal issue (Dr WIP / Cr Persediaan, rule ISSUE_PROJECT) sebelum COMMIT; gagal -> ROLLBACK dokumen
    $tot = WhRow('SELECT COALESCE(SUM(qty_out*unit_cost),0) AS t FROM material_out_items WHERE material_out_id=$1', array($hdrId));
    IssPostJournal($hdrId, $refNo, $projectId, $date, $tot['t']);

    WhQ('COMMIT');
    WhOk('Issuing berhasil diposting. No: ' . $refNo, array('id' => $hdrId, 'ref_no' => $refNo));
}

function IssReverse() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status, issue_type FROM material_out_header WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'posted') { throw new WhException('Hanya dokumen berstatus posted yang dapat di-reverse.'); }
    // [CHANGED] issue retur supplier harus dibatalkan lewat Retur Supplier (Batal Kirim) agar status klaim ikut konsisten
    if ($h['issue_type'] !== 'PROJECT') { throw new WhException('Dokumen ini adalah issue Retur Supplier. Gunakan Batal Kirim di modul Retur Supplier.'); }
    WhQ("UPDATE material_out_header SET status='reversed', reversed_at=now(), reversed_by=$2 WHERE id=$1",
        array($id, WhUserId()));
    WhDeleteMovementsByRef(ISSUE_REF_TYPE, $id);   // stok kembali; kuota RAB ikut pulih (status bukan posted)
    ReverseJournalByReference(IssJournalDb(), ISSUE_JOURNAL_REF, $id);   // [JURNAL][NEW] hapus jurnal issue (issue lama tanpa jurnal -> 0 baris, aman)
    WhQ('COMMIT');
    WhOk('Dokumen issuing berhasil di-reverse.');
}

// [JURNAL][NEW] Backfill sekali jalan: jurnal untuk issue proyek posted yang belum berjurnal. Idempotent.
// Tiap issue diposting terpisah (koneksi PDO autocommit); bila satu gagal, proses berhenti & pesan menyebut nomor issue-nya,
// yang sudah terposting tetap tersimpan dan dilewati pada pemanggilan berikutnya.
function IssBackfillJournal() {
    if (intval(isset($_SESSION['FormAccess']) ? $_SESSION['FormAccess'] : 0) < 3) { throw new WhException('Akses ditolak.'); }
    $rows = WhRows("SELECT h.id, h.ref_no, h.project_id, h.issue_date,
                           COALESCE((SELECT SUM(i.qty_out*i.unit_cost) FROM material_out_items i WHERE i.material_out_id=h.id),0) AS total
                    FROM material_out_header h
                    WHERE h.status='posted' AND h.issue_type='PROJECT'
                      AND NOT EXISTS (SELECT 1 FROM journal_entries je
                                       WHERE je.reference_type::text=$1 AND je.reference_id=h.id)
                    ORDER BY h.issue_date ASC, h.id ASC", array(ISSUE_JOURNAL_REF));
    $done = array(); $skipped = array();
    foreach ($rows as $r) {
        if (IssPostJournal((int)$r['id'], $r['ref_no'], (int)$r['project_id'], $r['issue_date'], $r['total'])) {
            $done[] = $r['ref_no'];
        } else {
            $skipped[] = $r['ref_no'];     // nilai 0 -> tidak dijurnal
        }
    }
    $msg = count($done) . ' jurnal issue diposting' . (count($done) ? ': ' . implode(', ', $done) : '.');
    if (count($skipped)) { $msg .= ' | dilewati (nilai 0): ' . implode(', ', $skipped); }
    WhOk($msg, array('posted' => $done, 'skipped' => $skipped));
}