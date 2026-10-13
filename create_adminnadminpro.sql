<?php
/**
 * inject_users.php -- buat user sistem 'admin' dan 'adminpro' (password awal 789)
 * ============================================================
 * Mengikuti alur 'create' di m.user.systemCRD.php:
 *   m_employee (karyawan penaut)  ->  m_user_system  ->  m_user_login_status  ->  m_user_access_menu (semua form aktif)
 * Password di-hash dengan EncodeData() milik aplikasi (sama dengan form User List), BUKAN ditulis mentah.
 *
 * PAKAI (CLI saja, dari folder proyek):
 *     php php-crud/inject_users.php               -> buat user yang belum ada (yang sudah ada dilewati)
 *     php php-crud/inject_users.php --dry-run     -> tampilkan rencana, semua perubahan dibatalkan (rollback)
 *     php php-crud/inject_users.php --force       -> user yang sudah ada: reset password, aktifkan, setel ulang hak akses
 *
 * Semua perubahan dalam SATU transaksi: gagal di tengah = tidak ada yang tersimpan.
 * HAPUS file ini dari server setelah dipakai, dan ganti password 789 setelah login pertama.
 * ============================================================
 */

if (PHP_SAPI !== 'cli') {                      // jangan pernah bisa dijalankan lewat web
    http_response_code(403);
    exit('Script ini hanya boleh dijalankan lewat CLI.');
}
chdir(__DIR__);                                // include relatif di function.all.php / crudClass.php mengikuti folder php-crud
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

// ---- KONFIGURASI -------------------------------------------------------------
const INJECT_PASSWORD = '789';
// level akses per menu (2 = buat, 3 = ubah / ganti status / verifikasi, 4 = hapus). Ubah di sini bila perlu.
$USERS = [
    ['login' => 'admin',    'employee' => 'Administrator', 'level' => 3],
    ['login' => 'adminpro', 'employee' => 'Admin Pro',     'level' => 4],
];
// ------------------------------------------------------------------------------

$dry   = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);

if (!function_exists('EncodeData')) {
    fwrite(STDERR, "EncodeData() tidak ditemukan di function.all.php -- batal.\n");
    exit(1);
}

$db = new crud();
$hash = EncodeData(INJECT_PASSWORD);
if (!is_string($hash) || $hash === '') {
    fwrite(STDERR, "EncodeData() tidak menghasilkan hash -- batal.\n");
    exit(1);
}

try {
    $db->beginTransaction();

    foreach ($USERS as $u) {
        $login = $u['login'];

        // 1) karyawan penaut (m_user_system.employee_id wajib; pakai yang sudah ada bila namanya sama)
        $st = $db->prepare("SELECT id FROM m_employee WHERE employee_name = :n ORDER BY id LIMIT 1");
        $st->execute([':n' => $u['employee']]);
        $empId = $st->fetchColumn();
        if (!$empId) {
            $st = $db->prepare("INSERT INTO m_employee (employee_name, remark, active) VALUES (:n, :r, true) RETURNING id");
            $st->execute([':n' => $u['employee'], ':r' => 'Akun sistem (inject_users.php)']);
            $empId = $st->fetchColumn();
            echo "  + karyawan '{$u['employee']}' dibuat (id {$empId})\n";
        } else {
            echo "  = karyawan '{$u['employee']}' sudah ada (id {$empId})\n";
        }

        // 2) user: sudah ada?
        $st = $db->prepare("SELECT id FROM m_user_system WHERE LOWER(login_name) = LOWER(:l) ORDER BY id LIMIT 1");
        $st->execute([':l' => $login]);
        $userId = $st->fetchColumn();

        if ($userId && !$force) {
            echo "[{$login}] sudah ada (id {$userId}) -- dilewati (pakai --force untuk reset password & hak akses)\n";
            continue;
        }

        if ($userId) {
            $st = $db->prepare("UPDATE m_user_system SET password = :p, employee_id = :e, active = true WHERE id = :id");
            $st->execute([':p' => $hash, ':e' => $empId, ':id' => $userId]);
            echo "[{$login}] diperbarui (id {$userId}): password direset, aktif\n";
        } else {
            $st = $db->prepare("INSERT INTO m_user_system (login_name, employee_id, password, active) VALUES (:l, :e, :p, true) RETURNING id");
            $st->execute([':l' => $login, ':e' => $empId, ':p' => $hash]);
            $userId = $st->fetchColumn();
            echo "[{$login}] dibuat (id {$userId})\n";
        }

        // 3) status login (baris wajib, seperti di form User List)
        $st = $db->prepare("SELECT 1 FROM m_user_login_status WHERE user_id = :id");
        $st->execute([':id' => $userId]);
        if (!$st->fetchColumn()) {
            $db->prepare("INSERT INTO m_user_login_status (user_id) VALUES (:id)")->execute([':id' => $userId]);
        }

        // 4) hak akses: semua form aktif pada level yang ditentukan (ganti total hak akses lama user ini)
        $db->prepare("DELETE FROM m_user_access_menu WHERE user_id = :id")->execute([':id' => $userId]);
        $st = $db->prepare("INSERT INTO m_user_access_menu (user_id, form_id, access_level)
                            SELECT :id, f.id, :lvl FROM m_form_name f WHERE f.active = true");
        $st->execute([':id' => $userId, ':lvl' => (int)$u['level']]);
        echo "    hak akses: " . $st->rowCount() . " form pada level " . (int)$u['level'] . "\n";
    }

    if ($dry) {
        $db->rollBack();
        echo "\nDRY-RUN: semua perubahan dibatalkan.\n";
    } else {
        $db->commit();
        echo "\nSelesai. Login dengan password awal '" . INJECT_PASSWORD . "', lalu segera ganti. Hapus file ini dari server.\n";
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, "GAGAL (semua dibatalkan): " . $e->getMessage() . "\n");
    exit(1);
}
