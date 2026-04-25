<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db.php';

// ── Route ─────────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$data   = [];

if ($method === 'GET') {
    $action = trim($_GET['action'] ?? '');
} else {
    $raw    = (string) file_get_contents('php://input', false, null, 0, 8192);
    $data   = json_decode($raw, true) ?? [];
    $action = trim($data['action'] ?? '');
}

switch ($action) {
    // ── ESP32 web-portal calls ───────────────────────────────────────────
    case 'enroll':   handleDirectEnroll($conn, $data);    break; // portal: add card
    case 'delete':   handleDirectDelete($conn, $data);    break; // portal: remove card
    case 'list':     handleList($conn);                   break; // portal: list cards

    // ── Remote-tap-to-link calls (existing flow, bug-fixed) ─────────────
    case 'request':  handleRequest($conn, $data);         break; // web: create token
    case 'poll':     handlePoll($conn);                   break; // ESP32: any pending?
    case 'submit':   handleSubmit($conn, $data);          break; // ESP32: submit UID
    case 'check':    handleCheck($conn);                  break; // browser: done yet?

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'Unknown or missing action']);
}

// ════════════════════════════════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════════════════════════════════

function checkKey(array $data): bool
{
    $k = (string) ($data['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    return hash_equals(API_KEY, $k);
}

function checkKeyGet(): bool
{
    $k = (string) ($_GET['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    return hash_equals(API_KEY, $k);
}

function validUid(string $uid): bool
{
    return (bool) preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){3}$/', $uid);
}

// ════════════════════════════════════════════════════════════════════════════
//  A. DIRECT ENROLL — ESP32 web portal: POST {action,api_key,name,uid}
//     This is the NEW action that connects your ESP32 portal directly to MySQL.
// ════════════════════════════════════════════════════════════════════════════
function handleDirectEnroll(mysqli $conn, array $data): void
{
    if (!checkKey($data)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'Invalid API key']);
        return;
    }

    $name = substr(trim((string) ($data['name'] ?? '')), 0, 100);
    $uid  = strtoupper(trim((string) ($data['uid']  ?? '')));

    if ($name === '' || $uid === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'name and uid are required']);
        return;
    }
    if (!validUid($uid)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'UID must be AA:BB:CC:DD']);
        return;
    }

    // Duplicate UID check
    $stmt = $conn->prepare(
        "SELECT id, name FROM rfid_cards WHERE uid = ? LIMIT 1"
    );
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $stmt->bind_result($existId, $existName);
    $exists = $stmt->fetch();
    $stmt->close();

    if ($exists) {
        echo json_encode([
            'status' => 'duplicate',
            'msg'    => 'Card already registered to: ' . $existName,
        ]);
        return;
    }

    $stmt = $conn->prepare(
        "INSERT INTO rfid_cards (name, uid) VALUES (?, ?)"
    );
    $stmt->bind_param('ss', $name, $uid);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    echo json_encode([
        'status'  => 'ok',
        'msg'     => $name . ' enrolled successfully',
        'card_id' => $newId,
        'name'    => $name,
        'uid'     => $uid,
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
//  B. DIRECT DELETE — ESP32 web portal: POST {action,api_key,name}
// ════════════════════════════════════════════════════════════════════════════
function handleDirectDelete(mysqli $conn, array $data): void
{
    if (!checkKey($data)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'Invalid API key']);
        return;
    }

    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'name is required']);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM rfid_cards WHERE name = ? LIMIT 1");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    echo json_encode([
        'status' => $deleted > 0 ? 'ok' : 'not_found',
        'msg'    => $deleted > 0 ? $name . ' deleted' : 'Student not found',
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
//  C. LIST — GET ?action=list&api_key=...  (for ESP32 portal student table)
// ════════════════════════════════════════════════════════════════════════════
function handleList(mysqli $conn): void
{
    if (!checkKeyGet()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'Invalid API key']);
        return;
    }

    $result = $conn->query(
        "SELECT id, name, uid, enrolled_at
         FROM rfid_cards WHERE status='active' ORDER BY name ASC"
    );
    $cards = [];
    while ($row = $result->fetch_assoc()) $cards[] = $row;

    echo json_encode(['status' => 'ok', 'cards' => $cards, 'count' => count($cards)]);
}

// ════════════════════════════════════════════════════════════════════════════
//  D. REQUEST — Web portal: POST {action,name} → create enrollment token
//     Called by rfid_link.php (or any web page) to begin remote tap flow.
// ════════════════════════════════════════════════════════════════════════════
function handleRequest(mysqli $conn, array $data): void
{
    // No API key required here — called server-side from your PHP session pages.
    // Add a session/CSRF check if this endpoint is publicly reachable.
    $name = substr(trim((string) ($data['name'] ?? '')), 0, 100);
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'name is required']);
        return;
    }

    // Clean up any stale pending token for this user
    $del = $conn->prepare("DELETE FROM enrollment_tokens WHERE user_name = ?");
    $del->bind_param('s', $name);
    $del->execute();
    $del->close();

    $token     = bin2hex(random_bytes(32));              // 64 hex chars
    $expiresAt = date('Y-m-d H:i:s', time() + 120);     // 2-minute window

    $ins = $conn->prepare(
        "INSERT INTO enrollment_tokens (user_name, token, expires_at) VALUES (?,?,?)"
    );
    $ins->bind_param('sss', $name, $token, $expiresAt);
    $ins->execute();
    $ins->close();

    echo json_encode(['status' => 'ok', 'token' => $token, 'expires_in' => 120]);
}

