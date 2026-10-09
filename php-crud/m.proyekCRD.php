<?php
/**
 * m.proyekCRD.php
 * ============================================================
 * Backend Controller untuk Master Proyek (public.projects).
 *
 * PERBAIKAN dari versi sebelumnya:
 * - [FIX FATAL] $db tidak pernah diinisialisasi ($db = new crud() hilang).
 * - [FIX KONTRAK] Versi lama pakai $_REQUEST['action'] dengan nama task
 *   (read/get_clients/get_locations/get_pics/save/delete) dan nama field
 *   (project_name, location_detail) yang TIDAK COCOK dengan m_proyek.js
 *   (yang mengirim task=... dan field name/pic_id/dst) -- selalu jatuh ke
 *   "Aksi tidak valid!". Disatukan ke $_REQUEST['task'], konsisten dengan
 *   seluruh modul lain (whrcvCRD.php, rabCRD.php, dst), dan field mengikuti
 *   PERSIS apa yang dikirim m_proyek.js.
 * - PIC diambil dari tabel `pics` (sesuai picDs di m_proyek.js), BUKAN
 *   m_employee seperti versi lama -- kalau ternyata PIC proyek memang
 *   harus dari data karyawan (m_employee), kabari saya, gampang diubah.
 * - Task 'read_one' ditambahkan (dipanggil m_proyek.js saat Edit, sebelumnya
 *   tidak ada sama sekali di versi lama -- Edit pasti gagal juga).
 * - Field contract_value ditambahkan ke create/update & read (kolom sudah
 *   ada di projects, tapi sebelumnya tidak ada satu pun layar yang bisa
 *   mengisinya -- padahal project_invoiceCRD.php/Termin Proyek bergantung
 *   ke kolom ini).
 * ============================================================
 */

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

session_start();
header('Content-Type: application/json');

$task = $_REQUEST['task'] ?? '';
$db   = new crud(); // [FIX] sebelumnya tidak ada -- inilah penyebab "Aksi tidak valid" / fatal

