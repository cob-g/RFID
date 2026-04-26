<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/db.php';

function enrollApiRespond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function enrollApiCheckKey(array $data): bool
{
    $provided = (string) ($data['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    return hash_equals(API_KEY, $provided);
}

function enrollApiCheckKeyGet(array $query): bool
{
    $provided = (string) ($query['api_key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    return hash_equals(API_KEY, $provided);
}

function enrollApiValidUid(string $uid): bool
{
    return (bool) preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){3}$/', $uid);
}

function enrollApiTableExists(mysqli $conn, string $table): bool
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

function enrollApiColumnExists(mysqli $conn, string $table, string $column): bool
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

function enrollApiSchema(mysqli $conn): array
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }

    $schema = [
        'has_rfid_cards' => enrollApiTableExists($conn, 'rfid_cards'),
        'has_rfid_devices' => enrollApiTableExists($conn, 'rfid_devices'),
        'has_enrollment_tokens' => enrollApiTableExists($conn, 'enrollment_tokens'),
        'has_pending_assignments' => enrollApiTableExists($conn, 'pending_rfid_assignments'),
    ];

    $schema['rfid_cards_has_status'] = $schema['has_rfid_cards']
        ? enrollApiColumnExists($conn, 'rfid_cards', 'status')
        : false;
    $schema['rfid_cards_has_user_id'] = $schema['has_rfid_cards']
        ? enrollApiColumnExists($conn, 'rfid_cards', 'user_id')
        : false;
    $schema['rfid_cards_has_enrolled_at'] = $schema['has_rfid_cards']
        ? enrollApiColumnExists($conn, 'rfid_cards', 'enrolled_at')
        : false;
    $schema['rfid_cards_has_created_at'] = $schema['has_rfid_cards']
        ? enrollApiColumnExists($conn, 'rfid_cards', 'created_at')
        : false;

    $schema['rfid_devices_has_status'] = $schema['has_rfid_devices']
        ? enrollApiColumnExists($conn, 'rfid_devices', 'status')
        : false;
    $schema['rfid_devices_has_created_at'] = $schema['has_rfid_devices']
        ? enrollApiColumnExists($conn, 'rfid_devices', 'created_at')
        : false;

    $schema['enrollment_has_status'] = $schema['has_enrollment_tokens']
        ? enrollApiColumnExists($conn, 'enrollment_tokens', 'status')
        : false;
    $schema['enrollment_has_user_id'] = $schema['has_enrollment_tokens']
        ? enrollApiColumnExists($conn, 'enrollment_tokens', 'user_id')
        : false;
    $schema['enrollment_has_created_at'] = $schema['has_enrollment_tokens']
        ? enrollApiColumnExists($conn, 'enrollment_tokens', 'created_at')
        : false;

    $schema['pending_has_status'] = $schema['has_pending_assignments']
        ? enrollApiColumnExists($conn, 'pending_rfid_assignments', 'status')
        : false;
    $schema['pending_has_fulfilled'] = $schema['has_pending_assignments']
        ? enrollApiColumnExists($conn, 'pending_rfid_assignments', 'fulfilled')
        : false;
    $schema['pending_has_created_at'] = $schema['has_pending_assignments']
        ? enrollApiColumnExists($conn, 'pending_rfid_assignments', 'created_at')
        : false;

    return $schema;
}

function enrollApiFindUserByUsername(mysqli $conn, string $username): ?array
{
    static $cache = [];
    $username = trim($username);
    if ($username === '') {
        return null;
    }

    // Normalize cache key so the same identifier in different casing
    // does not trigger duplicate lookups.
    $cacheKey = strtolower($username);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $usersHasEmail = enrollApiColumnExists($conn, 'users', 'email');
    if ($usersHasEmail) {
        $stmt = $conn->prepare(
            'SELECT id, username FROM users WHERE username = ? OR email = ? LIMIT 1'
        );
        $stmt->bind_param('ss', $username, $username);
    } else {
        $stmt = $conn->prepare('SELECT id, username FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
    }

    $stmt->execute();
    $stmt->bind_result($userId, $canonicalUsername);
    $found = $stmt->fetch();
    $stmt->close();

    $cache[$cacheKey] = $found
        ? ['id' => (int) $userId, 'username' => (string) $canonicalUsername]
        : null;

    return $cache[$cacheKey];
}

function enrollApiExpireTokens(mysqli $conn, array $schema): void
{
    if ($schema['has_enrollment_tokens'] && $schema['enrollment_has_status']) {
        $conn->query(
            "UPDATE enrollment_tokens SET status='expired' WHERE status='pending' AND expires_at < NOW()"
        );
    }

    if ($schema['has_pending_assignments'] && $schema['pending_has_status']) {
        $conn->query(
            "UPDATE pending_rfid_assignments SET status='expired' WHERE status='pending' AND expires_at < NOW()"
        );
    }
}

function enrollApiFindToken(mysqli $conn, string $token, array $schema): ?array
{
    if ($schema['has_enrollment_tokens']) {
        $userIdExpr = $schema['enrollment_has_user_id'] ? 'user_id' : 'NULL AS user_id';
        $statusExpr = $schema['enrollment_has_status'] ? 'status' : "'pending' AS status";
        $stmt = $conn->prepare(
            "SELECT id, user_name, {$userIdExpr}, {$statusExpr}, expires_at
             FROM enrollment_tokens
             WHERE token=?
             LIMIT 1"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->bind_result($id, $userName, $userId, $status, $expiresAt);
        $found = $stmt->fetch();
        $stmt->close();

        if ($found) {
            return [
                'source' => 'enrollment_tokens',
                'id' => (int) $id,
                'user_id' => $userId !== null ? (int) $userId : null,
                'user_name' => (string) $userName,
                'status' => strtolower((string) $status),
                'fulfilled' => 0,
                'expires_at' => (string) $expiresAt,
            ];
        }
    }

    if ($schema['has_pending_assignments']) {
        $statusExpr = $schema['pending_has_status'] ? 'p.status' : "'pending'";
        $fulfilledExpr = $schema['pending_has_fulfilled'] ? 'p.fulfilled' : '0';
        $stmt = $conn->prepare(
            "SELECT p.id,
                    p.user_id,
                    COALESCE(u.username, CONCAT('User #', p.user_id)) AS user_name,
                    {$statusExpr} AS status,
                    {$fulfilledExpr} AS fulfilled,
                    p.expires_at
             FROM pending_rfid_assignments p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.token = ?
             LIMIT 1"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->bind_result($id, $userId, $userName, $status, $fulfilled, $expiresAt);
        $found = $stmt->fetch();
        $stmt->close();

        if ($found) {
            return [
                'source' => 'pending_rfid_assignments',
                'id' => (int) $id,
                'user_id' => (int) $userId,
                'user_name' => (string) $userName,
                'status' => strtolower((string) $status),
                'fulfilled' => (int) $fulfilled,
                'expires_at' => (string) $expiresAt,
            ];
        }
    }

    return null;
}

function enrollApiNormalizedTokenStatus(array $token): string
{
    $status = strtolower((string) ($token['status'] ?? 'pending'));
    if ($status === 'fulfilled' || (int) ($token['fulfilled'] ?? 0) === 1) {
        return 'fulfilled';
    }

    if ($status === 'expired') {
        return 'expired';
    }

    $expiresAt = (string) ($token['expires_at'] ?? '');
    if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
        return 'expired';
    }

    return 'pending';
}

function enrollApiMarkTokenExpired(mysqli $conn, array $token, array $schema): void
{
    if ($token['source'] === 'enrollment_tokens') {
        if ($schema['enrollment_has_status']) {
            $stmt = $conn->prepare("UPDATE enrollment_tokens SET status='expired' WHERE id=?");
            $stmt->bind_param('i', $token['id']);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    $updates = [];
    if ($schema['pending_has_status']) {
        $updates[] = "status='expired'";
    }
    if (!$updates) {
        return;
    }

    $sql = 'UPDATE pending_rfid_assignments SET ' . implode(', ', $updates) . ' WHERE id=?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $token['id']);
    $stmt->execute();
    $stmt->close();
}

function enrollApiMarkTokenFulfilled(mysqli $conn, array $token, array $schema): void
{
    if ($token['source'] === 'enrollment_tokens') {
        if ($schema['enrollment_has_status']) {
            $stmt = $conn->prepare("UPDATE enrollment_tokens SET status='fulfilled' WHERE id=?");
            $stmt->bind_param('i', $token['id']);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    $updates = [];
    if ($schema['pending_has_status']) {
        $updates[] = "status='fulfilled'";
    }
    if ($schema['pending_has_fulfilled']) {
        $updates[] = 'fulfilled=1';
    }
    if (!$updates) {
        return;
    }

    $sql = 'UPDATE pending_rfid_assignments SET ' . implode(', ', $updates) . ' WHERE id=?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $token['id']);
    $stmt->execute();
    $stmt->close();
}

function enrollApiUidExists(mysqli $conn, string $uid, array $schema): bool
{
    if ($schema['has_rfid_cards']) {
        $sql = $schema['rfid_cards_has_status']
            ? "SELECT id FROM rfid_cards WHERE uid=? AND status='active' LIMIT 1"
            : 'SELECT id FROM rfid_cards WHERE uid=? LIMIT 1';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $uid);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        if ($exists) {
            return true;
        }
    }

    if ($schema['has_rfid_devices']) {
        $sql = $schema['rfid_devices_has_status']
            ? "SELECT id FROM rfid_devices WHERE uid=? AND status='active' LIMIT 1"
            : 'SELECT id FROM rfid_devices WHERE uid=? LIMIT 1';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $uid);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        if ($exists) {
            return true;
        }
    }

    return false;
}

function enrollApiUserHasActiveCard(mysqli $conn, int $userId, array $schema): bool
{
    if ($schema['has_rfid_cards'] && $schema['rfid_cards_has_user_id']) {
        $sql = $schema['rfid_cards_has_status']
            ? "SELECT id FROM rfid_cards WHERE user_id=? AND status='active' LIMIT 1"
            : 'SELECT id FROM rfid_cards WHERE user_id=? LIMIT 1';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        if ($exists) {
            return true;
        }
    }

    if ($schema['has_rfid_devices']) {
        $sql = $schema['rfid_devices_has_status']
            ? "SELECT id FROM rfid_devices WHERE user_id=? AND status='active' LIMIT 1"
            : 'SELECT id FROM rfid_devices WHERE user_id=? LIMIT 1';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        if ($exists) {
            return true;
        }
    }

    return false;
}

function enrollApiInsertCardMapping(mysqli $conn, array $schema, int $userId, string $userName, string $uid): int
{
    if ($schema['has_rfid_cards']) {
        if (!$schema['rfid_cards_has_user_id']) {
            throw new RuntimeException('rfid_cards.user_id column is required for account-bound enrollment');
        }

        if ($schema['rfid_cards_has_status']) {
            $ins = $conn->prepare(
                "INSERT INTO rfid_cards (name, uid, user_id, status) VALUES (?, ?, ?, 'active')"
            );
        } else {
            $ins = $conn->prepare('INSERT INTO rfid_cards (name, uid, user_id) VALUES (?, ?, ?)');
        }
        $ins->bind_param('ssi', $userName, $uid, $userId);
        $ins->execute();
        $newId = (int) $ins->insert_id;
        $ins->close();

        return $newId;
    }

    if ($schema['has_rfid_devices']) {
        if ($schema['rfid_devices_has_status']) {
            $ins = $conn->prepare("INSERT INTO rfid_devices (uid, user_id, status) VALUES (?, ?, 'active')");
        } else {
            $ins = $conn->prepare('INSERT INTO rfid_devices (uid, user_id) VALUES (?, ?)');
        }
        $ins->bind_param('si', $uid, $userId);
        $ins->execute();
        $newId = (int) $ins->insert_id;
        $ins->close();

        return $newId;
    }

    throw new RuntimeException('RFID mapping tables are not configured');
}

function enrollApiHandleDirectEnroll(mysqli $conn, array $body): void
{
    if (!enrollApiCheckKey($body)) {
        enrollApiRespond(['status' => 'error', 'msg' => 'Invalid API key'], 401);
        return;
    }

    $name = substr(trim((string) ($body['name'] ?? '')), 0, 100);
    $uid = strtoupper(trim((string) ($body['uid'] ?? '')));

    if ($name === '' || $uid === '') {
        enrollApiRespond(['status' => 'error', 'msg' => 'name and uid are required'], 400);
        return;
    }
    if (!enrollApiValidUid($uid)) {
        enrollApiRespond(['status' => 'error', 'msg' => 'UID must be AA:BB:CC:DD'], 400);
        return;
    }

    $user = enrollApiFindUserByUsername($conn, $name);
    if (!$user) {
        enrollApiRespond(['status' => 'not_found', 'msg' => 'Student account not found'], 404);
        return;
    }

    $userId = (int) $user['id'];
    $userName = (string) $user['username'];

    $schema = enrollApiSchema($conn);

    if (enrollApiUidExists($conn, $uid, $schema)) {
        enrollApiRespond([
            'status' => 'duplicate',
            'msg' => 'Card already registered to another student',
        ]);
        return;
    }

    if (enrollApiUserHasActiveCard($conn, $userId, $schema)) {
        enrollApiRespond([
            'status' => 'duplicate_user',
            'msg' => 'This account already has an active RFID card',
        ]);
        return;
    }

    try {
        $newId = enrollApiInsertCardMapping($conn, $schema, $userId, $userName, $uid);
    } catch (Throwable $e) {
        error_log('[enroll_api/direct_enroll] ' . $e->getMessage());
        enrollApiRespond(['status' => 'error', 'msg' => 'RFID mapping schema is not ready'], 500);
        return;
    }

    enrollApiRespond([
        'status' => 'ok',
        'msg' => $userName . ' enrolled successfully',
        'card_id' => $newId,
        'name' => $userName,
        'uid' => $uid,
    ]);
}

function enrollApiHandleDirectDelete(mysqli $conn, array $body): void
{
    if (!enrollApiCheckKey($body)) {
        enrollApiRespond(['status' => 'error', 'msg' => 'Invalid API key'], 401);
        return;
    }

    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        enrollApiRespond(['status' => 'error', 'msg' => 'name is required'], 400);
        return;
    }

    $user = enrollApiFindUserByUsername($conn, $name);
    if (!$user) {
        enrollApiRespond(['status' => 'not_found', 'msg' => 'Student account not found'], 404);
        return;
    }

    $userId = (int) $user['id'];
    $schema = enrollApiSchema($conn);
    $deleted = 0;

    if ($schema['has_rfid_cards']) {
        if ($schema['rfid_cards_has_user_id']) {
            $stmt = $conn->prepare('DELETE FROM rfid_cards WHERE user_id = ? LIMIT 1');
            $stmt->bind_param('i', $userId);
        } else {
            $stmt = $conn->prepare('DELETE FROM rfid_cards WHERE name = ? LIMIT 1');
            $stmt->bind_param('s', $name);
        }
        $stmt->execute();
        $deleted += max(0, $stmt->affected_rows);
        $stmt->close();
    }

    if ($schema['has_rfid_devices']) {
        $stmt = $conn->prepare('DELETE FROM rfid_devices WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $deleted += max(0, $stmt->affected_rows);
        $stmt->close();
    }

    enrollApiRespond([
        'status' => $deleted > 0 ? 'ok' : 'not_found',
        'msg' => $deleted > 0 ? $name . ' deleted' : 'Student not found',
    ]);
}

function enrollApiHandleList(mysqli $conn, array $query, array $body): void
{
    $keyData = $body ?: $query;
    if (!enrollApiCheckKey($keyData) && !enrollApiCheckKeyGet($query)) {
        enrollApiRespond(['status' => 'error', 'msg' => 'Invalid API key'], 401);
        return;
    }

    $schema = enrollApiSchema($conn);
    $cards = [];
    $seenUids = [];

    if ($schema['has_rfid_cards']) {
        if ($schema['rfid_cards_has_user_id']) {
            $where = $schema['rfid_cards_has_status'] ? " WHERE c.status='active'" : '';
            $enrolledExpr = 'NULL AS enrolled_at';
            if ($schema['rfid_cards_has_enrolled_at']) {
                $enrolledExpr = 'c.enrolled_at';
            } elseif ($schema['rfid_cards_has_created_at']) {
                $enrolledExpr = 'c.created_at AS enrolled_at';
            }

            $sql = "SELECT c.id,
                           COALESCE(u.username, c.name, CONCAT('User #', c.user_id)) AS name,
                           c.uid,
                           {$enrolledExpr}
                    FROM rfid_cards c
                    LEFT JOIN users u ON u.id = c.user_id
                    {$where}
                    ORDER BY name ASC";
        } else {
            $where = $schema['rfid_cards_has_status'] ? " WHERE status='active'" : '';
            $enrolledExpr = 'NULL AS enrolled_at';
            if ($schema['rfid_cards_has_enrolled_at']) {
                $enrolledExpr = 'enrolled_at';
            } elseif ($schema['rfid_cards_has_created_at']) {
                $enrolledExpr = 'created_at AS enrolled_at';
            }

            $sql = "SELECT id, name, uid, {$enrolledExpr} FROM rfid_cards{$where} ORDER BY name ASC";
        }

        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) {
            $uid = strtoupper(trim((string) ($row['uid'] ?? '')));
            if ($uid !== '') {
                $seenUids[$uid] = true;
            }
            $cards[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'uid' => $uid,
                'enrolled_at' => $row['enrolled_at'] ?? null,
            ];
        }
    }

    if ($schema['has_rfid_devices']) {
        $where = [];
        if ($schema['rfid_devices_has_status']) {
            $where[] = "d.status='active'";
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        $enrolledExpr = $schema['rfid_devices_has_created_at']
            ? 'd.created_at AS enrolled_at'
            : 'NULL AS enrolled_at';

        $sql = "SELECT d.id,
                       COALESCE(u.username, CONCAT('User #', d.user_id)) AS name,
                       d.uid,
                       {$enrolledExpr}
                FROM rfid_devices d
                LEFT JOIN users u ON u.id = d.user_id
                {$whereSql}
                ORDER BY name ASC";
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) {
            $uid = strtoupper(trim((string) ($row['uid'] ?? '')));
            if ($uid !== '' && isset($seenUids[$uid])) {
                continue;
            }
            $cards[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'uid' => $uid,
                'enrolled_at' => $row['enrolled_at'] ?? null,
            ];
        }
    }

    usort($cards, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

    enrollApiRespond(['status' => 'ok', 'cards' => $cards, 'count' => count($cards)]);
}

function enrollApiHandleRequestToken(mysqli $conn, array $body): void
{
    $name = substr(trim((string) ($body['name'] ?? '')), 0, 100);
    if ($name === '') {
        enrollApiRespond(['status' => 'error', 'msg' => 'name is required'], 400);
        return;
    }

    $user = enrollApiFindUserByUsername($conn, $name);
    if (!$user) {
        enrollApiRespond(['status' => 'not_found', 'msg' => 'Student account not found'], 404);
        return;
    }

    $userId = (int) $user['id'];
    $userName = (string) $user['username'];

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 120);
    $schema = enrollApiSchema($conn);

    if ($schema['has_enrollment_tokens']) {
        if ($schema['enrollment_has_user_id']) {
            $del = $conn->prepare('DELETE FROM enrollment_tokens WHERE user_id = ?');
            $del->bind_param('i', $userId);
        } else {
            $del = $conn->prepare('DELETE FROM enrollment_tokens WHERE user_name = ?');
            $del->bind_param('s', $userName);
        }
        $del->execute();
        $del->close();

        if ($schema['enrollment_has_user_id'] && $schema['enrollment_has_status']) {
            $ins = $conn->prepare(
                "INSERT INTO enrollment_tokens (user_id, user_name, token, expires_at, status)
                 VALUES (?, ?, ?, ?, 'pending')"
            );
            $ins->bind_param('isss', $userId, $userName, $token, $expiresAt);
        } elseif ($schema['enrollment_has_user_id']) {
            $ins = $conn->prepare(
                'INSERT INTO enrollment_tokens (user_id, user_name, token, expires_at) VALUES (?, ?, ?, ?)'
            );
            $ins->bind_param('isss', $userId, $userName, $token, $expiresAt);
        } elseif ($schema['enrollment_has_status']) {
            $ins = $conn->prepare(
                "INSERT INTO enrollment_tokens (user_name, token, expires_at, status) VALUES (?, ?, ?, 'pending')"
            );
            $ins->bind_param('sss', $userName, $token, $expiresAt);
        } else {
            $ins = $conn->prepare('INSERT INTO enrollment_tokens (user_name, token, expires_at) VALUES (?, ?, ?)');
            $ins->bind_param('sss', $userName, $token, $expiresAt);
        }
        $ins->execute();
        $ins->close();

        enrollApiRespond(['status' => 'ok', 'token' => $token, 'expires_in' => 120]);
        return;
    }

    if ($schema['has_pending_assignments']) {
        $del = $conn->prepare('DELETE FROM pending_rfid_assignments WHERE user_id = ?');
        $del->bind_param('i', $userId);
        $del->execute();
        $del->close();

        if ($schema['pending_has_status'] && $schema['pending_has_fulfilled']) {
            $ins = $conn->prepare("INSERT INTO pending_rfid_assignments (user_id, token, expires_at, status, fulfilled) VALUES (?, ?, ?, 'pending', 0)");
        } elseif ($schema['pending_has_status']) {
            $ins = $conn->prepare("INSERT INTO pending_rfid_assignments (user_id, token, expires_at, status) VALUES (?, ?, ?, 'pending')");
        } elseif ($schema['pending_has_fulfilled']) {
            $ins = $conn->prepare('INSERT INTO pending_rfid_assignments (user_id, token, expires_at, fulfilled) VALUES (?, ?, ?, 0)');
        } else {
            $ins = $conn->prepare('INSERT INTO pending_rfid_assignments (user_id, token, expires_at) VALUES (?, ?, ?)');
        }
        $ins->bind_param('iss', $userId, $token, $expiresAt);
        $ins->execute();
        $ins->close();

        enrollApiRespond(['status' => 'ok', 'token' => $token, 'expires_in' => 120]);
        return;
    }

    enrollApiRespond(['status' => 'error', 'msg' => 'Enrollment token tables are not configured'], 500);
}

function enrollApiHandlePoll(mysqli $conn, array $query, array $body): void
{
    $keyData = $body ?: $query;
    if (!enrollApiCheckKey($keyData) && !enrollApiCheckKeyGet($query)) {
        enrollApiRespond(['status' => 'error', 'msg' => 'Invalid API key'], 401);
        return;
    }

    $schema = enrollApiSchema($conn);
    enrollApiExpireTokens($conn, $schema);

    if ($schema['has_enrollment_tokens']) {
        $where = $schema['enrollment_has_status']
            ? "status='pending' AND expires_at > NOW()"
            : 'expires_at > NOW()';
        $orderBy = $schema['enrollment_has_created_at'] ? 'created_at' : 'id';

        $stmt = $conn->prepare(
            "SELECT token,
                    user_name,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS secs_left
             FROM enrollment_tokens
             WHERE {$where}
             ORDER BY {$orderBy} ASC
             LIMIT 1"
        );
        $stmt->execute();
        $stmt->bind_result($token, $userName, $secsLeft);
        $found = $stmt->fetch();
        $stmt->close();

        if ($found) {
            enrollApiRespond([
                'pending' => true,
                'token' => $token,
                'username' => $userName,
                'expires_in' => max(5, min(300, (int) $secsLeft)),
            ]);
            return;
        }
    }

    if ($schema['has_pending_assignments']) {
        $where = ['p.expires_at > NOW()'];
        if ($schema['pending_has_status']) {
            $where[] = "p.status='pending'";
        }
        if ($schema['pending_has_fulfilled']) {
            $where[] = 'p.fulfilled=0';
        }
        $orderBy = $schema['pending_has_created_at'] ? 'p.created_at' : 'p.id';

        $stmt = $conn->prepare(
            "SELECT p.token,
                    COALESCE(u.username, CONCAT('User #', p.user_id)) AS user_name,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), p.expires_at)) AS secs_left
             FROM pending_rfid_assignments p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY {$orderBy} ASC
             LIMIT 1"
        );
        $stmt->execute();
        $stmt->bind_result($token, $userName, $secsLeft);
        $found = $stmt->fetch();
        $stmt->close();

        if ($found) {
            enrollApiRespond([
                'pending' => true,
                'token' => $token,
                'username' => $userName,
                'expires_in' => max(5, min(300, (int) $secsLeft)),
            ]);
            return;
        }
    }

    enrollApiRespond(['pending' => false]);
}

function enrollApiHandleSubmit(mysqli $conn, array $body): void
{
    if (!enrollApiCheckKey($body)) {
        enrollApiRespond(['status' => 'error', 'msg' => 'Invalid API key'], 401);
        return;
    }

    $token = strtolower(trim((string) ($body['token'] ?? '')));
    $uid = strtoupper(trim((string) ($body['uid'] ?? '')));

    if ($token === '' || $uid === '') {
        enrollApiRespond(['status' => 'error', 'msg' => 'token and uid are required'], 400);
        return;
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        enrollApiRespond(['status' => 'error', 'msg' => 'Invalid token format'], 400);
        return;
    }
    if (!enrollApiValidUid($uid)) {
        enrollApiRespond(['status' => 'error', 'msg' => 'UID must be AA:BB:CC:DD'], 400);
        return;
    }

    $schema = enrollApiSchema($conn);
    enrollApiExpireTokens($conn, $schema);

    $tokenRow = enrollApiFindToken($conn, $token, $schema);
    if (!$tokenRow) {
        enrollApiRespond(['status' => 'error', 'msg' => 'Token not found']);
        return;
    }

    $tokenStatus = enrollApiNormalizedTokenStatus($tokenRow);
    if ($tokenStatus === 'fulfilled') {
        enrollApiRespond(['status' => 'duplicate', 'msg' => 'Token already used']);
        return;
    }
    if ($tokenStatus === 'expired') {
        enrollApiMarkTokenExpired($conn, $tokenRow, $schema);
        enrollApiRespond(['status' => 'expired', 'msg' => 'Enrollment window expired - try again']);
        return;
    }

    if (enrollApiUidExists($conn, $uid, $schema)) {
        enrollApiRespond([
            'status' => 'duplicate',
            'msg' => 'Card already registered to another student',
        ]);
        return;
    }

    $userId = (int) ($tokenRow['user_id'] ?? 0);
    $userName = (string) ($tokenRow['user_name'] ?? '');
    if ($userId <= 0) {
        $user = enrollApiFindUserByUsername($conn, $userName);
        if (!$user) {
            enrollApiRespond([
                'status' => 'not_found',
                'msg' => 'Student account not found for this enrollment token',
            ], 404);
            return;
        }
        $userId = (int) $user['id'];
        $userName = (string) $user['username'];
    }

    if (enrollApiUserHasActiveCard($conn, $userId, $schema)) {
        enrollApiRespond([
            'status' => 'duplicate_user',
            'msg' => 'This account already has an active RFID card',
        ]);
        return;
    }

    try {
        $conn->begin_transaction();

        enrollApiInsertCardMapping($conn, $schema, $userId, $userName, $uid);

        enrollApiMarkTokenFulfilled($conn, $tokenRow, $schema);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[enroll_api/submit] ' . $e->getMessage());
        enrollApiRespond(['status' => 'error', 'msg' => 'Database error - please try again'], 500);
        return;
    }

    enrollApiRespond([
        'status' => 'ok',
        'msg' => 'Card linked for ' . $tokenRow['user_name'],
        'name' => $tokenRow['user_name'],
    ]);
}

function enrollApiHandleCheck(mysqli $conn, array $query): void
{
    $token = strtolower(trim((string) ($query['token'] ?? '')));
    if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        enrollApiRespond(['status' => 'error', 'msg' => 'Invalid token'], 400);
        return;
    }

    $schema = enrollApiSchema($conn);
    enrollApiExpireTokens($conn, $schema);

    $tokenRow = enrollApiFindToken($conn, $token, $schema);
    if (!$tokenRow) {
        enrollApiRespond(['status' => 'expired', 'msg' => 'Token not found']);
        return;
    }

    $status = enrollApiNormalizedTokenStatus($tokenRow);
    if ($status === 'expired') {
        enrollApiMarkTokenExpired($conn, $tokenRow, $schema);
    }

    enrollApiRespond(['status' => $status]);
}

