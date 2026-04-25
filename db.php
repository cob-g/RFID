<?php
declare(strict_types=1);

define('DB_HOST', 'sql200.infinityfree.com');
define('DB_USER', 'if0_41597651');
define('DB_PASS', '39L5uytNzWT');
define('DB_NAME', 'if0_41597651_library_seat_system');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

// ── Strict MySQLi error mode ───────────────────────────────────────────────────
// All MySQLi errors will throw mysqli_sql_exception instead of returning false.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
 
// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $conn->set_charset(DB_CHARSET);
} catch (mysqli_sql_exception $e) {
    // Do NOT expose connection details to the browser.
    // Log to server error log, return a generic JSON error for API callers.
    error_log('[DB] Connection failed: ' . $e->getMessage());
 
    // Detect if the caller expects JSON (API endpoints) or HTML (pages)
    $isApi = (
        isset($_SERVER['HTTP_ACCEPT']) &&
        strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
    ) || (
        isset($_SERVER['CONTENT_TYPE']) &&
        strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false
    );
 
    if ($isApi) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(503);
        echo json_encode(['status' => 'error', 'msg' => 'Database unavailable']);
    } else {
        http_response_code(503);
        echo '<!DOCTYPE html><html><body>'
           . '<h2 style="font-family:sans-serif;color:#c00">Service Unavailable</h2>'
           . '<p style="font-family:sans-serif">The database is temporarily unavailable. '
           . 'Please try again shortly.</p>'
           . '</body></html>';
    }
    exit;
}