function PrepOrFailMP($stmt, $context = '') {
    if ($stmt === false) {
        throw new Exception(($context ? "[{$context}] " : '') . 'Query gagal dijalankan.');
    }
    return $stmt;
}
function mpFetchAll($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFailMP($stmt, $ctx);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function mpFetchOne($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFailMP($stmt, $ctx);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
function mpExec($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFailMP($stmt, $ctx);
    $stmt->execute($params);
    return $stmt;
}

$access = intval($_SESSION['FormAccess'] ?? 0);

try {
    switch ($task) {

        case 'read':
            $sql = "SELECT p.id, p.name, p.client_id, c.name AS client_name,
                           p.pic_id, pic.name AS pic_name,
                           p.location_id, loc.name AS location_name, p.location,
                           p.start_date, p.end_date, p.budget, p.contract_value, p.spent, p.progress,
                           p.status, p.notes
                    FROM projects p
                    LEFT JOIN clients c ON c.id = p.client_id
                    LEFT JOIN pics pic ON pic.id = p.pic_id
                    LEFT JOIN m_location loc ON loc.id = p.location_id
                    ORDER BY p.id DESC";
            $data = mpFetchAll($db, $sql, [], 'read');
            echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data]);
            break;

        case 'read_one':
            $id = (int)($_REQUEST['id'] ?? 0);
            $row = mpFetchOne($db, "SELECT * FROM projects WHERE id = :id", ['id' => $id], 'read_one');
            if (!$row) throw new Exception('Data proyek tidak ditemukan.');
            echo json_encode(['success' => true, 'data' => $row]);
            break;

        case 'read_clients':
            echo json_encode(['success' => true, 'data' => mpFetchAll($db,
                "SELECT id, name FROM clients ORDER BY name", [], 'read_clients')]);
            break;

        case 'read_pics':
            echo json_encode(['success' => true, 'data' => mpFetchAll($db,
                "SELECT id, name FROM pics ORDER BY name", [], 'read_pics')]);
            break;

        case 'read_locations':
            echo json_encode(['success' => true, 'data' => mpFetchAll($db,
                "SELECT id, name FROM m_location ORDER BY name", [], 'read_locations')]);
            break;

        // --------------------------------------------------------------
        case 'create':
        case 'update':
            if ($task === 'create' && $access < 2) {
                echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menambah proyek.']);
                exit;
            }
            if ($task === 'update' && $access < 3) {
                echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk mengubah proyek.']);
                exit;
            }

            $id             = (int)($_REQUEST['id'] ?? 0);
            $name           = trim($_REQUEST['name'] ?? '');
            $client_id      = ($_REQUEST['client_id'] ?? '') !== '' ? (int)$_REQUEST['client_id'] : null;
            $pic_id         = ($_REQUEST['pic_id'] ?? '') !== '' ? (int)$_REQUEST['pic_id'] : null;
            $location_id    = ($_REQUEST['location_id'] ?? '') !== '' ? (int)$_REQUEST['location_id'] : null;
            $start_date     = $_REQUEST['start_date'] ?: null;
            $end_date       = $_REQUEST['end_date'] ?: null;
            $budget         = (float)($_REQUEST['budget'] ?? 0);
            $contract_value = (float)($_REQUEST['contract_value'] ?? 0);
            $progress       = (int)($_REQUEST['progress'] ?? 0);
            $status         = $_REQUEST['status'] ?? 'Berjalan';
            $notes          = $_REQUEST['notes'] ?? '';

            if ($name === '') throw new Exception('Nama Proyek wajib diisi.');
            if (!in_array($status, ['Belum Mulai', 'Berjalan', 'Tertunda', 'Selesai'], true)) {
                throw new Exception('Status proyek tidak valid.');
            }
            if ($progress < 0 || $progress > 100) throw new Exception('Progress harus antara 0-100.');
            if ($end_date && $start_date && $end_date < $start_date) {
                throw new Exception('Tanggal Selesai tidak boleh sebelum Tanggal Mulai.');
            }
            if ($budget < 0 || $contract_value < 0) throw new Exception('Budget/Nilai Kontrak tidak boleh negatif.');

            if ($task === 'create') {
                $newId = (int)mpFetchOne($db,
                    "INSERT INTO projects
                        (name, client_id, pic_id, location_id, start_date, end_date, budget, contract_value, progress, status, notes, created_at, updated_at)
                     VALUES (:name, :cid, :pid, :lid, :sd, :ed, :budget, :cv, :progress, :status, :notes, NOW(), NOW())
                     RETURNING id",
                    ['name' => $name, 'cid' => $client_id, 'pid' => $pic_id, 'lid' => $location_id,
                     'sd' => $start_date, 'ed' => $end_date, 'budget' => $budget, 'cv' => $contract_value,
                     'progress' => $progress, 'status' => $status, 'notes' => $notes],
                    'insert projects')['id'];
                echo json_encode(['success' => true, 'id' => $newId, 'message' => "Proyek '{$name}' berhasil ditambahkan."]);
            } else {
                if (!$id) throw new Exception('ID proyek tidak valid.');
                mpExec($db,
                    "UPDATE projects SET
                        name = :name, client_id = :cid, pic_id = :pid, location_id = :lid,
                        start_date = :sd, end_date = :ed, budget = :budget, contract_value = :cv,
                        progress = :progress, status = :status, notes = :notes, updated_at = NOW()
                     WHERE id = :id",
                    ['name' => $name, 'cid' => $client_id, 'pid' => $pic_id, 'lid' => $location_id,
                     'sd' => $start_date, 'ed' => $end_date, 'budget' => $budget, 'cv' => $contract_value,
                     'progress' => $progress, 'status' => $status, 'notes' => $notes, 'id' => $id],
                    'update projects');
                echo json_encode(['success' => true, 'message' => "Proyek '{$name}' berhasil diperbarui."]);
            }
            break;

        case 'delete':
            if ($access < 4) {
                echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menghapus proyek.']);
                exit;
            }
            $id = (int)($_REQUEST['id'] ?? 0);
            if (!$id) throw new Exception('ID proyek tidak valid.');

            $refs = mpFetchOne($db,
                "SELECT
                    (SELECT COUNT(*) FROM purchase_orders WHERE project_id = :id) AS po_count,
                    (SELECT COUNT(*) FROM project_invoices WHERE project_id = :id) AS termin_count,
                    (SELECT COUNT(*) FROM material_out_header WHERE project_id = :id) AS mout_count",
                ['id' => $id], 'cek referensi proyek');
            $refCount = (int)$refs['po_count'] + (int)$refs['termin_count'] + (int)$refs['mout_count'];
            if ($refCount > 0) {
                throw new Exception("Proyek ini tidak bisa dihapus karena sudah punya transaksi terkait (PO: {$refs['po_count']}, Termin: {$refs['termin_count']}, Pemakaian Material: {$refs['mout_count']}).");
            }

            mpExec($db, "DELETE FROM projects WHERE id = :id", ['id' => $id], 'delete projects');
            echo json_encode(['success' => true, 'message' => 'Proyek berhasil dihapus.']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
            break;
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Terjadi kesalahan pada server.']);
}