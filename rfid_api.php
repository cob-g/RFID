<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'msg' => 'POST only']);
    exit;
}

require_once __DIR__ . '/db.php';

// ── Parse body (JSON preferred, form-encoded fallback for older firmware) ─────
$raw  = (string) file_get_contents('php://input', false, null, 0, 8192);
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;            // fallback — ESP32 application/x-www-form-urlencoded
}
if (!is_array($data) || empty($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Empty or unreadable body']);
    exit;
}

// ── API key (accept from JSON body OR X-API-Key header) ──────────────────────
$apiKey = (string) ($data['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''));
if (!hash_equals(API_KEY, $apiKey)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Invalid API key']);
    exit;
}

// ── Extract + sanitise fields ─────────────────────────────────────────────────
$uid     = strtoupper(trim((string) ($data['uid']      ?? '')));
$date    = trim((string) ($data['date']     ?? ''));
$time    = trim((string) ($data['time']     ?? ''));
$action  = strtoupper(trim((string) ($data['action']   ?? '')));
$eventId = trim((string) ($data['event_id'] ?? ''));
$device  = substr(trim((string) ($data['device'] ?? 'esp32')), 0, 50);

// ── Validate ──────────────────────────────────────────────────────────────────
$required = ['uid' => $uid, 'date' => $date, 'time' => $time,
             'action' => $action, 'event_id' => $eventId];
$missing  = array_keys(array_filter($required, fn($v) => $v === ''));
if ($missing) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'Missing: ' . implode(', ', $missing)]);
    exit;
}
if (!in_array($action, ['TIME_IN', 'TIME_OUT'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'action must be TIME_IN or TIME_OUT']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'date must be YYYY-MM-DD']);
    exit;
}
if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => 'time must be HH:MM:SS']);
    exit;
}

// ── Resolve UID → card (server is the single source of truth for names) ───────
$stmt = $conn->prepare(
    "SELECT id, name FROM rfid_cards WHERE uid = ? AND status = 'active' LIMIT 1"
);
$stmt->bind_param('s', $uid);
$stmt->execute();
$stmt->bind_result($cardId, $cardName);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    // HTTP 200 so ESP32 does NOT queue this as a transient failure
    echo json_encode([
        'status' => 'unknown_uid',
        'msg'    => 'Card not enrolled — visit the library portal to register',
    ]);
    exit;
}

// ── Idempotency: same event_id already stored ─────────────────────────────────
$stmt = $conn->prepare(
    "SELECT id, action FROM attendance WHERE event_id = ? LIMIT 1"
);
$stmt->bind_param('s', $eventId);
$stmt->execute();
$stmt->bind_result($existId, $existAction);
$isDup = $stmt->fetch();
$stmt->close();

if ($isDup) {
    echo json_encode([
        'status' => 'duplicate',
        'msg'    => 'Event already recorded',
        'name'   => $cardName,
        'action' => $existAction,
    ]);
    exit;
}

// ── Guard: already timed out today ────────────────────────────────────────────
if ($action === 'TIME_OUT') {
    $s = $conn->prepare(
        "SELECT id FROM attendance WHERE uid=? AND date=? AND action='TIME_OUT' LIMIT 1"
    );
    $s->bind_param('ss', $uid, $date);
    $s->execute();
    $s->store_result();
    $alreadyOut = $s->num_rows > 0;
    $s->close();

    if ($alreadyOut) {
        echo json_encode([
            'status' => 'already_timed_out',
            'msg'    => $cardName . ' already timed out today',
            'name'   => $cardName,
        ]);
        exit;
    }
}

// ── Insert attendance row ─────────────────────────────────────────────────────
try {
    $stmt = $conn->prepare("
        INSERT INTO attendance
            (card_id, name, uid, date, time, action, event_id, device)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('isssssss',
        $cardId, $cardName, $uid, $date, $time, $action, $eventId, $device
    );
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log('[rfid_api] INSERT failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => 'Failed to record attendance']);
    exit;
}

// ── Return server-resolved name so ESP32 can display it on LCD ───────────────
echo json_encode([
    'status'        => 'ok',
    'msg'           => 'Attendance recorded',
    'name'          => $cardName,   // ← ESP32 uses this for LCD display
    'action'        => $action,
    'attendance_id' => $newId,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);