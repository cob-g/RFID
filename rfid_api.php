<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'msg' => 'GET/POST only', 'retryable' => false]);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/system_hours.php';

$query = $_GET;
$data = [];
if ($method === 'POST') {
    $raw = (string) file_get_contents('php://input', false, null, 0, 8192);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    } elseif (!empty($_POST) && is_array($_POST)) {
        $data = $_POST;
    }
}

$rawAction = $method === 'GET'
    ? (string) ($query['action'] ?? '')
    : (string) ($data['action'] ?? ($query['action'] ?? ''));
$actionUpper = strtoupper(trim($rawAction));

$enrollmentActions = ['ENROLL', 'LIST', 'DELETE', 'REQUEST', 'POLL', 'SUBMIT', 'CHECK'];
if (in_array($actionUpper, $enrollmentActions, true)) {
    if (!defined('ENROLL_API_LIBRARY_MODE')) {
        define('ENROLL_API_LIBRARY_MODE', true);
    }
    require_once __DIR__ . '/enroll_api.php';
    enrollApiHandleRequest($conn, $method, $query, $data);
    exit;
}

// Attendance writes remain POST-only for safety and unchanged ESP32 behavior.
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'msg' => 'POST only for attendance', 'retryable' => false]);
    exit;
}
if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Empty or unreadable body', 'retryable' => false]);
    exit;
}

$apiKey = (string) ($data['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''));
if (!hash_equals(API_KEY, $apiKey)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Invalid API key', 'retryable' => false]);
    exit;
}

$uid = strtoupper(trim((string) ($data['uid'] ?? '')));
$date = trim((string) ($data['date'] ?? ''));
$time = trim((string) ($data['time'] ?? ''));
$action = strtoupper(trim((string) ($data['action'] ?? '')));
$eventId = trim((string) ($data['event_id'] ?? ''));
$device = substr(trim((string) ($data['device'] ?? 'esp32')), 0, 50);

$required = [
    'uid' => $uid,
    'date' => $date,
    'time' => $time,
    'action' => $action,
    'event_id' => $eventId,
];
$missing = array_keys(array_filter($required, static fn($v): bool => $v === ''));
if ($missing) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Missing: ' . implode(', ', $missing), 'retryable' => false]);
    exit;
}
if (!in_array($action, ['TIME_IN', 'TIME_OUT'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'action must be TIME_IN or TIME_OUT', 'retryable' => false]);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'date must be YYYY-MM-DD', 'retryable' => false]);
    exit;
}
if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'time must be HH:MM:SS', 'retryable' => false]);
    exit;
}

