<?php
/**
 * m.proyekCRD.php
 * ============================================================
 * Backend Controller untuk Master Proyek (public.projects).
 *
 * Catatan perubahan:
 * - [FIX PARSE ERROR] Karakter 'P' nyasar setelah '}' terakhir dihapus
 *   (penyebab "unexpected end of file" di log Apache).
 * - [FIX PIC] projects.pic_id mengacu ke m_employee.id (terbukti dari data),
 *   bukan tabel `pics`. 'read' dan 'read_pics' sekarang memakai m_employee.
 * - [FIX] UPDATE tidak lagi menimpa budget/contract_value/progress
 *   dengan 0 bila field tersebut tidak dikirim klien.
 * - [FIX] Respons JSON dibersihkan dari output liar (ob_start/ob_clean),
 *   display_errors dimatikan agar JSON tidak rusak.
 * - [NEW 2026-10-12] PLAFON: contract_value (mata uang dasar) = plafon. Budget (x kurs anggaran) harus <= nilai kontrak;
 *   selisihnya adalah CADANGAN yang dipakai saat proyek berjalan (lihat rabCRD.php). Nilai kontrak juga tidak boleh
 *   diturunkan di bawah total RAB yang sudah tersusun. Divalidasi di create & update (mpCheckPlafon).
 * - [NEW 2026-10-12] projects.progress read-only di master: diubah hanya lewat Progress Proyek (klaim + verifikasi).
 * ============================================================
 */

ini_set('display_errors', '0');   // [FIX] jangan bocorkan error ke body JSON
ob_start();                        // [FIX] tampung output liar

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$task = $_REQUEST['task'] ?? '';
$db   = new crud();

