<?php
// Include file fungsi dasar dan class database yang digunakan sistem
require_once __DIR__ . '/php-class/crudClass.php';
require_once __DIR__ . '/php-func/function.all.php';

// Inisialisasi koneksi database
$db = new crud();

try {
    $username = 'admin';
    $new_password = '789';

    // Gunakan fungsi EncodeData() yang biasa dipakai aplikasi untuk enkripsi password (atau md5 jika sesuai sistem)
    $encrypted_password = function_exists('EncodeData') ? EncodeData($new_password) : md5($new_password);

    // Cek apakah user admin tersedia di tabel m_user_system
    $stmt = $db->prepare("SELECT id FROM m_user_system WHERE login_name = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user_id = $user['id'];

        // Update password user admin
        $updateQuery = "UPDATE m_user_system SET password = :password WHERE id = :id;";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([
            'password' => $encrypted_password,
            'id' => $user_id
        ]);

        echo "<h3>Berhasil! Password untuk user <b>'admin'</b> telah diperbarui menjadi <b>'789'</b>.</h3>";
        echo "<p>Silakan hapus file ini demi keamanan setelah selesai digunakan.</p>";
    } else {
        echo "<h3>Gagal: User dengan login name 'admin' tidak ditemukan di dalam tabel m_user_system.</h3>";
    }

} catch (Exception $e) {
    echo "<h3>Terjadi kesalahan saat memperbarui password:</h3> " . $e->getMessage();
}
?>