function enrollApiHandleRequest(mysqli $conn, string $method, array $query, array $body): void
{
    $method = strtoupper($method);
    $action = $method === 'GET'
        ? strtolower(trim((string) ($query['action'] ?? '')))
        : strtolower(trim((string) ($body['action'] ?? ($query['action'] ?? ''))));

    switch ($action) {
        case 'enroll':
            enrollApiHandleDirectEnroll($conn, $body);
            break;

        case 'delete':
            enrollApiHandleDirectDelete($conn, $body);
            break;

        case 'list':
            enrollApiHandleList($conn, $query, $body);
            break;

        case 'request':
            enrollApiHandleRequestToken($conn, $body);
            break;

        case 'poll':
            enrollApiHandlePoll($conn, $query, $body);
            break;

        case 'submit':
            enrollApiHandleSubmit($conn, $body);
            break;

        case 'check':
            enrollApiHandleCheck($conn, $query);
            break;

        default:
            enrollApiRespond(['status' => 'error', 'msg' => 'Unknown or missing action'], 400);
    }
}

if (!defined('ENROLL_API_LIBRARY_MODE')) {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $query = $_GET;
    $body = [];

    if ($method !== 'GET') {
        $raw = (string) file_get_contents('php://input', false, null, 0, 8192);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        } elseif (!empty($_POST) && is_array($_POST)) {
            $body = $_POST;
        }
    }

    enrollApiHandleRequest($conn, $method, $query, $body);
    exit;
}
