<?php
/* Document      : purchaseinvoiceCRD.php
   Description   : Backend CRUD untuk Purchase Invoice
   [FIX] - Tambah pengecekan koneksi DB eksplisit + fallback pesan error
           supaya tidak pernah tampil pesan kosong ke frontend.
         - JOIN supplier diperbaiki: m_suppliers (bukan clients), sesuai FK asli.
         - purchase_invoice_dtl: nama kolom diperbaiki ke invoice_hdr_id /
           grn_hdr_id (sesuai skema asli, sebelumnya invoice_id / grn_dtl_id
           yang TIDAK ADA di tabel -> selalu gagal saat detail dibaca/disimpan).
         - read_invoice_detail: karena grn_hdr_id mengarah ke grn_hdr (level
           dokumen, bukan level item), di-JOIN + GROUP BY ke grn_dtl supaya
           qty/harga/nama item tetap bisa ditampilkan.
   [BARU 2026-10-08]
         - task 'read_invoice_print': data Tanda Terima Invoice (read-only, tidak mengubah data).
         - create: kolom created_by ikut diisi dari sesi (kolom sudah ada di tabel, sebelumnya kosong)
           supaya nama penerima tampil di cetakan. */

session_start();

if (file_exists('../php-func/function.all.php')) {
    require_once('../php-func/function.all.php');
}

$task = isset($_POST['task']) ? $_POST['task'] : (isset($_GET['task']) ? $_GET['task'] : '');

@ini_set('display_errors', 0);
header('Content-Type: application/json; charset=UTF-8');

// [FIX] Pengecekan koneksi eksplisit. Sebelumnya kode di sini hanya
// mengecek function_exists('pg_connect') === false, yang praktis tidak
// pernah true (pg_connect selalu ada kalau ekstensi pgsql terpasang),
// jadi baris pg_connect() manual di bawahnya tidak pernah jalan. Kalau
// function.all.php gagal membuka koneksi, pg_query() di bawah akan gagal
// TANPA pesan (pg_last_error() bisa balik string kosong kalau memang
// belum ada koneksi aktif sama sekali) -> di frontend muncul pesan
// default "Gagal memuat data main grid." tanpa detail.
if (!function_exists('pg_query')) {
    echo json_encode([
        'success' => false,
        'message' => 'Ekstensi PostgreSQL (pgsql) tidak tersedia di server.'
    ]);
    exit;
}

$testConn = @pg_query("SELECT 1");
if (!$testConn) {
    echo json_encode([
        'success' => false,
        'message' => 'Tidak ada koneksi database aktif. Cek path/isi function.all.php (../php-func/function.all.php) dan pastikan pg_connect() berhasil dipanggil sebelum file ini dieksekusi.'
    ]);
    exit;
}

// [FIX] Helper supaya pesan error TIDAK PERNAH kosong ke frontend.
function pgErrorOrFallback($fallback = 'Terjadi kesalahan pada database.') {
    $err = pg_last_error();
    return $err !== '' ? $err : $fallback;
}

