<?php
/**
 * db_connection.php
 * Koneksi Database untuk Jewelry ERP System (inv_project)
 */

$host = 'localhost';
$db   = 'proyekGMN'; // Sesuaikan dengan nama database Anda
$user = 'postgres';        // Sesuaikan dengan username database Anda
$pass = 'merdeka';  // Sesuaikan dengan password database Anda
$port = '5432';           // Port default PostgreSQL (Ganti ke '3306' jika menggunakan MySQL)

$dsn = "pgsql:host=$host;port=$port;dbname=$db;";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Jika koneksi gagal, kembalikan respons JSON error agar mudah dibaca oleh ExtJS store/form
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => 'Database Connection Failed: ' . $e->getMessage()
    ]);
    exit();
}
?>
