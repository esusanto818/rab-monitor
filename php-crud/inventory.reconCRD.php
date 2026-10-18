<?php
/**
 * inventory.reconCRD.php
 * ============================================================
 * Backend Rekonsiliasi Persediaan (inventory.recon.js). Read-only.
 * Pola sama dengan poCRD.php: PDO (class crud), dispatch lewat $_REQUEST['task'], respons JSON.
 * Logika perhitungan ada di php-func/function.inventory.recon.php (dipakai juga oleh pdf/inventoryrecon.php).
 *
 * Task: list_accounts | reconcile (as_of=YYYY-MM-DD, account_id)
 * ============================================================
 */

ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';
require_once __DIR__ . '/../php-func/function.inventory.recon.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$db = new crud();

function sendJsonResponse($success, $data = [], $message = '') {
    $response = ['success' => $success, 'message' => $message];
    if ($success) { $response['data'] = $data; }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sendJsonResponse(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';

try {
    switch ($task) {
        case 'list_accounts':
            sendJsonResponse(true, ir_accounts($db));
            break;

        case 'reconcile':
            $asOf = (string)($_REQUEST['as_of'] ?? date('Y-m-d'));
            $accId = (int)($_REQUEST['account_id'] ?? 0);
            sendJsonResponse(true, ir_reconcile($db, $asOf, $accId));
            break;

        default:
            sendJsonResponse(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (PDOException $e) {
    error_log('[inventory.reconCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[inventory.reconCRD] ' . $e->getMessage());
    sendJsonResponse(false, [], $e->getMessage());
}