$hoursState = libraryHoursEvaluate($conn);
if (!$hoursState['is_open']) {
    echo json_encode(
        libraryHoursApiClosedPayload(
            $conn,
            'RFID attendance is unavailable while the library is closed.',
            $hoursState
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

$expiredCleanup = ['seats' => 0, 'computers' => 0];
try {
    $expiredCleanup = rfidApiCleanupExpiredPendingReservations($conn);
} catch (Throwable $e) {
    error_log('[rfid_api] Expired cleanup failed: ' . $e->getMessage());
}

$card = rfidApiResolveUid($conn, $uid);
if (!$card) {
    // HTTP 200 so ESP32 does not queue this as a transient failure.
    echo json_encode([
        'status' => 'unknown_uid',
        'msg' => 'Card not enrolled - visit the library portal to register',
        'retryable' => false,
    ]);
    exit;
}

$stmt = $conn->prepare('SELECT id, action FROM attendance WHERE event_id = ? LIMIT 1');
$stmt->bind_param('s', $eventId);
$stmt->execute();
$stmt->bind_result($existingId, $existingAction);
$isDup = $stmt->fetch();
$stmt->close();

if ($isDup) {
    $dupReleased = ['seats' => 0, 'computers' => 0];
    if ($existingAction === 'TIME_OUT' && $card['user_id'] !== null) {
        try {
            $dupReleased = rfidApiAutoReleaseAllocations($conn, (int) $card['user_id']);
        } catch (Throwable $e) {
            error_log('[rfid_api] Duplicate TIME_OUT auto-release failed: ' . $e->getMessage());
        }
    }

    $dupPayload = [
        'status' => 'duplicate',
        'msg' => 'Event already recorded',
        'name' => $card['name'],
        'action' => $existingAction,
        'retryable' => false,
    ];
    if ($existingAction === 'TIME_OUT' && $card['user_id'] !== null) {
        $dupPayload['released_seats'] = $dupReleased['seats'];
        $dupPayload['released_computers'] = $dupReleased['computers'];
    }

    echo json_encode($dupPayload);
    exit;
}

if ($action === 'TIME_IN') {
    if (rfidApiHasAttendanceActionToday($conn, $uid, $card['user_id'], $date, 'TIME_IN')) {
        echo json_encode([
            'status' => 'already_timed_in',
            'msg' => $card['name'] . ' already timed in today',
            'name' => $card['name'],
            'retryable' => false,
        ]);
        exit;
    }
}

if ($action === 'TIME_OUT') {
    if (!rfidApiHasAttendanceActionToday($conn, $uid, $card['user_id'], $date, 'TIME_IN')) {
        echo json_encode([
            'status' => 'missing_time_in',
            'msg' => $card['name'] . ' must TIME_IN first before TIME_OUT',
            'name' => $card['name'],
            'retryable' => false,
        ]);
        exit;
    }

    if (rfidApiHasAttendanceActionToday($conn, $uid, $card['user_id'], $date, 'TIME_OUT')) {
        $alreadyReleased = ['seats' => 0, 'computers' => 0];
        if ($card['user_id'] !== null) {
            try {
                $alreadyReleased = rfidApiAutoReleaseAllocations($conn, (int) $card['user_id']);
            } catch (Throwable $e) {
                error_log('[rfid_api] Already-timed-out auto-release failed: ' . $e->getMessage());
            }
        }

        $alreadyPayload = [
            'status' => 'already_timed_out',
            'msg' => $card['name'] . ' already timed out today',
            'name' => $card['name'],
            'retryable' => false,
        ];
        if ($card['user_id'] !== null) {
            $alreadyPayload['released_seats'] = $alreadyReleased['seats'];
            $alreadyPayload['released_computers'] = $alreadyReleased['computers'];
        }

        echo json_encode($alreadyPayload);
        exit;
    }
}

try {
    $newId = rfidApiInsertAttendance($conn, $card, $uid, $date, $time, $action, $eventId, $device);
} catch (Throwable $e) {
    error_log('[rfid_api] INSERT failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => 'Failed to record attendance', 'retryable' => true]);
    exit;
}

$confirmed = ['seats' => 0, 'computers' => 0];
if ($action === 'TIME_IN' && $card['user_id'] !== null) {
    $timeInUserId = (int) $card['user_id'];
    $pendingSeatLabels = [];

    try {
        $pendingSeatLabels = rfidApiPendingSeatLabelsForUser($conn, $timeInUserId);
    } catch (Throwable $e) {
        error_log('[rfid_api] Pending seat lookup failed: ' . $e->getMessage());
    }

    try {
        $confirmed = rfidApiConfirmPendingReservations($conn, $timeInUserId);
    } catch (Throwable $e) {
        error_log('[rfid_api] Pending confirmation failed: ' . $e->getMessage());
    }

    if ($confirmed['seats'] > 0) {
        try {
            rfidApiSendSeatConfirmedEmailForUser($conn, $timeInUserId, $pendingSeatLabels, $date . ' ' . $time);
        } catch (Throwable $e) {
            error_log('[rfid_api] Seat confirmed email failed: ' . $e->getMessage());
        }
    }
}

$released = ['seats' => 0, 'computers' => 0];
if ($action === 'TIME_OUT' && $card['user_id'] !== null) {
    try {
        $released = rfidApiAutoReleaseAllocations($conn, (int) $card['user_id']);
    } catch (Throwable $e) {
        error_log('[rfid_api] Auto-release failed: ' . $e->getMessage());
    }
}

$payload = [
    'status' => 'ok',
    'msg' => 'Attendance recorded',
    'name' => $card['name'],
    'action' => $action,
    'attendance_id' => $newId,
    'retryable' => false,
];
if ($action === 'TIME_IN' && $card['user_id'] !== null) {
    $payload['confirmed_seats'] = $confirmed['seats'];
    $payload['confirmed_computers'] = $confirmed['computers'];
}
if ($action === 'TIME_OUT' && $card['user_id'] !== null) {
    $payload['released_seats'] = $released['seats'];
    $payload['released_computers'] = $released['computers'];
}
$payload['expired_cleanup_seats'] = $expiredCleanup['seats'];
$payload['expired_cleanup_computers'] = $expiredCleanup['computers'];

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function rfidApiTableExists(mysqli $conn, string $table): bool
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

function rfidApiColumnExists(mysqli $conn, string $table, string $column): bool
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

function rfidApiSchema(mysqli $conn): array
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }

    $schema = [
        'has_rfid_cards' => rfidApiTableExists($conn, 'rfid_cards'),
        'has_rfid_devices' => rfidApiTableExists($conn, 'rfid_devices'),
        'rfid_cards_has_status' => false,
        'rfid_cards_has_user_id' => false,
        'rfid_devices_has_status' => false,
        'attendance_has_card_id' => rfidApiColumnExists($conn, 'attendance', 'card_id'),
        'attendance_has_user_id' => rfidApiColumnExists($conn, 'attendance', 'user_id'),
    ];

    if ($schema['has_rfid_cards']) {
        $schema['rfid_cards_has_status'] = rfidApiColumnExists($conn, 'rfid_cards', 'status');
        $schema['rfid_cards_has_user_id'] = rfidApiColumnExists($conn, 'rfid_cards', 'user_id');
    }
    if ($schema['has_rfid_devices']) {
        $schema['rfid_devices_has_status'] = rfidApiColumnExists($conn, 'rfid_devices', 'status');
    }

    return $schema;
}

function rfidApiLookupUserIdFromIdentity(mysqli $conn, string $identity): ?int
{
    static $cache = [];
    $identity = trim($identity);
    if ($identity === '') {
        return null;
    }
    if (array_key_exists($identity, $cache)) {
        return $cache[$identity];
    }

    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->bind_param('ss', $identity, $identity);
    $stmt->execute();
    $stmt->bind_result($userId);
    $found = $stmt->fetch();
    $stmt->close();

    $cache[$identity] = $found ? (int) $userId : null;
    return $cache[$identity];
}

function rfidApiLookupUserIdFromDeviceUid(mysqli $conn, string $uid): ?int
{
    static $cache = [];
    if (array_key_exists($uid, $cache)) {
        return $cache[$uid];
    }

    if (!rfidApiTableExists($conn, 'rfid_devices') || !rfidApiColumnExists($conn, 'rfid_devices', 'user_id')) {
        $cache[$uid] = null;
        return null;
    }

    $whereStatus = rfidApiColumnExists($conn, 'rfid_devices', 'status')
        ? " AND status = 'active'"
        : '';
    $stmt = $conn->prepare('SELECT user_id FROM rfid_devices WHERE uid = ?' . $whereStatus . ' LIMIT 1');
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $stmt->bind_result($userId);
    $found = $stmt->fetch();
    $stmt->close();

    $cache[$uid] = $found ? (int) $userId : null;
    return $cache[$uid];
}

function rfidApiLookupLastAttendanceUserId(mysqli $conn, string $uid): ?int
{
    static $cache = [];
    if (array_key_exists($uid, $cache)) {
        return $cache[$uid];
    }

    if (!rfidApiColumnExists($conn, 'attendance', 'user_id')) {
        $cache[$uid] = null;
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT user_id
         FROM attendance
         WHERE uid = ? AND user_id IS NOT NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $stmt->bind_result($userId);
    $found = $stmt->fetch();
    $stmt->close();

    $cache[$uid] = $found ? (int) $userId : null;
    return $cache[$uid];
}

function rfidApiResolveUid(mysqli $conn, string $uid): ?array
{
    $schema = rfidApiSchema($conn);

    if ($schema['has_rfid_cards']) {
        if ($schema['rfid_cards_has_user_id']) {
            $sql = $schema['rfid_cards_has_status']
                ? "SELECT id, name, user_id FROM rfid_cards WHERE uid = ? AND status = 'active' LIMIT 1"
                : 'SELECT id, name, user_id FROM rfid_cards WHERE uid = ? LIMIT 1';
        } else {
            $sql = $schema['rfid_cards_has_status']
                ? "SELECT id, name FROM rfid_cards WHERE uid = ? AND status = 'active' LIMIT 1"
                : 'SELECT id, name FROM rfid_cards WHERE uid = ? LIMIT 1';
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $uid);
        $stmt->execute();
        if ($schema['rfid_cards_has_user_id']) {
            $stmt->bind_result($cardId, $name, $mappedUserId);
            $found = $stmt->fetch();
            $userId = $found ? ($mappedUserId !== null ? (int) $mappedUserId : null) : null;
        } else {
            $stmt->bind_result($cardId, $name);
            $found = $stmt->fetch();
            $userId = null;
        }
        $stmt->close();

        if ($found) {
            if ($userId === null) {
                $userId = rfidApiLookupUserIdFromIdentity($conn, (string) $name);
            }
            if ($userId === null) {
                $userId = rfidApiLookupUserIdFromDeviceUid($conn, $uid);
            }
            if ($userId === null) {
                $userId = rfidApiLookupLastAttendanceUserId($conn, $uid);
            }

            return [
                'name' => (string) $name,
                'card_id' => (int) $cardId,
                'user_id' => $userId,
            ];
        }
    }

    if ($schema['has_rfid_devices']) {
        $whereStatus = $schema['rfid_devices_has_status'] ? " AND d.status = 'active'" : '';
        $stmt = $conn->prepare(
            "SELECT d.user_id, COALESCE(u.username, CONCAT('User #', d.user_id)) AS user_name
             FROM rfid_devices d
             LEFT JOIN users u ON u.id = d.user_id
             WHERE d.uid = ?{$whereStatus}
             LIMIT 1"
        );
        $stmt->bind_param('s', $uid);
        $stmt->execute();
        $stmt->bind_result($userId, $name);
        $found = $stmt->fetch();
        $stmt->close();

        if ($found) {
            return [
                'name' => (string) $name,
                'card_id' => null,
                'user_id' => (int) $userId,
            ];
        }
    }

    return null;
}

function rfidApiHasAttendanceActionToday(
    mysqli $conn,
    string $uid,
    ?int $userId,
    string $date,
    string $action
): bool {
    $useUserId = $userId !== null && rfidApiColumnExists($conn, 'attendance', 'user_id');

    if ($useUserId) {
        $stmt = $conn->prepare('SELECT id FROM attendance WHERE user_id = ? AND date = ? AND action = ? LIMIT 1');
        $stmt->bind_param('iss', $userId, $date, $action);
    } else {
        $stmt = $conn->prepare('SELECT id FROM attendance WHERE uid = ? AND date = ? AND action = ? LIMIT 1');
        $stmt->bind_param('sss', $uid, $date, $action);
    }

    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function rfidApiReleaseUserItems(mysqli $conn, string $table, int $userId): int
{
    if (!rfidApiTableExists($conn, $table) || !rfidApiColumnExists($conn, $table, 'reserved_by')) {
        return 0;
    }

    $setParts = [];
    if (rfidApiColumnExists($conn, $table, 'status')) {
        $setParts[] = "status='available'";
    }
    $setParts[] = 'reserved_by=NULL';
    if (rfidApiColumnExists($conn, $table, 'reserved_at')) {
        $setParts[] = 'reserved_at=NULL';
    }
    if (rfidApiColumnExists($conn, $table, 'expires_at')) {
        $setParts[] = 'expires_at=NULL';
    }
    if (rfidApiColumnExists($conn, $table, 'reservation_status')) {
        $setParts[] = 'reservation_status=NULL';
    }

    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setParts) . ' WHERE reserved_by = ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $affected = max(0, $stmt->affected_rows);
    $stmt->close();

    return $affected;
}

function rfidApiCleanupExpiredPendingTable(mysqli $conn, string $table): int
{
    if (
        !rfidApiTableExists($conn, $table)
        || !rfidApiColumnExists($conn, $table, 'status')
        || !rfidApiColumnExists($conn, $table, 'reserved_by')
        || !rfidApiColumnExists($conn, $table, 'expires_at')
    ) {
        return 0;
    }

    $setParts = [
        "status='available'",
        'reserved_by=NULL',
    ];
    if (rfidApiColumnExists($conn, $table, 'reserved_at')) {
        $setParts[] = 'reserved_at=NULL';
    }
    $setParts[] = 'expires_at=NULL';
    if (rfidApiColumnExists($conn, $table, 'reservation_status')) {
        $setParts[] = 'reservation_status=NULL';
    }

    $where = "status='reserved' AND reserved_by IS NOT NULL AND expires_at IS NOT NULL AND expires_at < NOW()";
    if (rfidApiColumnExists($conn, $table, 'reservation_status')) {
        $where .= " AND reservation_status='pending'";
    }

    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setParts) . ' WHERE ' . $where;
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $affected = max(0, $stmt->affected_rows);
    $stmt->close();

    return $affected;
}

function rfidApiCleanupExpiredPendingReservations(mysqli $conn): array
{
    return [
        'seats' => rfidApiCleanupExpiredPendingTable($conn, 'seats'),
        'computers' => rfidApiCleanupExpiredPendingTable($conn, 'computers'),
    ];
}

function rfidApiConfirmPendingTable(mysqli $conn, string $table, int $userId): int
{
    if (
        !rfidApiTableExists($conn, $table)
        || !rfidApiColumnExists($conn, $table, 'status')
        || !rfidApiColumnExists($conn, $table, 'reserved_by')
        || !rfidApiColumnExists($conn, $table, 'expires_at')
    ) {
        return 0;
    }

    $setParts = ['expires_at=NULL'];
    if (rfidApiColumnExists($conn, $table, 'reservation_status')) {
        $setParts[] = "reservation_status='confirmed'";
    }

    $where = "reserved_by = ? AND status='reserved' AND expires_at IS NOT NULL AND expires_at >= NOW()";
    if (rfidApiColumnExists($conn, $table, 'reservation_status')) {
        $where .= " AND reservation_status='pending'";
    }

    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setParts) . ' WHERE ' . $where;
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $affected = max(0, $stmt->affected_rows);
    $stmt->close();

    return $affected;
}

function rfidApiConfirmPendingReservations(mysqli $conn, int $userId): array
{
    return [
        'seats' => rfidApiConfirmPendingTable($conn, 'seats', $userId),
        'computers' => rfidApiConfirmPendingTable($conn, 'computers', $userId),
    ];
}

function rfidApiPendingSeatLabelsForUser(mysqli $conn, int $userId): array
{
    if (
        !rfidApiTableExists($conn, 'seats')
        || !rfidApiColumnExists($conn, 'seats', 'reserved_by')
        || !rfidApiColumnExists($conn, 'seats', 'status')
        || !rfidApiColumnExists($conn, 'seats', 'expires_at')
        || !rfidApiColumnExists($conn, 'seats', 'seat_number')
    ) {
        return [];
    }

    $where = "reserved_by = ? AND status='reserved' AND expires_at IS NOT NULL AND expires_at >= NOW()";
    if (rfidApiColumnExists($conn, 'seats', 'reservation_status')) {
        $where .= " AND reservation_status='pending'";
    }

    $stmt = $conn->prepare('SELECT seat_number FROM seats WHERE ' . $where . ' ORDER BY id');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $labels = [];
    while ($row = $result->fetch_assoc()) {
        $label = trim((string) ($row['seat_number'] ?? ''));
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    $stmt->close();

    return $labels;
}

function rfidApiCurrentSeatLabelsForUser(mysqli $conn, int $userId): array
{
    if (
        !rfidApiTableExists($conn, 'seats')
        || !rfidApiColumnExists($conn, 'seats', 'reserved_by')
        || !rfidApiColumnExists($conn, 'seats', 'status')
        || !rfidApiColumnExists($conn, 'seats', 'seat_number')
    ) {
        return [];
    }

    $stmt = $conn->prepare("SELECT seat_number FROM seats WHERE reserved_by = ? AND status='reserved' ORDER BY id");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $labels = [];
    while ($row = $result->fetch_assoc()) {
        $label = trim((string) ($row['seat_number'] ?? ''));
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    $stmt->close();

    return $labels;
}

function rfidApiLookupUserContact(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT username, email FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($username, $email);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        return null;
    }

    $email = trim((string) $email);
    if ($email === '') {
        return null;
    }

    $name = trim((string) $username);
    if ($name === '') {
        $name = 'Student';
    }

    return ['name' => $name, 'email' => $email];
}

function rfidApiNormalizeSeatLabelsForEmailKey(array $seatLabels): array
{
    $labels = array_values(array_unique(array_filter(array_map(
        static fn($v): string => trim((string) $v),
        $seatLabels
    ), static fn(string $v): bool => $v !== '')));

    sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
    return $labels;
}

function rfidApiSeatConfirmedEmailKey(int $userId, array $seatLabels, string $timeInAt): string
{
    $labels = rfidApiNormalizeSeatLabelsForEmailKey($seatLabels);
    return hash('sha256', $userId . '|' . trim($timeInAt) . '|' . implode('|', $labels));
}

function rfidApiSeatConfirmedEmailFlagPath(string $emailKey): string
{
    $tmpDir = rtrim((string) sys_get_temp_dir(), '\\/' . DIRECTORY_SEPARATOR);
    if ($tmpDir === '') {
        $tmpDir = __DIR__;
    }

    return $tmpDir . DIRECTORY_SEPARATOR . 'scc_seat_confirm_' . $emailKey . '.flag';
}

function rfidApiSeatConfirmedEmailWasSent(string $emailKey): bool
{
    return is_file(rfidApiSeatConfirmedEmailFlagPath($emailKey));
}

function rfidApiSeatConfirmedEmailMarkSent(string $emailKey): void
{
    @file_put_contents(rfidApiSeatConfirmedEmailFlagPath($emailKey), date('c'));
}

function rfidApiSendSeatConfirmedEmailForUser(
    mysqli $conn,
    int $userId,
    array $pendingSeatLabels,
    string $timeInAt = ''
): void
{
    if (!function_exists('sendSeatConfirmedEmail')) {
        return;
    }

    $contact = rfidApiLookupUserContact($conn, $userId);
    if ($contact === null) {
        return;
    }

    $seatLabels = $pendingSeatLabels;
    if (empty($seatLabels)) {
        $seatLabels = rfidApiCurrentSeatLabelsForUser($conn, $userId);
    }
    if (empty($seatLabels)) {
        return;
    }

    $timeInAt = trim($timeInAt);
    if ($timeInAt === '') {
        $timeInAt = date('Y-m-d H:i:s');
    }

    $emailKey = rfidApiSeatConfirmedEmailKey($userId, $seatLabels, $timeInAt);
    if (rfidApiSeatConfirmedEmailWasSent($emailKey)) {
        return;
    }

    $sent = sendSeatConfirmedEmail(
        $contact['email'],
        $contact['name'],
        $seatLabels,
        'Study Area - Library'
    );

    if (!$sent) {
        error_log('[rfid_api] Seat confirmed email returned false for user_id=' . $userId);
        return;
    }

    rfidApiSeatConfirmedEmailMarkSent($emailKey);
}

function rfidApiAutoReleaseAllocations(mysqli $conn, int $userId): array
{
    return [
        'seats' => rfidApiReleaseUserItems($conn, 'seats', $userId),
        'computers' => rfidApiReleaseUserItems($conn, 'computers', $userId),
    ];
}

function rfidApiBindParams(mysqli_stmt $stmt, string $types, array &$values): void
{
    $params = [$types];
    foreach ($values as $i => &$value) {
        $params[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
}

function rfidApiInsertAttendance(
    mysqli $conn,
    array $card,
    string $uid,
    string $date,
    string $time,
    string $action,
    string $eventId,
    string $device
): int {
    $schema = rfidApiSchema($conn);

    $columns = ['name', 'uid', 'date', 'time', 'action', 'event_id', 'device'];
    $types = 'sssssss';
    $values = [
        (string) $card['name'],
        $uid,
        $date,
        $time,
        $action,
        $eventId,
        $device,
    ];

    if ($schema['attendance_has_card_id'] && $card['card_id'] !== null) {
        $columns[] = 'card_id';
        $types .= 'i';
        $values[] = (int) $card['card_id'];
    }

    if ($schema['attendance_has_user_id'] && $card['user_id'] !== null) {
        $columns[] = 'user_id';
        $types .= 'i';
        $values[] = (int) $card['user_id'];
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO attendance (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

    $stmt = $conn->prepare($sql);
    rfidApiBindParams($stmt, $types, $values);
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();

    return $newId;
}
