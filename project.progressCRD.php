<?php
/**
 * project.progressCRD.php — Backend untuk Entry Progress Proyek & Verifikasi HO
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';

$action = WhReq('action', 'list');

try {
    switch ($action) {
        case 'list':            PrgList(); break;
        case 'detail':          PrgDetail(); break;
        case 'lookup_projects': PrgLookupProjects(); break;
        case 'rab_items':       PrgRabItems(); break;
        case 'save':            PrgSave(); break;
        case 'submit':          PrgSubmit(); break;
        case 'verify':          PrgVerify(); break;
        case 'reject':          PrgReject(); break;
        case 'delete':          PrgDelete(); break;
        case 'upload_ba':       PrgUploadBa(); break;
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    @pg_query($conn, 'ROLLBACK');
    WhFail($e->getMessage());
} catch (Exception $e) {
    @pg_query($conn, 'ROLLBACK');
    WhLog('project.progressCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server.');
}

function PrgList() {
    list($start, $limit) = WhPage();
    $q = WhReq('query');
    $params = array();
    $where = '1=1';
    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $where .= ' AND (p.name ILIKE $1 OR l.status ILIKE $1)';
    }
    $c = WhRow("SELECT COUNT(*) AS n FROM project_progress_logs l JOIN projects p ON p.id=l.project_id WHERE $where", $params);
    $params[] = $limit;  $pl = '$' . count($params);
    $params[] = $start;  $ps = '$' . count($params);
    $rows = WhRows("SELECT l.id, l.project_id, p.name AS project_name, l.period_date, l.progress_pct, l.progress_delta,
                           l.ba_document_url, l.status, l.rejection_note,
                           u1.username AS reported_by_name, u2.username AS verified_by_name, l.verified_at
                    FROM project_progress_logs l
                    JOIN projects p ON p.id=l.project_id
                    LEFT JOIN users u1 ON u1.id=l.reported_by
                    LEFT JOIN users u2 ON u2.id=l.verified_by
                    WHERE $where ORDER BY l.period_date DESC, l.id DESC LIMIT $pl OFFSET $ps", $params);
    WhJson(array('success' => true, 'totalCount' => (int)$c['n'], 'data' => $rows));
}

function PrgDetail() {
    $id = (int)WhReq('id');
    $rows = WhRows("SELECT pi.id, pi.budget_item_id, m.material_code AS item_code, m.material_name AS item_name, m.unit,
                           bi.budget_qty AS qty_plan, pi.qty_installed, pi.installed_pct, pi.notes
                    FROM project_progress_items pi
                    JOIN budget_items bi ON bi.id=pi.budget_item_id
                    JOIN materials m ON m.id=bi.material_id
                    WHERE pi.progress_log_id=$1 ORDER BY pi.id", array($id));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function PrgLookupProjects() {
    $q = WhReq('query');
    $rows = WhRows("SELECT id, name AS project_name, COALESCE(budget,0) AS budget, COALESCE(progress,0) AS current_progress
                    FROM projects WHERE name ILIKE $1 ORDER BY name LIMIT 50", array('%' . $q . '%'));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function PrgRabItems() {
    $projectId  = (int)WhReq('project_id');
    $progressId = (int)WhReq('progress_id');
    $rows = WhRows("SELECT bi.id AS budget_item_id, m.material_code AS item_code, m.material_name AS item_name, m.unit,
                           bi.budget_qty AS qty_plan, COALESCE(pi.qty_installed, 0) AS qty_installed,
                           COALESCE(pi.installed_pct, 0) AS installed_pct, COALESCE(pi.notes, '') AS notes
                    FROM budget_items bi
                    JOIN materials m ON m.id=bi.material_id
                    LEFT JOIN project_progress_items pi ON pi.budget_item_id=bi.id AND pi.progress_log_id=$2
                    WHERE bi.project_id=$1 ORDER BY m.material_name", array($projectId, $progressId));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function PrgSave() {
    WhRequirePost();
    $id        = (int)WhPost('id');
    $projectId = (int)WhPost('project_id');
    $date      = WhDate(WhPost('period_date'), 'Tanggal Cut-Off');
    $baUrl     = WhPost('ba_document_url');
    $details   = WhDetails();
    $userId    = WhUserId();

    WhQ('BEGIN');
    if ($id > 0) {
        WhQ("UPDATE project_progress_logs SET period_date=$2, ba_document_url=$3, updated_at=now() WHERE id=$1", array($id, $date, $baUrl));
        WhQ("DELETE FROM project_progress_items WHERE progress_log_id=$1", array($id));
    } else {
        $h = WhRow("INSERT INTO project_progress_logs (project_id, period_date, progress_pct, progress_delta, ba_document_url, reported_by, status)
                    VALUES ($1, $2, 0, 0, $3, $4, 'DRAFT') RETURNING id", array($projectId, $date, $baUrl, $userId));
        $id = (int)$h['id'];
    }

    $totalPctSum = 0; $count = 0;
    foreach ($details as $d) {
        $biId = (int)$d['budget_item_id'];
        $qty  = WhNum($d['qty_installed']);
        $pct  = WhNum($d['installed_pct']);
        $note = trim($d['notes']);
        WhQ("INSERT INTO project_progress_items (progress_log_id, budget_item_id, qty_installed, installed_pct, notes)
             VALUES ($1, $2, $3, $4, $5)", array($id, $biId, $qty, $pct, $note));
        $totalPctSum += $pct;
        $count++;
    }

    // Kalkulasi rata-rata atau bobot progress
    $cumProgress = $count > 0 ? round($totalPctSum / $count, 2) : 0;
    
    // Ambil progress proyek sebelum laporan ini
    $p = WhRow("SELECT progress FROM projects WHERE id=$1", array($projectId));
    $oldProg = (int)($p['progress'] ?? 0);
    $delta = max(0, $cumProgress - $oldProg);

    WhQ("UPDATE project_progress_logs SET progress_pct=$2, progress_delta=$3 WHERE id=$1", array($id, $cumProgress, $delta));
    WhQ('COMMIT');
    WhOk('Laporan progress tersimpan sebagai DRAFT.', array('id' => $id));
}

function PrgSubmit() {
    $id = (int)WhPost('id');
    WhQ("UPDATE project_progress_logs SET status='SUBMITTED', updated_at=now() WHERE id=$1", array($id));
    WhOk('Laporan progress berhasil disubmit ke HO.');
}

function PrgVerify() {
    $id = (int)WhPost('id');
    $userId = WhUserId();
    WhQ('BEGIN');
    $log = WhRow("SELECT * FROM project_progress_logs WHERE id=$1 FOR UPDATE", array($id));
    if (!$log || $log['status'] !== 'SUBMITTED') { throw new WhException('Status laporan tidak valid untuk diverifikasi.'); }

    // Lock & Update status log
    WhQ("UPDATE project_progress_logs SET status='VERIFIED', verified_by=$2, verified_at=now(), updated_at=now() WHERE id=$1", array($id, $userId));
    
    // Update progress fisik resmi di tabel proyek utama
    WhQ("UPDATE projects SET progress=$2, updated_at=now() WHERE id=$1", array($log['project_id'], (int)$log['progress_pct']));
    WhQ('COMMIT');
    WhOk('Progress terverifikasi HO! Progress resmi proyek diperbarui ke ' . $log['progress_pct'] . '%.');
}

function PrgReject() {
    $id = (int)WhPost('id');
    $note = WhPost('rejection_note');
    WhQ("UPDATE project_progress_logs SET status='REJECTED', rejection_note=$2, updated_at=now() WHERE id=$1", array($id, $note));
    WhOk('Laporan progress ditolak. Notifikasi dikirim ke site engineer.');
}

function PrgDelete() {
    $id = (int)WhPost('id');
    WhQ("DELETE FROM project_progress_logs WHERE id=$1 AND status IN ('DRAFT','REJECTED')", array($id));
    WhOk('Laporan progress berhasil dihapus.');
}

function PrgUploadBa() {
    if (empty($_FILES['file'])) throw new WhException('File tidak ditemukan.');
    $f = $_FILES['file'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $dir = dirname(__DIR__) . '/uploads/progress';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'ba_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        WhJson(array('success' => true, 'url' => '/rab-project/uploads/progress/' . $name));
    } else {
        throw new WhException('Gagal mengunggah file.');
    }
}
