<?php
/**
 * grnCRD.php
 * ============================================================
 * Backend untuk grn.js (Proses GRN tanpa Simpan Warehouse).
 * Kontrak task: list_po_open, read_po_items, read, 
 *               read_grn_items, read_edit, create, update, delete
 *
 * MULTI CURRENCY (sql/2026-10-01.grn_currency.sql):
 *  - GRN membekukan mata uang & kurs PO (grn_hdr.currency_id / exch_rate).
 *  - grn_dtl.unit_cost      = harga per unit dalam MATA UANG PO (bisa diedit saat GRN).
 *  - grn_dtl.unit_cost_base = unit_cost x kurs PO = MATA UANG DASAR (dihitung server, bukan dari klien);
 *    inilah yang dipakai WH Rcv untuk FIFO, stok, dan jurnal.
 *  - GRN hanya boleh dibuat dari PO berstatus Disetujui (GRN_PO_STATUS_LIST). PO Draft/Diajukan/Dibatalkan ditolak.
 * ============================================================
 */

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

session_start();
header('Content-Type: application/json');

const GRN_PO_STATUS_LIST = ['Disetujui'];     // status PO yang boleh di-GRN

$task = $_REQUEST['task'] ?? '';
$db = new crud();

function PgOrFail($result, $context = '') {
    if ($result === false) {
        throw new Exception(($context ? "[{$context}] " : '') . pg_last_error());
    }
    return $result;
}

