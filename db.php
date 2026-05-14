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

function authLogHeartbeatIfNeeded(mysqli $conn, int $intervalSeconds = 60): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (empty($_SESSION['user_id'])) {
        return;
    }

    $lastBeat = $_SESSION['auth_log_last_heartbeat'] ?? 0;
    if (!is_int($lastBeat)) {
        $lastBeat = is_numeric($lastBeat) ? (int) $lastBeat : 0;
    }

    $now = time();
    if (($now - $lastBeat) < $intervalSeconds) {
        return;
    }

    $_SESSION['auth_log_last_heartbeat'] = $now;

    authLogWrite($conn, [
        'user_id' => (int) $_SESSION['user_id'],
        'username' => (string) ($_SESSION['username'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? ''),
        'identity' => (string) ($_SESSION['username'] ?? ''),
        'action' => 'heartbeat',
        'session_id' => session_id(),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);
}

if ($conn instanceof mysqli) {
    authLogHeartbeatIfNeeded($conn);
}

function auditLogWrite(mysqli $conn, array $payload): void
{
    $action = trim((string) ($payload['action'] ?? ''));
    if ($action === '' || !dbTableExists($conn, 'audit_log')) {
        return;
    }
    if (!dbColumnExists($conn, 'audit_log', 'action')) {
        return;
    }

    $actorUsername = trim((string) ($payload['actor_username'] ?? ''));
    $actorRole = trim((string) ($payload['actor_role'] ?? ''));
    $targetType = trim((string) ($payload['target_type'] ?? ''));
    $targetLabel = trim((string) ($payload['target_label'] ?? ''));
    $ipAddress = trim((string) ($payload['ip_address'] ?? ''));

    $details = $payload['details'] ?? '';
    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_SLASHES);
    }
    $details = trim((string) $details);

    $actorUserId = $payload['actor_user_id'] ?? null;
    if (!is_int($actorUserId)) {
        $actorUserId = is_numeric($actorUserId) ? (int) $actorUserId : null;
    }

    $targetId = $payload['target_id'] ?? null;
    if (!is_int($targetId)) {
        $targetId = is_numeric($targetId) ? (int) $targetId : null;
    }

    $columns = [];
    $types = '';
    $values = [];

    if ($actorUserId !== null && dbColumnExists($conn, 'audit_log', 'actor_user_id')) {
        $columns[] = 'actor_user_id';
        $types .= 'i';
        $values[] = $actorUserId;
    }

    if ($targetId !== null && dbColumnExists($conn, 'audit_log', 'target_id')) {
        $columns[] = 'target_id';
        $types .= 'i';
        $values[] = $targetId;
    }

    $stringValues = [
        'actor_username' => [$actorUsername, 80],
        'actor_role' => [$actorRole, 30],
        'action' => [$action, 50],
        'target_type' => [$targetType, 40],
        'target_label' => [$targetLabel, 120],
        'details' => [$details, 2000],
        'ip_address' => [$ipAddress, 45],
    ];

    foreach ($stringValues as $column => [$value, $limit]) {
        if (!dbColumnExists($conn, 'audit_log', $column)) {
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
    $sql = 'INSERT INTO audit_log (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

    try {
        $stmt = $conn->prepare($sql);
        dbBindParams($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[audit_log] Insert failed: ' . $e->getMessage());
    }
}

function studentProfileFetch(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0 || !dbTableExists($conn, 'student_profiles')) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT user_id, first_name, last_name, middle_initial, student_id, course_or_department, year_level, section, address'
        . ' FROM student_profiles WHERE user_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function studentProfileUpsert(mysqli $conn, int $userId, array $profile): void
{
    if ($userId <= 0 || !dbTableExists($conn, 'student_profiles')) {
        return;
    }

    $firstName = substr(trim((string) ($profile['first_name'] ?? '')), 0, 100);
    $lastName = substr(trim((string) ($profile['last_name'] ?? '')), 0, 100);
    $middleInitial = substr(trim((string) ($profile['middle_initial'] ?? '')), 0, 5);
    $studentId = substr(trim((string) ($profile['student_id'] ?? '')), 0, 40);
    $course = substr(trim((string) ($profile['course_or_department'] ?? '')), 0, 120);
    $yearLevel = substr(trim((string) ($profile['year_level'] ?? '')), 0, 30);
    $section = substr(trim((string) ($profile['section'] ?? '')), 0, 30);
    $address = substr(trim((string) ($profile['address'] ?? '')), 0, 255);

    $stmt = $conn->prepare(
        'INSERT INTO student_profiles (user_id, first_name, last_name, middle_initial, student_id, course_or_department, year_level, section, address)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)' 
        . ' ON DUPLICATE KEY UPDATE'
        . ' first_name = VALUES(first_name),'
        . ' last_name = VALUES(last_name),'
        . ' middle_initial = VALUES(middle_initial),'
        . ' student_id = VALUES(student_id),'
        . ' course_or_department = VALUES(course_or_department),'
        . ' year_level = VALUES(year_level),'
        . ' section = VALUES(section),'
        . ' address = VALUES(address)'
    );
    $stmt->bind_param('issssssss', $userId, $firstName, $lastName, $middleInitial, $studentId, $course, $yearLevel, $section, $address);
    $stmt->execute();
    $stmt->close();
}

function facultyProfileFetch(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0 || !dbTableExists($conn, 'faculty_profiles')) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT user_id, faculty_level, created_at, updated_at FROM faculty_profiles WHERE user_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function facultyProfileUpsert(mysqli $conn, int $userId, array $profile): void
{
    if ($userId <= 0 || !dbTableExists($conn, 'faculty_profiles')) {
        return;
    }

    $facultyLevel = substr(trim((string) ($profile['faculty_level'] ?? '')), 0, 32);

    $stmt = $conn->prepare(
        'INSERT INTO faculty_profiles (user_id, faculty_level) VALUES (?, ?)'
        . ' ON DUPLICATE KEY UPDATE faculty_level = VALUES(faculty_level)'
    );
    $stmt->bind_param('is', $userId, $facultyLevel);
    $stmt->execute();
    $stmt->close();
}