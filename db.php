<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'scc_library_local_rebuild');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

// Load RFID API key from environment when available; keep a safe fallback
// so existing ESP32 deployments continue working without firmware changes.
if (!defined('API_KEY')) {
    $envApiKey = getenv('RFID_API_KEY');
    if ($envApiKey === false || $envApiKey === '') {
        $envApiKey = $_ENV['RFID_API_KEY'] ?? ($_SERVER['RFID_API_KEY'] ?? '');
    }
    define('API_KEY', (string) ($envApiKey !== '' ? $envApiKey : 'SCC-2026-SECURE'));
}


if (!defined('RFID_PORTAL_URL')) {
    $portalUrl = getenv('RFID_PORTAL_URL');
    if ($portalUrl === false || trim($portalUrl) === '') {
        $portalUrl = $_ENV['RFID_PORTAL_URL'] ?? ($_SERVER['RFID_PORTAL_URL'] ?? '');
    }
    $portalUrl = trim((string) $portalUrl);
    if ($portalUrl === '') {
        $portalUrl = 'http://192.168.1.19/';
    }
    if (!filter_var($portalUrl, FILTER_VALIDATE_URL)) {
        $portalUrl = 'http://192.168.1.19/';
    }
    $portalUrl = rtrim($portalUrl, '/') . '/';
    define('RFID_PORTAL_URL', $portalUrl);
}

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

    if (defined('DB_OPTIONAL') && DB_OPTIONAL) {
        $conn = null;
        return;
    }
 
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

function dbTableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->bind_result($one);
    $exists = $stmt->fetch();
    $stmt->close();

    $cache[$table] = (bool) $exists;
    return $cache[$table];
}

function dbColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->bind_result($one);
    $exists = $stmt->fetch();
    $stmt->close();

    $cache[$key] = (bool) $exists;
    return $cache[$key];
}

function dbBindParams(mysqli_stmt $stmt, string $types, array &$values): void
{
    $params = [$types];
    foreach ($values as $i => &$value) {
        $params[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
}

function authLogWrite(mysqli $conn, array $payload): void
{
    $action = trim((string) ($payload['action'] ?? ''));
    if ($action === '' || !dbTableExists($conn, 'auth_log')) {
        return;
    }
    if (!dbColumnExists($conn, 'auth_log', 'action')) {
        return;
    }

    $identity = trim((string) ($payload['identity'] ?? ''));
    $username = trim((string) ($payload['username'] ?? ''));
    $role = trim((string) ($payload['role'] ?? ''));
    $sessionId = trim((string) ($payload['session_id'] ?? ''));
    $ipAddress = trim((string) ($payload['ip_address'] ?? ''));
    $userAgent = trim((string) ($payload['user_agent'] ?? ''));

    $userId = $payload['user_id'] ?? null;
    if (!is_int($userId)) {
        $userId = is_numeric($userId) ? (int) $userId : null;
    }

    $columns = [];
    $types = '';
    $values = [];

    $stringValues = [
        'username' => [$username, 80],
        'role' => [$role, 30],
        'identity' => [$identity, 120],
        'action' => [$action, 24],
        'session_id' => [$sessionId, 128],
        'ip_address' => [$ipAddress, 45],
        'user_agent' => [$userAgent, 255],
    ];

    if ($userId !== null && dbColumnExists($conn, 'auth_log', 'user_id')) {
        $columns[] = 'user_id';
        $types .= 'i';
        $values[] = $userId;
    }

    foreach ($stringValues as $column => [$value, $limit]) {
        if (!dbColumnExists($conn, 'auth_log', $column)) {
            continue;
        }
        if ($value === '') {
            continue;
        }
        $columns[] = $column;
        $types .= 's';
        $values[] = substr($value, 0, $limit);
    }

    if ($columns === []) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO auth_log (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

    try {
        $stmt = $conn->prepare($sql);
        dbBindParams($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[auth_log] Insert failed: ' . $e->getMessage());
    }
}