/** Kode mata uang dasar (Pengaturan Sistem); belum diatur -> IDR. Hanya untuk tampilan. */
function GrnBaseCode() {
    $r = pg_query("SELECT to_regclass('public.finance_settings') AS t");
    if ($r && ($row = pg_fetch_assoc($r)) && $row['t']) {
        $q = pg_query("SELECT UPPER(TRIM(c.init)) AS code FROM finance_settings f
                         JOIN m_currency c ON c.id = f.base_currency_id WHERE f.id = 1");
        if ($q && ($rw = pg_fetch_assoc($q)) && $rw['code']) return $rw['code'];
    }
    return 'IDR';
}

/** Info mata uang untuk tampilan: dari snapshot GRN bila $grn_id > 0, selain itu dari PO. */
function GrnCurrencyInfo($po_id, $grn_id) {
    if ($grn_id > 0) {
        $res = pg_query_params("SELECT UPPER(TRIM(c.init)) AS code, COALESCE(NULLIF(g.exch_rate,0),1) AS exch_rate
                                  FROM grn_hdr g LEFT JOIN m_currency c ON c.id = g.currency_id WHERE g.id = $1", [$grn_id]);
    } else {
        $res = pg_query_params("SELECT UPPER(TRIM(c.init)) AS code, COALESCE(NULLIF(p.exch_rate,0),1) AS exch_rate
                                  FROM purchase_orders p LEFT JOIN m_currency c ON c.id = p.currency_id WHERE p.id = $1", [$po_id]);
    }
    PgOrFail($res, 'Gagal load mata uang');
    $row = pg_fetch_assoc($res) ?: [];
    $base = GrnBaseCode();
    return ['code' => $row['code'] ?: $base, 'base_code' => $base, 'exch_rate' => (float)($row['exch_rate'] ?? 1)];
}

function GetPoDtlTolerance($po_dtl_id, $po_qty, $exclude_grn_hdr_id = 0) {
    // Total Qty yang sudah diterima GRN lain untuk item PO ini
    $sql = "SELECT COALESCE(SUM(qty_received),0) AS received_other
            FROM grn_dtl 
            WHERE po_item_id = $1 AND grn_hdr_id <> $2";
    $res = pg_query_params($sql, [$po_dtl_id, (int)$exclude_grn_hdr_id]);
    PgOrFail($res, 'Gagal cek akumulasi qty GRN');
    $row = pg_fetch_assoc($res);
    $receivedOther = (float)($row['received_other'] ?? 0);

    $qtyMaxAllowed = round($po_qty * 1.10, 4);
    $qtyReceivableMax = max(0, $qtyMaxAllowed - $receivedOther);

    return [
        'others_qty'         => $receivedOther,
        'qty_max_allowed'    => $qtyMaxAllowed,
        'qty_receivable_max' => $qtyReceivableMax
    ];
}

try {
    switch ($task) {

        case 'list_po_open':
            // HANYA MENGAMBIL PO YANG MEMILIKI SISA QTY BELUM DI-GRN
            $sql = "SELECT p.id, p.po_number AS ref_no, p.po_date AS tx_date, 
                           p.supplier_id, 
                           COALESCE(sup.id, p.supplier_id) AS legacy_supplier_id, 
                           sup.name AS supplier_name,
                           p.currency_id, UPPER(TRIM(cur.init)) AS currency_code,
                           COALESCE(NULLIF(p.exch_rate,0),1) AS exch_rate
                    FROM purchase_orders p
                    LEFT JOIN m_suppliers sup ON sup.id = p.supplier_id
                    LEFT JOIN m_currency cur ON cur.id = p.currency_id
                    JOIN po_items pd ON pd.po_id = p.id
                    LEFT JOIN (
                        SELECT po_item_id, SUM(qty_received) AS total_rcv
                        FROM grn_dtl
                        GROUP BY po_item_id
                    ) g ON g.po_item_id = pd.id
                    WHERE p.status IN ('" . implode("','", GRN_PO_STATUS_LIST) . "')
                    GROUP BY p.id, p.po_number, p.po_date, p.supplier_id, sup.id, sup.name, p.currency_id, cur.init, p.exch_rate
                    HAVING SUM(pd.qty) - COALESCE(SUM(g.total_rcv), 0) > 0.0001
                    ORDER BY p.po_date DESC";
            $result = pg_query($sql);
            PgOrFail($result, 'Gagal load daftar PO');
            echo json_encode(['success' => true, 'data' => pg_fetch_all($result) ?: []]);
            break;

        case 'read_po_items':
            $po_id  = (int)($_REQUEST['po_id'] ?? 0);
            $grn_id = (int)($_REQUEST['grn_id'] ?? 0);

            $sql = "SELECT pd.id AS po_dtl_id, pd.material_id AS item_id, pd.description AS item_name, 
                           pd.qty AS qty_ordered, pd.unit_price
                    FROM po_items pd
                    WHERE pd.po_id = $1
                    ORDER BY pd.id";
            $result = pg_query_params($sql, [$po_id]);
            PgOrFail($result, 'Gagal load detail PO');
            $rows = pg_fetch_all($result) ?: [];

            $data = [];
            foreach ($rows as $r) {
                $poQty = (float)$r['qty_ordered'];
                $tol   = GetPoDtlTolerance($r['po_dtl_id'], $poQty, $grn_id);

                // Cek apakah item ini milik GRN yang sedang diedit
                $ownResult = pg_query_params(
                    "SELECT qty_received, unit_cost FROM grn_dtl WHERE po_item_id = $1 AND grn_hdr_id = $2",
                    [$r['po_dtl_id'], $grn_id]
                );
                PgOrFail($ownResult, 'Gagal cek baris grn_dtl existing');
                $own = pg_fetch_assoc($ownResult);

                // Hitung sisa murni PO (PO_Qty - Received_Others)
                $sisaQtyPure = max(0, $poQty - $tol['others_qty']);

                // Sisa maksimal yang diizinkan (Toleransi 10%)
                $sisaQtyBolehditerima = $tol['qty_receivable_max'];

                // Jika Sisa Qty Murni <= 0 dan bukan dalam mode Edit item terkait, skip (jangan tampilkan)
                if ($sisaQtyPure <= 0.0001 && !$own) {
                    continue;
                }

                $data[] = [
                    'po_dtl_id'             => $r['po_dtl_id'],
                    'item_id'               => $r['item_id'],
                    'item_name'             => $r['item_name'],
                    'qty_ordered'           => $poQty,
                    'unit_price'            => (float)$r['unit_price'],
                    'qty_received'          => $tol['others_qty'],
                    'sisa_qty_po'           => $sisaQtyPure,
                    'qty_max_allowed'       => $tol['qty_max_allowed'],
                    'qty_receivable_max'    => $sisaQtyBolehditerima,
                    'qty_received_this_grn' => $own ? (float)$own['qty_received'] : $sisaQtyPure,
                    'unit_cost_this_grn'    => $own ? (float)$own['unit_cost'] : (float)$r['unit_price']
                ];
            }

            echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data,
                              'currency' => GrnCurrencyInfo($po_id, $grn_id)]);
            break;

        case 'read':
            $sql = "SELECT g.id, g.ref_no, g.grn_date, g.status, g.remark,
                           p.po_number AS po_ref_no, g.po_id, s.id AS supplier_id, s.name AS supplier_name,
                           (SELECT COALESCE(SUM(qty_received),0) FROM grn_dtl d WHERE d.grn_hdr_id = g.id) AS total_qty,
                           (SELECT COALESCE(SUM(qty_received * unit_cost),0) FROM grn_dtl d WHERE d.grn_hdr_id = g.id) AS total_amount,
                           (SELECT COALESCE(SUM(qty_received * unit_cost_base),0) FROM grn_dtl d WHERE d.grn_hdr_id = g.id) AS total_base,
                           UPPER(TRIM(cur.init)) AS currency_code, COALESCE(NULLIF(g.exch_rate,0),1) AS exch_rate
                    FROM grn_hdr g
                    LEFT JOIN purchase_orders p ON p.id = g.po_id
                    LEFT JOIN m_suppliers s ON s.id = g.supplier_id
                    LEFT JOIN m_currency cur ON cur.id = g.currency_id
                    ORDER BY g.grn_date DESC, g.id DESC";
            $result = pg_query($sql);
            PgOrFail($result, 'Gagal load daftar GRN');
            $all = pg_fetch_all($result) ?: [];
            echo json_encode(['success' => true, 'totalCount' => count($all), 'data' => $all]);
            break;

        case 'read_grn_items':
            $grn_id = (int)($_REQUEST['grn_id'] ?? 0);
            $sql = "SELECT item_name, qty_received AS qty, unit_cost AS unit_price, unit_cost_base
                    FROM grn_dtl WHERE grn_hdr_id = $1 ORDER BY id";
            $result = pg_query_params($sql, [$grn_id]);
            PgOrFail($result, 'Gagal load item GRN');
            echo json_encode(['success' => true, 'data' => pg_fetch_all($result) ?: [],
                              'currency' => GrnCurrencyInfo(0, $grn_id)]);
            break;

        case 'read_edit':
            $id = (int)($_REQUEST['id'] ?? 0);
            $hdrResult = pg_query_params("SELECT * FROM grn_hdr WHERE id = $1", [$id]);
            PgOrFail($hdrResult, 'Gagal load GRN');
            $hdr = pg_fetch_assoc($hdrResult);
            if (!$hdr) throw new Exception('Data tidak ditemukan');
            echo json_encode(['success' => true, 'data' => $hdr]);
            break;

        case 'create':
            $po_id       = (int)($_REQUEST['po_id'] ?? 0);
            $supplier_id = (int)($_REQUEST['supplier_id'] ?? 0);
            $grn_date    = $_REQUEST['grn_date'] ?? date('Y-m-d');
            $remark      = $_REQUEST['remark'] ?? null;
            $arrItems    = json_decode($_REQUEST['arrItems'] ?? '[]', true);
            $created_by  = $_SESSION['user_id'] ?? null;

            if (!$po_id) throw new Exception('PO belum dipilih');

            if (!$supplier_id) {
                $supRes = pg_query_params("SELECT supplier_id FROM purchase_orders WHERE id = $1", [$po_id]);
                if ($supRes && $supRow = pg_fetch_assoc($supRes)) {
                    $supplier_id = (int)$supRow['supplier_id'];
                }
            }

            if (!$supplier_id) throw new Exception('Supplier tidak ditemukan dari PO');
            if (empty($arrItems)) throw new Exception('Minimal 1 item harus diisi qty diterima');

            // PO harus berstatus Disetujui; mata uang & kurs PO dibekukan di GRN
            $poRes = pg_query_params("SELECT status, currency_id, COALESCE(NULLIF(exch_rate,0),1) AS exch_rate
                                        FROM purchase_orders WHERE id = $1", [$po_id]);
            PgOrFail($poRes, 'Gagal cek PO');
            $poRow = pg_fetch_assoc($poRes);
            if (!$poRow) throw new Exception('PO tidak ditemukan');
            if (!in_array($poRow['status'], GRN_PO_STATUS_LIST, true)) {
                throw new Exception('GRN hanya dapat dibuat dari PO berstatus ' . implode('/', GRN_PO_STATUS_LIST) .
                                    ' (status PO saat ini: ' . $poRow['status'] . ').');
            }
            $po_currency = $poRow['currency_id'] !== null ? (int)$poRow['currency_id'] : null;
            $po_rate     = (float)$poRow['exch_rate'];

            pg_query('BEGIN');

            $ref_no = CreateRefNo('grn_hdr', 'ref_no', 'GRN');

            $hdrResult = pg_query_params(
                "INSERT INTO grn_hdr (ref_no, grn_date, po_id, supplier_id, status, remark, created_by, created_at, currency_id, exch_rate)
                 VALUES ($1,$2,$3,$4,'open',$5,$6,NOW(),$7,$8) RETURNING id",
                [$ref_no, $grn_date, $po_id, $supplier_id, $remark, $created_by, $po_currency, $po_rate]
            );
            PgOrFail($hdrResult, 'Gagal insert grn_hdr');
            $grn_hdr_id = pg_fetch_result($hdrResult, 0, 'id');

            InsertGrnItems($grn_hdr_id, $arrItems, 0, $po_rate);

            pg_query('COMMIT');
            echo json_encode(['success' => true, 'message' => "GRN {$ref_no} berhasil disimpan.", 'id' => $grn_hdr_id, 'ref_no' => $ref_no]);
            break;

        case 'update':
            $id          = (int)($_REQUEST['id'] ?? 0);
            $po_id       = (int)($_REQUEST['po_id'] ?? 0);
            $supplier_id = (int)($_REQUEST['supplier_id'] ?? 0);
            $grn_date    = $_REQUEST['grn_date'] ?? date('Y-m-d');
            $remark      = $_REQUEST['remark'] ?? null;
            $arrItems    = json_decode($_REQUEST['arrItems'] ?? '[]', true);

            if (!$id) throw new Exception('GRN tidak valid');

            if (!$supplier_id && $po_id) {
                $supRes = pg_query_params("SELECT supplier_id FROM purchase_orders WHERE id = $1", [$po_id]);
                if ($supRes && $supRow = pg_fetch_assoc($supRes)) {
                    $supplier_id = (int)$supRow['supplier_id'];
                }
            }

            if (!$supplier_id) throw new Exception('Supplier belum dipilih');
            if (empty($arrItems)) throw new Exception('Minimal 1 item harus diisi qty diterima');

            $hRes = pg_query_params("SELECT COALESCE(NULLIF(exch_rate,0),1) AS exch_rate FROM grn_hdr WHERE id = $1", [$id]);
            PgOrFail($hRes, 'Gagal load kurs GRN');
            $hRow = pg_fetch_assoc($hRes);
            if (!$hRow) throw new Exception('GRN tidak ditemukan');
            $grn_rate = (float)$hRow['exch_rate'];                       // kurs snapshot GRN tidak berubah saat edit

            pg_query('BEGIN');

            GuardNoWhReceiving($id, 'diedit');

            $delResult = pg_query_params("DELETE FROM grn_dtl WHERE grn_hdr_id = $1", [$id]);
            PgOrFail($delResult, 'Gagal hapus baris lama grn_dtl');

            $upd = pg_query_params(
                "UPDATE grn_hdr SET supplier_id = $1, grn_date = $2, remark = $3 WHERE id = $4",
                [$supplier_id, $grn_date, $remark, $id]
            );
            PgOrFail($upd, 'Gagal update grn_hdr');

            InsertGrnItems($id, $arrItems, $id, $grn_rate);

            pg_query('COMMIT');
            echo json_encode(['success' => true, 'message' => 'GRN berhasil diupdate.', 'id' => $id]);
            break;

        case 'delete':
            $id = (int)($_REQUEST['id'] ?? 0);
            if (!$id) throw new Exception('GRN tidak valid');

            pg_query('BEGIN');

            GuardNoWhReceiving($id, 'dihapus');

            $delDtl = pg_query_params("DELETE FROM grn_dtl WHERE grn_hdr_id = $1", [$id]);
            PgOrFail($delDtl, 'Gagal hapus grn_dtl');

            $delHdr = pg_query_params("DELETE FROM grn_hdr WHERE id = $1", [$id]);
            PgOrFail($delHdr, 'Gagal hapus grn_hdr');

            pg_query('COMMIT');
            echo json_encode(['success' => true, 'message' => 'GRN berhasil dihapus.']);
            break;

        default:
            throw new Exception('Task tidak dikenali: ' . htmlspecialchars($task));
    }
} catch (Exception $e) {
    @pg_query('ROLLBACK');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function InsertGrnItems($grn_hdr_id, $arrItems, $exclude_grn_hdr_id, $rate = 1.0) {
    $any_line = false;

    foreach ($arrItems as $it) {
        $qty = (float)($it['qty_received'] ?? 0);
        if ($qty <= 0) continue;

        $poResult = pg_query_params("SELECT qty AS po_qty FROM po_items WHERE id = $1", [$it['po_dtl_id']]);
        PgOrFail($poResult, 'Gagal cek qty PO');
        $poRow = pg_fetch_assoc($poResult);
        if (!$poRow) throw new Exception('Baris PO tidak ditemukan (po_dtl_id=' . $it['po_dtl_id'] . ')');

        $tol = GetPoDtlTolerance($it['po_dtl_id'], (float)$poRow['po_qty'], $exclude_grn_hdr_id);
        if ($qty > $tol['qty_receivable_max'] + 0.0001) {
            throw new Exception("Qty GRN untuk {$it['item_name']} ({$qty}) melebihi batas toleransi +10% PO (sisa maksimal: " . round($tol['qty_receivable_max'], 4) . ")");
        }

        $dtlResult = pg_query_params(
            "INSERT INTO grn_dtl (grn_hdr_id, po_item_id, item_id, item_name, qty_received, unit_cost, unit_cost_base, remark)
             VALUES ($1,$2,$3,$4,$5,$6,$7,$8)",
            [
                $grn_hdr_id, $it['po_dtl_id'], $it['item_id'], $it['item_name'],
                $qty, $it['unit_cost'], round((float)$it['unit_cost'] * $rate, 4), $it['remark'] ?? null
            ]
        );
        PgOrFail($dtlResult, 'Gagal insert grn_dtl');

        $any_line = true;
    }

    if (!$any_line) {
        throw new Exception('Tidak ada baris dengan qty > 0');
    }
}

function GuardNoWhReceiving($grn_hdr_id, $actionLabel) {
    $chkResult = pg_query_params(
        "SELECT COALESCE(SUM(d.qty_received),0) AS total_wh
         FROM wh_receiving_dtl d
         JOIN wh_receiving_hdr h ON h.id = d.wh_receiving_hdr_id AND h.status = 'posted'
         JOIN grn_dtl gd ON gd.id = d.grn_dtl_id
         WHERE gd.grn_hdr_id = $1",
        [$grn_hdr_id]
    );
    PgOrFail($chkResult, 'Gagal cek qty yang sudah di-WH Rcv');
    $chk = pg_fetch_assoc($chkResult);
    if ((float)$chk['total_wh'] > 0) {
        throw new Exception("GRN tidak bisa {$actionLabel} -- sudah ada WH Rcv terkait. Reverse WH Rcv-nya dulu.");
    }
}