try {
    switch ($task) {
        case 'read':
            $limit  = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
            $start  = isset($_POST['start']) ? intval($_POST['start']) : 0;

            $sqlTotal = "SELECT COUNT(*) AS total FROM purchase_invoice_hdr";
            $resTotal = pg_query($sqlTotal);
            if (!$resTotal) throw new Exception(pgErrorOrFallback('Gagal menghitung total data invoice.'));

            $rowTotal = pg_fetch_assoc($resTotal);
            $totalCount = intval($rowTotal['total']);

            // [FIX] supplier_id FK sebenarnya mengarah ke m_suppliers(id),
            // bukan clients(id) — sebelumnya JOIN ke clients membuat
            // supplier_name selalu kosong/salah.
            $sql = "SELECT h.*, s.name AS supplier_name
                    FROM purchase_invoice_hdr h
                    LEFT JOIN m_suppliers s ON s.id = h.supplier_id
                    ORDER BY h.id DESC LIMIT $limit OFFSET $start";
            $result = pg_query($sql);
            if (!$result) throw new Exception(pgErrorOrFallback('Gagal memuat data main grid.'));

            $data = [];
            while ($row = pg_fetch_assoc($result)) {
                $row['total_amount'] = floatval(isset($row['total_amount']) ? $row['total_amount'] : 0);
                $row['paid_amount'] = floatval(isset($row['paid_amount']) ? $row['paid_amount'] : 0);
                $data[] = $row;
            }

            echo json_encode([
                'success' => true,
                'totalCount' => $totalCount,
                'data' => $data
            ]);
            break;

        case 'read_available_grn':
            // [BARU] Daftar GRN (grn_hdr) yang bisa dipilih untuk di-match ke invoice.
            // Difilter berdasarkan supplier yang sama dengan invoice, status masih
            // open/partial. Agregasi qty/harga dari grn_dtl (lihat catatan di
            // read_invoice_detail soal grn_hdr_id mengarah ke level dokumen).
            $supplier_id = isset($_REQUEST['supplier_id']) ? intval($_REQUEST['supplier_id']) : 0;

            $sql = "SELECT h.id AS grn_hdr_id, h.ref_no AS grn_ref_no, h.grn_date, h.supplier_id,
                           string_agg(DISTINCT gd.item_name, ', ') AS item_name,
                           SUM(gd.qty_received) AS qty_received,
                           CASE WHEN SUM(gd.qty_received) > 0
                                THEN SUM(gd.qty_received * gd.unit_cost) / SUM(gd.qty_received)
                                ELSE 0 END AS unit_cost,
                           SUM(gd.qty_received * gd.unit_cost) AS grn_total,
                           COALESCE(im.already_matched, 0) AS already_matched,
                           SUM(gd.qty_received * gd.unit_cost) - COALESCE(im.already_matched, 0) AS remaining
                    FROM grn_hdr h
                    JOIN grn_dtl gd ON gd.grn_hdr_id = h.id
                    LEFT JOIN (
                        SELECT grn_hdr_id, SUM(matched_amount) AS already_matched
                        FROM purchase_invoice_dtl
                        GROUP BY grn_hdr_id
                    ) im ON im.grn_hdr_id = h.id
                    WHERE h.status IN ('open', 'partial')
                      AND ($1 = 0 OR h.supplier_id = $1)
                    GROUP BY h.id, h.ref_no, h.grn_date, h.supplier_id, im.already_matched
                    -- [BARU] Invoicing partial dibolehkan: GRN tetap muncul selama
                    -- masih ada sisa nilai yang belum di-match ke invoice manapun.
                    HAVING SUM(gd.qty_received * gd.unit_cost) - COALESCE(im.already_matched, 0) > 0
                    ORDER BY h.grn_date DESC";

            $result = pg_query_params($sql, [$supplier_id]);
            if (!$result) throw new Exception(pgErrorOrFallback('Gagal memuat daftar GRN.'));

            $data = [];
            while ($row = pg_fetch_assoc($result)) {
                $row['qty_received'] = floatval($row['qty_received']);
                $row['unit_cost'] = floatval($row['unit_cost']);
                $row['grn_total'] = floatval($row['grn_total']);
                $row['already_matched'] = floatval($row['already_matched']);
                $row['remaining'] = floatval($row['remaining']);
                $data[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'read_supplier':
            // [FIX] konsisten pakai m_suppliers juga di sini.
            $sql = "SELECT id, name FROM m_suppliers WHERE active IS NOT FALSE ORDER BY name ASC";
            $result = pg_query($sql);
            if (!$result) throw new Exception(pgErrorOrFallback('Gagal memuat daftar supplier.'));
            $data = [];
            while ($row = pg_fetch_assoc($result)) {
                $data[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'read_invoice_detail':
            $invoice_id = isset($_REQUEST['invoice_id']) ? intval($_REQUEST['invoice_id']) : 0;

            // [FIX] Kolom sebelumnya (d.invoice_id, d.grn_dtl_id) tidak ada
            // di tabel purchase_invoice_dtl -> query selalu gagal.
            // grn_hdr_id mengarah ke grn_hdr (dokumen), jadi di-JOIN +
            // GROUP BY ke grn_dtl untuk tetap dapat qty/harga/nama item.
            $sql = "SELECT d.id, d.invoice_hdr_id, d.grn_hdr_id, d.matched_amount, d.remark,
                           h.ref_no AS grn_ref_no,
                           string_agg(DISTINCT gd.item_name, ', ') AS item_name,
                           SUM(gd.qty_received) AS qty_received,
                           CASE WHEN SUM(gd.qty_received) > 0
                                THEN SUM(gd.qty_received * gd.unit_cost) / SUM(gd.qty_received)
                                ELSE 0 END AS unit_cost,
                           SUM(gd.qty_received * gd.unit_cost) AS grn_total
                    FROM purchase_invoice_dtl d
                    JOIN grn_hdr h ON h.id = d.grn_hdr_id
                    LEFT JOIN grn_dtl gd ON gd.grn_hdr_id = h.id
                    WHERE d.invoice_hdr_id = $1
                    GROUP BY d.id, d.invoice_hdr_id, d.grn_hdr_id, d.matched_amount, d.remark, h.ref_no";

            $result = pg_query_params($sql, [$invoice_id]);
            if (!$result) throw new Exception(pgErrorOrFallback('Gagal memuat detail invoice.'));

            $data = [];
            while ($row = pg_fetch_assoc($result)) {
                $row['matched_amount'] = floatval($row['matched_amount']);
                $row['qty_received'] = floatval($row['qty_received']);
                $row['unit_cost'] = floatval($row['unit_cost']);
                $row['grn_total'] = floatval($row['grn_total']);
                $data[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'read_invoice_print':
            // [BARU] Data Tanda Terima Invoice (header + GRN yang di-match) untuk dicetak.
            $invoice_id = isset($_REQUEST['invoice_id']) ? intval($_REQUEST['invoice_id']) : 0;
            if ($invoice_id <= 0) throw new Exception('Invoice tidak valid.');

            $sqlH = "SELECT h.id, h.inv_no, h.supplier_invoice_no, h.invoice_date, h.created_at, h.status,
                            h.remark, h.total_amount, s.name AS supplier_name,
                            COALESCE(e.employee_name, u.login_name) AS created_by_name
                     FROM purchase_invoice_hdr h
                     LEFT JOIN m_suppliers s ON s.id = h.supplier_id
                     LEFT JOIN m_user_system u ON u.id = h.created_by
                     LEFT JOIN m_employee e ON e.id = u.employee_id
                     WHERE h.id = $1";
            $resH = pg_query_params($sqlH, [$invoice_id]);
            if (!$resH) throw new Exception(pgErrorOrFallback('Gagal memuat header invoice.'));
            $hdr = pg_fetch_assoc($resH);
            if (!$hdr) throw new Exception('Invoice tidak ditemukan.');
            $hdr['total_amount'] = floatval($hdr['total_amount']);

            $sqlD = "SELECT d.id, d.grn_hdr_id, d.matched_amount, d.remark,
                            g.ref_no AS grn_ref_no, g.grn_date,
                            p.po_number AS po_ref_no,
                            UPPER(TRIM(cur.init)) AS currency_code,
                            (SELECT string_agg(DISTINCT gd.item_name, ', ') FROM grn_dtl gd WHERE gd.grn_hdr_id = g.id) AS item_name
                     FROM purchase_invoice_dtl d
                     JOIN grn_hdr g ON g.id = d.grn_hdr_id
                     LEFT JOIN purchase_orders p ON p.id = g.po_id
                     LEFT JOIN m_currency cur ON cur.id = g.currency_id
                     WHERE d.invoice_hdr_id = $1
                     ORDER BY d.id";
            $resD = pg_query_params($sqlD, [$invoice_id]);
            if (!$resD) throw new Exception(pgErrorOrFallback('Gagal memuat rincian invoice.'));
            $items = [];
            while ($row = pg_fetch_assoc($resD)) {
                $row['matched_amount'] = floatval($row['matched_amount']);
                $items[] = $row;
            }
            echo json_encode(['success' => true, 'data' => ['header' => $hdr, 'items' => $items]]);
            break;

        case 'create':
        case 'update':
            pg_query("BEGIN");

            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            // [FIX] inv_no sekarang No. Ref Dokumen yang di-generate sistem,
            // bukan input user -> field ini sudah disabled di JS, jadi
            // $_POST['inv_no'] diabaikan sepenuhnya di sini.
            $supplier_invoice_no = isset($_POST['supplier_invoice_no']) ? $_POST['supplier_invoice_no'] : '';
            $invoice_date = isset($_POST['invoice_date']) ? $_POST['invoice_date'] : date('Y-m-d');
            $supplier_id = isset($_POST['supplier_id']) ? intval($_POST['supplier_id']) : 0;
            $remark = isset($_POST['remark']) ? $_POST['remark'] : '';

            $invoice_details = isset($_POST['invoice_details']) ? json_decode($_POST['invoice_details'], true) : [];

            if ($task == 'create') {
                // [FIX] Sebelumnya manggil CreateRefNo('PINV', 'purchase_invoice_hdr', 'inv_no')
                // dengan asumsi urutan param (prefix, table, column) -> ternyata SALAH.
                // Dari log server: fungsi ini pakai param pertama sebagai NAMA TABEL
                // ("FROM PINV") dan param kedua sebagai nama kolom di substring() ->
                // query di dalam CreateRefNo() error "relation pinv does not exist",
                // $inv_no jadi kosong/null, lalu INSERT gagal (kolom NOT NULL).
                // Sampai urutan param yang benar dikonfirmasi, generate ref no sendiri
                // di sini saja (self-contained, tidak bergantung fungsi lain).
                $prefix = 'PINV-' . date('ym') . '-';
                $sqlSeq = "SELECT COUNT(*) + 1 AS next_seq FROM purchase_invoice_hdr WHERE inv_no LIKE $1";
                $resSeq = pg_query_params($sqlSeq, [$prefix . '%']);
                if (!$resSeq) throw new Exception(pgErrorOrFallback('Gagal generate No. Ref Dokumen.'));
                $rowSeq = pg_fetch_assoc($resSeq);
                $inv_no = $prefix . str_pad($rowSeq['next_seq'], 5, '0', STR_PAD_LEFT);

                // [CHG] + created_by (kolom sudah ada di tabel, sebelumnya tidak diisi)
                $created_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
                $sqlHdr = "INSERT INTO purchase_invoice_hdr (inv_no, supplier_invoice_no, invoice_date, supplier_id, remark, status, created_by)
                           VALUES ($1, $2, $3, $4, $5, 'posted', $6) RETURNING id";
                $resHdr = pg_query_params($sqlHdr, [$inv_no, $supplier_invoice_no, $invoice_date, $supplier_id, $remark, $created_by]);
                if (!$resHdr) throw new Exception(pgErrorOrFallback('Gagal membuat invoice baru.'));
                $rowHdr = pg_fetch_assoc($resHdr);
                $invoice_id = $rowHdr['id'];
            } else {
                $invoice_id = $id;
                // [FIX] inv_no sengaja tidak disertakan di SET -> No. Ref Dokumen immutable setelah dibuat.
                $sqlHdr = "UPDATE purchase_invoice_hdr SET supplier_invoice_no = $1, invoice_date = $2, supplier_id = $3, remark = $4
                           WHERE id = $5";
                $resHdr = pg_query_params($sqlHdr, [$supplier_invoice_no, $invoice_date, $supplier_id, $remark, $invoice_id]);
                if (!$resHdr) throw new Exception(pgErrorOrFallback('Gagal mengupdate invoice.'));

                // [FIX] invoice_hdr_id, bukan invoice_id
                pg_query_params("DELETE FROM purchase_invoice_dtl WHERE invoice_hdr_id = $1", [$invoice_id]);
            }

            $total_amount_header = 0;
            foreach ($invoice_details as $item) {
                // [FIX] field dari frontend juga perlu grn_hdr_id, lihat catatan di purchaseinvoice.js
                $grn_hdr_id = intval(isset($item['grn_hdr_id']) ? $item['grn_hdr_id'] : (isset($item['grn_dtl_id']) ? $item['grn_dtl_id'] : 0));
                $matched_amount = floatval($item['matched_amount']);
                $total_amount_header += $matched_amount;

                // [FIX] invoice_hdr_id, grn_hdr_id, bukan invoice_id, grn_dtl_id
                $sqlDtl = "INSERT INTO purchase_invoice_dtl (invoice_hdr_id, grn_hdr_id, matched_amount) VALUES ($1, $2, $3)";
                $resDtl = pg_query_params($sqlDtl, [$invoice_id, $grn_hdr_id, $matched_amount]);
                if (!$resDtl) throw new Exception(pgErrorOrFallback('Gagal menyimpan detail invoice.'));
            }

            pg_query_params("UPDATE purchase_invoice_hdr SET total_amount = $1 WHERE id = $2", [$total_amount_header, $invoice_id]);

            pg_query("COMMIT");
            echo json_encode(['success' => true, 'message' => 'Data purchase invoice berhasil disimpan.']);
            break;

        case 'delete':
            $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
            pg_query("BEGIN");
            // [FIX] invoice_hdr_id, bukan invoice_id
            pg_query_params("DELETE FROM purchase_invoice_dtl WHERE invoice_hdr_id = $1", [$id]);
            pg_query_params("DELETE FROM purchase_invoice_hdr WHERE id = $1", [$id]);
            pg_query("COMMIT");
            echo json_encode(['success' => true, 'message' => 'Data berhasil dihapus.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
            break;
    }
} catch (Exception $e) {
    if (function_exists('pg_query')) {
        @pg_query("ROLLBACK");
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}