// ════════════════════════════════════════════════════════════════════════════
//  E. POLL — ESP32: GET ?action=poll&api_key=...
//     "Is there a pending remote enrollment waiting for a card tap?"
// ════════════════════════════════════════════════════════════════════════════
function handlePoll(mysqli $conn): void
{
    if (!checkKeyGet()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'Invalid API key']);
        return;
    }

    // Sweep expired tokens
    $conn->query(
        "UPDATE enrollment_tokens SET status='expired'
         WHERE status='pending' AND expires_at < NOW()"
    );

    $stmt = $conn->prepare("
        SELECT token, user_name,
               GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS secs_left
        FROM   enrollment_tokens
        WHERE  status='pending' AND expires_at > NOW()
        ORDER  BY created_at ASC
        LIMIT  1
    ");
    $stmt->execute();
    $stmt->bind_result($token, $userName, $secsLeft);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        echo json_encode(['pending' => false]);
        return;
    }

    echo json_encode([
        'pending'    => true,
        'token'      => $token,
        'username'   => $userName,
        'expires_in' => max(5, min(300, (int) $secsLeft)),
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
//  F. SUBMIT — ESP32: POST {action,api_key,token,uid}
//     Card was tapped; link it to the pending enrollment token.
// ════════════════════════════════════════════════════════════════════════════
function handleSubmit(mysqli $conn, array $data): void
{
    if (!checkKey($data)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'Invalid API key']);
        return;
    }

    $token = trim((string) ($data['token'] ?? ''));
    $uid   = strtoupper(trim((string) ($data['uid']   ?? '')));

    if ($token === '' || $uid === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'token and uid are required']);
        return;
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'Invalid token format']);
        return;
    }
    if (!validUid($uid)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'UID must be AA:BB:CC:DD']);
        return;
    }

    // Sweep expired
    $conn->query(
        "UPDATE enrollment_tokens SET status='expired'
         WHERE status='pending' AND expires_at < NOW()"
    );

    $stmt = $conn->prepare(
        "SELECT id, user_name, status, expires_at FROM enrollment_tokens WHERE token=? LIMIT 1"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->bind_result($tokenId, $userName, $tokenStatus, $expiresAt);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        echo json_encode(['status' => 'error', 'msg' => 'Token not found']);
        return;
    }
    if ($tokenStatus === 'fulfilled') {
        echo json_encode(['status' => 'duplicate', 'msg' => 'Token already used']);
        return;
    }
    if ($tokenStatus === 'expired' || strtotime($expiresAt) < time()) {
        $conn->query("UPDATE enrollment_tokens SET status='expired' WHERE id=" . (int) $tokenId);
        echo json_encode(['status' => 'expired', 'msg' => 'Enrollment window expired — try again']);
        return;
    }

    // UID already registered?
    $s = $conn->prepare(
        "SELECT id FROM rfid_cards WHERE uid=? AND status='active' LIMIT 1"
    );
    $s->bind_param('s', $uid);
    $s->execute();
    $s->store_result();
    $isDup = $s->num_rows > 0;
    $s->close();

    if ($isDup) {
        echo json_encode(['status' => 'duplicate', 'msg' => 'Card already registered to another student']);
        return;
    }

    // Atomic: insert card + mark token fulfilled
    try {
        $conn->begin_transaction();

        $ins = $conn->prepare("INSERT INTO rfid_cards (name, uid) VALUES (?, ?)");
        $ins->bind_param('ss', $userName, $uid);
        $ins->execute();
        $ins->close();

        $upd = $conn->prepare("UPDATE enrollment_tokens SET status='fulfilled' WHERE id=?");
        $upd->bind_param('i', $tokenId);
        $upd->execute();
        $upd->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log('[enroll_api/submit] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => 'Database error — please try again']);
        return;
    }

    echo json_encode([
        'status' => 'ok',
        'msg'    => 'Card linked for ' . $userName,
        'name'   => $userName,
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
//  G. CHECK — Browser: GET ?action=check&token=<hex64>
//     "Has the ESP32 tapped the card yet?"
// ════════════════════════════════════════════════════════════════════════════
function handleCheck(mysqli $conn): void
{
    $token = trim($_GET['token'] ?? '');
    if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'Invalid token']);
        return;
    }

    $stmt = $conn->prepare(
        "SELECT status FROM enrollment_tokens WHERE token=? LIMIT 1"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->bind_result($tokenStatus);
    $found = $stmt->fetch();
    $stmt->close();

    echo json_encode($found
        ? ['status' => $tokenStatus]
        : ['status' => 'expired', 'msg' => 'Token not found']
    );
}