/** [FIX] Kirim JSON bersih lalu berhenti. */
function mpJson(array $payload) {
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

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

/** [NEW] Format angka untuk pesan (1.234.567,89). */
function mpFmt($n) { return number_format((float)$n, 2, ',', '.'); }

/**
 * [NEW] Validasi plafon. $budget dalam mata uang anggaran, $rate = kurs anggaran ke mata uang dasar,
 * $contract dalam mata uang dasar. Nilai kontrak 0 = belum diisi -> belum ada plafon yang bisa dicek.
 */
function mpCheckPlafon($db, $projectId, $budget, $contract, $rate) {
    if ($contract <= 0) return;
    $budgetBase = round($budget * $rate, 2);
    if ($budgetBase > $contract + 0.01) {
        throw new Exception('Budget (setara ' . mpFmt($budgetBase) . ') tidak boleh melebihi Nilai Kontrak (' . mpFmt($contract) .
                            '). Selisih nilai kontrak dan budget menjadi cadangan.');
    }
    if ($projectId > 0) {
        $r = mpFetchOne($db, "SELECT COALESCE(SUM(qty_plan * unit_price_plan), 0) AS t FROM budget_items
                               WHERE project_id = :id AND category = 'Material'", ['id' => $projectId], 'cek total RAB');
        $rabBase = round((float)$r['t'] * $rate, 2);
        if ($rabBase > $contract + 0.01) {
            throw new Exception('Nilai Kontrak (' . mpFmt($contract) . ') tidak boleh di bawah total RAB yang sudah tersusun (setara ' . mpFmt($rabBase) . ').');
        }
    }
}

$access = intval($_SESSION['FormAccess'] ?? 0);

try {
    switch ($task) {

        case 'read':
            // [FIX PIC] join ke m_employee (bukan pics)
            $sql = "SELECT p.id, p.name, p.client_id, c.name AS client_name,
                           p.pic_id, e.employee_name AS pic_name,
                           p.location_id, loc.name AS location_name, p.location,
                           p.start_date, p.end_date, p.budget, p.contract_value, p.spent, p.progress,
                           p.status, p.notes,
                           COALESCE(NULLIF(p.budget_exch_rate, 0), 1) AS budget_exch_rate,      -- [NEW] untuk hitung cadangan
                           COALESCE(UPPER(TRIM(cur.init)), 'IDR') AS budget_currency_code
                    FROM projects p
                    LEFT JOIN m_currency cur ON cur.id = p.budget_currency_id
                    LEFT JOIN clients c ON c.id = p.client_id
                    LEFT JOIN m_employee e ON e.id = p.pic_id
                    LEFT JOIN m_location loc ON loc.id = p.location_id
                    ORDER BY p.id DESC";
            $data = mpFetchAll($db, $sql, [], 'read');
            mpJson(['success' => true, 'totalCount' => count($data), 'data' => $data]);
            break;

        case 'read_one':
            $id  = (int)($_REQUEST['id'] ?? 0);
            $row = mpFetchOne($db, "SELECT * FROM projects WHERE id = :id", ['id' => $id], 'read_one');
            if (!$row) throw new Exception('Data proyek tidak ditemukan.');
            mpJson(['success' => true, 'data' => $row]);
            break;

        case 'read_clients':
            mpJson(['success' => true, 'data' => mpFetchAll($db,
                "SELECT id, name FROM clients ORDER BY name", [], 'read_clients')]);
            break;

        case 'read_pics':
            // [FIX PIC] daftar PIC = karyawan aktif; karyawan nonaktif tetap ikut
            // bila sudah dipakai sebagai PIC proyek (agar tampil benar saat Edit).
            mpJson(['success' => true, 'data' => mpFetchAll($db,
                "SELECT id, employee_name AS name
                   FROM m_employee
                  WHERE employee_name IS NOT NULL
                    AND (active IS NOT FALSE OR id IN (SELECT pic_id FROM projects WHERE pic_id IS NOT NULL))
                  ORDER BY employee_name", [], 'read_pics')]);
            break;

        case 'read_locations':
            mpJson(['success' => true, 'data' => mpFetchAll($db,
                "SELECT id, name FROM m_location ORDER BY name", [], 'read_locations')]);
            break;

        // --------------------------------------------------------------
        case 'create':
        case 'update':
            if ($task === 'create' && $access < 2) {
                mpJson(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menambah proyek.']);
            }
            if ($task === 'update' && $access < 3) {
                mpJson(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk mengubah proyek.']);
            }

            $id             = (int)($_REQUEST['id'] ?? 0);
            $name           = trim($_REQUEST['name'] ?? '');
            $client_id      = ($_REQUEST['client_id'] ?? '') !== '' ? (int)$_REQUEST['client_id'] : null;
            $pic_id         = ($_REQUEST['pic_id'] ?? '') !== '' ? (int)$_REQUEST['pic_id'] : null;
            $location_id    = ($_REQUEST['location_id'] ?? '') !== '' ? (int)$_REQUEST['location_id'] : null;
            $start_date     = ($_REQUEST['start_date'] ?? '') ?: null;
            $end_date       = ($_REQUEST['end_date'] ?? '') ?: null;
            $status         = $_REQUEST['status'] ?? 'Berjalan';
            $notes          = $_REQUEST['notes'] ?? '';

            // [FIX] angka: null = tidak dikirim -> pada UPDATE nilai lama dipertahankan
            $budget         = (isset($_REQUEST['budget']) && $_REQUEST['budget'] !== '') ? (float)$_REQUEST['budget'] : null;
            $contract_value = (isset($_REQUEST['contract_value']) && $_REQUEST['contract_value'] !== '') ? (float)$_REQUEST['contract_value'] : null;
            // [CHANGED 2026-10-12] progress TIDAK lagi diterima dari form: hanya berubah lewat klaim + verifikasi (project.progressCRD.php).
            //   create -> 0; update -> nilai lama dipertahankan (COALESCE).
            $progress       = null;

            if ($name === '') throw new Exception('Nama Proyek wajib diisi.');
            if (!in_array($status, ['Belum Mulai', 'Berjalan', 'Tertunda', 'Selesai'], true)) {
                throw new Exception('Status proyek tidak valid.');
            }
            if ($progress !== null && ($progress < 0 || $progress > 100)) throw new Exception('Progress harus antara 0-100.');
            if ($end_date && $start_date && $end_date < $start_date) {
                throw new Exception('Tanggal Selesai tidak boleh sebelum Tanggal Mulai.');
            }
            if (($budget !== null && $budget < 0) || ($contract_value !== null && $contract_value < 0)) {
                throw new Exception('Budget/Nilai Kontrak tidak boleh negatif.');
            }

            // [NEW] plafon: budget <= nilai kontrak; nilai kontrak >= total RAB
            if ($task === 'update' && !$id) throw new Exception('ID proyek tidak valid.');
            if ($task === 'create') {
                $effBudget = $budget ?? 0.0; $effContract = $contract_value ?? 0.0; $effRate = 1.0;
            } else {
                $cur = mpFetchOne($db, "SELECT COALESCE(budget, 0) AS budget, COALESCE(contract_value, 0) AS contract_value,
                                               COALESCE(NULLIF(budget_exch_rate, 0), 1) AS rate FROM projects WHERE id = :id", ['id' => $id], 'cek plafon');
                if (!$cur) throw new Exception('Data proyek tidak ditemukan.');
                $effBudget   = $budget ?? (float)$cur['budget'];
                $effContract = $contract_value ?? (float)$cur['contract_value'];
                $effRate     = (float)$cur['rate'];
            }
            mpCheckPlafon($db, $task === 'update' ? $id : 0, $effBudget, $effContract, $effRate);

            if ($task === 'create') {
                $newId = (int)mpFetchOne($db,
                    "INSERT INTO projects
                        (name, client_id, pic_id, location_id, start_date, end_date, budget, contract_value, progress, status, notes, created_at, updated_at)
                     VALUES (:name, :cid, :pid, :lid, :sd, :ed, :budget, :cv, :progress, :status, :notes, NOW(), NOW())
                     RETURNING id",
                    ['name' => $name, 'cid' => $client_id, 'pid' => $pic_id, 'lid' => $location_id,
                     'sd' => $start_date, 'ed' => $end_date,
                     'budget' => $budget ?? 0, 'cv' => $contract_value ?? 0,
                     'progress' => $progress ?? 0, 'status' => $status, 'notes' => $notes],
                    'insert projects')['id'];
                mpJson(['success' => true, 'id' => $newId, 'message' => "Proyek '{$name}' berhasil ditambahkan."]);
            } else {
                if (!$id) throw new Exception('ID proyek tidak valid.');
                // [FIX] COALESCE: kolom angka yang tidak dikirim tidak ditimpa 0
                mpExec($db,
                    "UPDATE projects SET
                        name = :name, client_id = :cid, pic_id = :pid, location_id = :lid,
                        start_date = :sd, end_date = :ed,
                        budget = COALESCE(CAST(:budget AS numeric), budget),
                        contract_value = COALESCE(CAST(:cv AS numeric), contract_value),
                        progress = COALESCE(CAST(:progress AS smallint), progress),
                        status = :status, notes = :notes, updated_at = NOW()
                     WHERE id = :id",
                    ['name' => $name, 'cid' => $client_id, 'pid' => $pic_id, 'lid' => $location_id,
                     'sd' => $start_date, 'ed' => $end_date,
                     'budget' => $budget, 'cv' => $contract_value, 'progress' => $progress,
                     'status' => $status, 'notes' => $notes, 'id' => $id],
                    'update projects');
                mpJson(['success' => true, 'message' => "Proyek '{$name}' berhasil diperbarui."]);
            }
            break;

        case 'delete':
            if ($access < 4) {
                mpJson(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menghapus proyek.']);
            }
            $id = (int)($_REQUEST['id'] ?? 0);
            if (!$id) throw new Exception('ID proyek tidak valid.');

            $refs = mpFetchOne($db,
                "SELECT
                    (SELECT COUNT(*) FROM purchase_orders WHERE project_id = :id1) AS po_count,
                    (SELECT COUNT(*) FROM project_invoices WHERE project_id = :id2) AS termin_count,
                    (SELECT COUNT(*) FROM material_out_header WHERE project_id = :id3) AS mout_count",
                ['id1' => $id, 'id2' => $id, 'id3' => $id], 'cek referensi proyek');   // [FIX] placeholder unik per pemakaian
            $refCount = (int)$refs['po_count'] + (int)$refs['termin_count'] + (int)$refs['mout_count'];
            if ($refCount > 0) {
                throw new Exception("Proyek ini tidak bisa dihapus karena sudah punya transaksi terkait (PO: {$refs['po_count']}, Termin: {$refs['termin_count']}, Pemakaian Material: {$refs['mout_count']}).");
            }

            mpExec($db, "DELETE FROM projects WHERE id = :id", ['id' => $id], 'delete projects');
            mpJson(['success' => true, 'message' => 'Proyek berhasil dihapus.']);
            break;

        default:
            mpJson(['success' => false, 'message' => 'Task tidak dikenali.']);
            break;
    }
} catch (Throwable $e) {
    error_log('[m.proyekCRD] ' . $e->getMessage());
    mpJson(['success' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Terjadi kesalahan pada server.']);
}