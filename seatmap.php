<?php
session_start();
ob_start();

ini_set('display_errors', 0);
ini_set('log_errors',     1);
error_reporting(E_ALL);

require_once 'auth.php';
require_once 'db.php';
require_once 'email_helper.php';

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

date_default_timezone_set('Asia/Manila');
requireRole('student', 'faculty');

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id       = (int) $_SESSION['user_id'];
$isAjaxRequest = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']));

const RESERVATION_CONFIRM_WINDOW_MINUTES = 15;

function isUserTimedInToday(mysqli $conn, int $userId): bool
{
    $latestAction = seatSystemLatestAttendanceActionToday($conn, $userId);
    return $latestAction === 'TIME_IN';
}

function seatSystemLatestAttendanceActionToday(mysqli $conn, int $userId): ?string
{
    try {
        $stmt = $conn->prepare(
            'SELECT action
             FROM attendance
             WHERE user_id = ? AND date = CURDATE()
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $action = strtoupper(trim((string) ($row['action'] ?? '')));
        if ($action === 'TIME_IN' || $action === 'TIME_OUT') {
            return $action;
        }

        return null;
    } catch (Throwable $e) {
        error_log('[SeatSystem] timed-in check failed: ' . $e->getMessage());
        return null;
    }
}

function seatSystemLatestTimeInAtToday(mysqli $conn, int $userId): string
{
    try {
        $stmt = $conn->prepare(
            "SELECT DATE_FORMAT(TIMESTAMP(date, time), '%Y-%m-%d %H:%i:%s') AS time_in_at
             FROM attendance
             WHERE user_id = ? AND action = 'TIME_IN' AND date = CURDATE()
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return trim((string) ($row['time_in_at'] ?? ''));
    } catch (Throwable $e) {
        error_log('[SeatSystem] latest TIME_IN lookup failed: ' . $e->getMessage());
        return '';
    }
}

function seatSystemColumnExists(mysqli $conn, string $table, string $column): bool
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

function seatSystemCleanupExpiredPendingInTable(mysqli $conn, string $table): int
{
    if (
        !seatSystemColumnExists($conn, $table, 'status')
        || !seatSystemColumnExists($conn, $table, 'reserved_by')
        || !seatSystemColumnExists($conn, $table, 'expires_at')
    ) {
        return 0;
    }

    $setParts = ["status='available'", 'reserved_by=NULL'];
    if (seatSystemColumnExists($conn, $table, 'reserved_at')) {
        $setParts[] = 'reserved_at=NULL';
    }
    $setParts[] = 'expires_at=NULL';
    if (seatSystemColumnExists($conn, $table, 'reservation_status')) {
        $setParts[] = 'reservation_status=NULL';
    }

    $where = "status='reserved' AND reserved_by IS NOT NULL AND expires_at IS NOT NULL AND expires_at < NOW()";
    if (seatSystemColumnExists($conn, $table, 'reservation_status')) {
        $where .= " AND reservation_status='pending'";
    }

    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setParts) . ' WHERE ' . $where;
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $affected = max(0, $stmt->affected_rows);
    $stmt->close();

    return $affected;
}

function seatSystemCleanupExpiredPendingAllocations(mysqli $conn): array
{
    return [
        'seats' => seatSystemCleanupExpiredPendingInTable($conn, 'seats'),
        'computers' => seatSystemCleanupExpiredPendingInTable($conn, 'computers'),
    ];
}

function seatSystemConfirmPendingForUserInTable(mysqli $conn, string $table, int $userId): int
{
    if (
        !seatSystemColumnExists($conn, $table, 'status')
        || !seatSystemColumnExists($conn, $table, 'reserved_by')
        || !seatSystemColumnExists($conn, $table, 'expires_at')
    ) {
        return 0;
    }

    $setParts = ['expires_at=NULL'];
    if (seatSystemColumnExists($conn, $table, 'reservation_status')) {
        $setParts[] = "reservation_status='confirmed'";
    }

    $where = "reserved_by = ? AND status='reserved' AND expires_at IS NOT NULL AND expires_at >= NOW()";
    if (seatSystemColumnExists($conn, $table, 'reservation_status')) {
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

function seatSystemPendingSeatLabelsForUser(mysqli $conn, int $userId): array
{
    if (
        !seatSystemColumnExists($conn, 'seats', 'status')
        || !seatSystemColumnExists($conn, 'seats', 'reserved_by')
        || !seatSystemColumnExists($conn, 'seats', 'expires_at')
        || !seatSystemColumnExists($conn, 'seats', 'seat_number')
    ) {
        return [];
    }

    $where = "reserved_by = ? AND status='reserved' AND expires_at IS NOT NULL AND expires_at >= NOW()";
    if (seatSystemColumnExists($conn, 'seats', 'reservation_status')) {
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

function seatSystemCurrentSeatLabelsForUser(mysqli $conn, int $userId): array
{
    if (
        !seatSystemColumnExists($conn, 'seats', 'status')
        || !seatSystemColumnExists($conn, 'seats', 'reserved_by')
        || !seatSystemColumnExists($conn, 'seats', 'seat_number')
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

function seatSystemSeatLabelsConfirmedByLatestTimeIn(mysqli $conn, int $userId, string $timeInAt): array
{
    if (
        !seatSystemColumnExists($conn, 'seats', 'status')
        || !seatSystemColumnExists($conn, 'seats', 'reserved_by')
        || !seatSystemColumnExists($conn, 'seats', 'reserved_at')
        || !seatSystemColumnExists($conn, 'seats', 'expires_at')
        || !seatSystemColumnExists($conn, 'seats', 'seat_number')
    ) {
        return [];
    }

    $where = "reserved_by = ? AND status='reserved' AND reserved_at IS NOT NULL AND reserved_at <= ? AND expires_at IS NULL";
    if (seatSystemColumnExists($conn, 'seats', 'reservation_status')) {
        $where .= " AND reservation_status='confirmed'";
    }

    $stmt = $conn->prepare('SELECT seat_number FROM seats WHERE ' . $where . ' ORDER BY id');
    $stmt->bind_param('is', $userId, $timeInAt);
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

function seatSystemLookupUserContact(mysqli $conn, int $userId): ?array
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

function seatSystemNormalizeSeatLabelsForEmailKey(array $seatLabels): array
{
    $labels = array_values(array_unique(array_filter(array_map(
        static fn($v): string => trim((string) $v),
        $seatLabels
    ), static fn(string $v): bool => $v !== '')));

    sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
    return $labels;
}

function seatSystemSeatConfirmedEmailKey(int $userId, array $seatLabels, string $timeInAt): string
{
    $labels = seatSystemNormalizeSeatLabelsForEmailKey($seatLabels);
    return hash('sha256', $userId . '|' . trim($timeInAt) . '|' . implode('|', $labels));
}

function seatSystemSeatConfirmedEmailFlagPath(string $emailKey): string
{
    $tmpDir = rtrim((string) sys_get_temp_dir(), '\\/' . DIRECTORY_SEPARATOR);
    if ($tmpDir === '') {
        $tmpDir = __DIR__;
    }

    return $tmpDir . DIRECTORY_SEPARATOR . 'scc_seat_confirm_' . $emailKey . '.flag';
}

function seatSystemSeatConfirmedEmailWasSent(string $emailKey): bool
{
    return is_file(seatSystemSeatConfirmedEmailFlagPath($emailKey));
}

function seatSystemSeatConfirmedEmailMarkSent(string $emailKey): void
{
    @file_put_contents(seatSystemSeatConfirmedEmailFlagPath($emailKey), date('c'));
}

function seatSystemSendSeatConfirmedEmail(
    mysqli $conn,
    int $userId,
    array $pendingSeatLabels,
    string $timeInAt = ''
): void
{
    if (!function_exists('sendSeatConfirmedEmail')) {
        return;
    }

    $contact = seatSystemLookupUserContact($conn, $userId);
    if ($contact === null) {
        return;
    }

    $seatLabels = $pendingSeatLabels;
    if (empty($seatLabels)) {
        $seatLabels = seatSystemCurrentSeatLabelsForUser($conn, $userId);
    }
    if (empty($seatLabels)) {
        return;
    }

    $timeInAt = trim($timeInAt);
    if ($timeInAt === '') {
        $timeInAt = date('Y-m-d H:i:s');
    }

    $emailKey = seatSystemSeatConfirmedEmailKey($userId, $seatLabels, $timeInAt);
    if (seatSystemSeatConfirmedEmailWasSent($emailKey)) {
        return;
    }

    $sent = sendSeatConfirmedEmail(
        $contact['email'],
        $contact['name'],
        $seatLabels,
        'Study Area - Library'
    );

    if (!$sent) {
        error_log('[SeatSystem] Seat confirmed email returned false for user ID ' . $userId);
        return;
    }

    seatSystemSeatConfirmedEmailMarkSent($emailKey);
}

function seatSystemReleaseUserAllocationsInTable(mysqli $conn, string $table, int $userId): int
{
    if (!seatSystemColumnExists($conn, $table, 'reserved_by')) {
        return 0;
    }

    $setParts = [];
    if (seatSystemColumnExists($conn, $table, 'status')) {
        $setParts[] = "status='available'";
    }
    $setParts[] = 'reserved_by=NULL';
    if (seatSystemColumnExists($conn, $table, 'reserved_at')) {
        $setParts[] = 'reserved_at=NULL';
    }
    if (seatSystemColumnExists($conn, $table, 'expires_at')) {
        $setParts[] = 'expires_at=NULL';
    }
    if (seatSystemColumnExists($conn, $table, 'reservation_status')) {
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

function seatSystemSyncAllocationsByAttendance(mysqli $conn, int $userId): array
{
    $latestAction = seatSystemLatestAttendanceActionToday($conn, $userId);
    if ($latestAction === 'TIME_IN') {
        $latestTimeInAt = seatSystemLatestTimeInAtToday($conn, $userId);
        $pendingSeatLabels = [];
        try {
            $pendingSeatLabels = seatSystemPendingSeatLabelsForUser($conn, $userId);
        } catch (Throwable $e) {
            error_log('[SeatSystem] Pending seat lookup failed: ' . $e->getMessage());
        }

        $confirmedSeats = seatSystemConfirmPendingForUserInTable($conn, 'seats', $userId);
        $confirmedComputers = seatSystemConfirmPendingForUserInTable($conn, 'computers', $userId);

        $confirmEmailSeatLabels = [];
        if ($confirmedSeats > 0) {
            $confirmEmailSeatLabels = $pendingSeatLabels;
        } elseif ($latestTimeInAt !== '') {
            try {
                $confirmEmailSeatLabels = seatSystemSeatLabelsConfirmedByLatestTimeIn($conn, $userId, $latestTimeInAt);
            } catch (Throwable $e) {
                error_log('[SeatSystem] Confirmed-seat fallback lookup failed: ' . $e->getMessage());
            }
        }

        if (!empty($confirmEmailSeatLabels)) {
            try {
                seatSystemSendSeatConfirmedEmail($conn, $userId, $confirmEmailSeatLabels, $latestTimeInAt);
            } catch (Throwable $e) {
                error_log('[SeatSystem] Seat confirmed email failed: ' . $e->getMessage());
            }
        }

        return [
            'timed_in' => true,
            'confirmed_seats' => $confirmedSeats,
            'confirmed_computers' => $confirmedComputers,
            'released_seats' => 0,
            'released_computers' => 0,
        ];
    }

    if ($latestAction === 'TIME_OUT') {
        return [
            'timed_in' => false,
            'confirmed_seats' => 0,
            'confirmed_computers' => 0,
            'released_seats' => seatSystemReleaseUserAllocationsInTable($conn, 'seats', $userId),
            'released_computers' => seatSystemReleaseUserAllocationsInTable($conn, 'computers', $userId),
        ];
    }

    return [
        'timed_in' => false,
        'confirmed_seats' => 0,
        'confirmed_computers' => 0,
        'released_seats' => 0,
        'released_computers' => 0,
    ];
}

seatSystemCleanupExpiredPendingAllocations($conn);
$attendanceSync = seatSystemSyncAllocationsByAttendance($conn, $user_id);
$userTimedInNow = (bool) $attendanceSync['timed_in'];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    seatSystemCleanupExpiredPendingAllocations($conn);
    $pollAttendanceSync = seatSystemSyncAllocationsByAttendance($conn, $user_id);

    $seatRows = mysqli_fetch_all(
        mysqli_query($conn, 'SELECT id, seat_number, status, reserved_by, reserved_at, expires_at FROM seats'),
        MYSQLI_ASSOC
    );
    $compRows = mysqli_fetch_all(
        mysqli_query($conn, 'SELECT id, computer_number, status, reserved_by, reserved_at, expires_at FROM computers'),
        MYSQLI_ASSOC
    );

    echo json_encode([
        'seats'     => $seatRows,
        'computers' => $compRows,
        'user_id'   => $user_id,
        'timed_in'  => (bool) $pollAttendanceSync['timed_in'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($isAjaxRequest) {
    set_exception_handler(function (Throwable $e) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        error_log('[SeatSystem] Unhandled exception: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'An unexpected server error occurred. Please try again.',
        ]);
        exit;
    });

    set_error_handler(function (int $errno, string $errstr) {
        throw new ErrorException($errstr, 0, $errno);
    });
}

$csrfToken = ensureCsrfToken();

$userInfoStmt = $conn->prepare(
    'SELECT username, email FROM users WHERE id = ? LIMIT 1'
);
$userInfoStmt->bind_param('i', $user_id);
$userInfoStmt->execute();
$userInfo = $userInfoStmt->get_result()->fetch_assoc();
$userInfoStmt->close();

$studentEmail = $userInfo['email']    ?? '';
$studentName  = $userInfo['username'] ?? ($_SESSION['username'] ?? 'Student');

function jsonResponse(array $data): never
{
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    session_write_close();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('sysLog')) {
    function sysLog(string $msg): void
    {
        error_log('[SeatSystem] ' . $msg);
    }
}

function checkRateLimit(int $userId): void
{
    if (function_exists('apcu_fetch')) {
        $key  = 'rate_' . $userId;
        $hits = (int) apcu_fetch($key);
        if ($hits >= 20) {
            jsonResponse(['success' => false, 'message' => 'Too many requests. Please wait a moment and try again.']);
        }
        $success = false;
        apcu_inc($key, 1, $success, 60);
        if (!$success) {
            apcu_store($key, 1, 60);
        }
        return;
    }

    if (!isset($_SESSION['rate_window_start'])) {
        $_SESSION['rate_window_start'] = time();
        $_SESSION['rate_hits']         = 0;
    }
    if ((time() - $_SESSION['rate_window_start']) > 60) {
        $_SESSION['rate_window_start'] = time();
        $_SESSION['rate_hits']         = 0;
    }
    $_SESSION['rate_hits']++;
    if ($_SESSION['rate_hits'] > 20) {
        jsonResponse(['success' => false, 'message' => 'Too many requests. Please wait a moment and try again.']);
    }
}

function logAllocation(
    mysqli $conn,
    int    $userId,
    string $type,
    int    $itemId,
    string $label,
    string $action,
    string $tableName
): void {
    try {
        $stmt = $conn->prepare(
            'INSERT INTO allocation_log
                 (user_id, item_type, item_id, item_label, action, table_name)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isisss', $userId, $type, $itemId, $label, $action, $tableName);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        sysLog('logAllocation failed: ' . $e->getMessage());
    }
}

function seatSystemHasReservedToday(mysqli $conn, int $userId): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*)
         FROM allocation_log
         WHERE user_id = ?
           AND action = 'reserved'
           AND DATE(created_at) = CURDATE()"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return ((int) $count) > 0;
}

if ($isAjaxRequest) {

    seatSystemCleanupExpiredPendingAllocations($conn);

    checkRateLimit($user_id);

    $submittedCsrf = $_POST['csrf'] ?? '';
    if (!verifyCsrf($submittedCsrf)) {
        jsonResponse([
            'success' => false,
            'message' => 'Security token mismatch. Please refresh the page and try again.',
        ]);
    }

    try {

        if (isset($_POST['seat_id'])) {

            $id = (int) $_POST['seat_id'];
            if ($id <= 0) {
                throw new InvalidArgumentException('Invalid seat ID.');
            }

            $conn->begin_transaction();

            $peekStmt = $conn->prepare(
                'SELECT status, reserved_by FROM seats WHERE id = ? LIMIT 1'
            );
            $peekStmt->bind_param('i', $id);
            $peekStmt->execute();
            $peekSeat  = $peekStmt->get_result()->fetch_assoc();
            $peekStmt->close();

            $isRelease = ($peekSeat && (int)$peekSeat['reserved_by'] === $user_id);

            if (!$isRelease) {
                // Temporarily disabled daily reservation limit for testing.
                // if (seatSystemHasReservedToday($conn, $user_id)) {
                //     $conn->rollback();
                //     jsonResponse([
                //         'success' => false,
                //         'message' => 'Daily limit reached: you can reserve only once per day.',
                //     ]);
                // }

                $limitStmt = $conn->prepare(
                    'SELECT
                        (SELECT COUNT(*) FROM seats     WHERE reserved_by = ?) +
                        (SELECT COUNT(*) FROM computers WHERE reserved_by = ?) AS total'
                );
                $limitStmt->bind_param('ii', $user_id, $user_id);
                $limitStmt->execute();
                $currentTotal = (int) $limitStmt->get_result()->fetch_row()[0];
                $limitStmt->close();

                if ($currentTotal >= 1) {
                    $conn->rollback();
                    jsonResponse([
                        'success' => false,
                        'message' => 'You have reached the maximum of 1 allocation. Please release one before allocating another.',
                    ]);
                }
            }

            $hasSeatReservationStatus = seatSystemColumnExists($conn, 'seats', 'reservation_status');
            $reserveAsConfirmed = isUserTimedInToday($conn, $user_id);
            if ($hasSeatReservationStatus) {
                if ($reserveAsConfirmed) {
                    $stmt = $conn->prepare(
                        'UPDATE seats
                         SET    status = "reserved",
                                reserved_by = ?,
                                reserved_at = NOW(),
                                expires_at = NULL,
                                reservation_status = "confirmed"
                         WHERE  id = ? AND status = "available"'
                    );
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE seats
                         SET    status = "reserved",
                                reserved_by = ?,
                                reserved_at = NOW(),
                                expires_at = DATE_ADD(NOW(), INTERVAL ' . RESERVATION_CONFIRM_WINDOW_MINUTES . ' MINUTE),
                                reservation_status = "pending"
                         WHERE  id = ? AND status = "available"'
                    );
                }
            } else {
                if ($reserveAsConfirmed) {
                    $stmt = $conn->prepare(
                        'UPDATE seats
                         SET    status = "reserved",
                                reserved_by = ?,
                                reserved_at = NOW(),
                                expires_at = NULL
                         WHERE  id = ? AND status = "available"'
                    );
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE seats
                         SET    status = "reserved",
                                reserved_by = ?,
                                reserved_at = NOW(),
                                expires_at = DATE_ADD(NOW(), INTERVAL ' . RESERVATION_CONFIRM_WINDOW_MINUTES . ' MINUTE)
                         WHERE  id = ? AND status = "available"'
                    );
                }
            }
            $stmt->bind_param('ii', $user_id, $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected > 0) {
                $action = 'reserved';
            } else {
                if ($hasSeatReservationStatus) {
                    $stmt = $conn->prepare(
                        'UPDATE seats
                         SET    status = "available",
                                reserved_by = NULL,
                                reserved_at = NULL,
                                expires_at = NULL,
                                reservation_status = NULL
                         WHERE  id = ? AND reserved_by = ?'
                    );
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE seats
                         SET    status = "available",
                                reserved_by = NULL,
                                reserved_at = NULL,
                                expires_at = NULL
                         WHERE  id = ? AND reserved_by = ?'
                    );
                }
                $stmt->bind_param('ii', $id, $user_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();

                if ($affected > 0) {
                    $action = 'released';
                } else {
                    throw new RuntimeException(
                        'This seat is already taken by someone else, or you do not own it.'
                    );
                }
            }

            $stmt = $conn->prepare('SELECT seat_number, expires_at FROM seats WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $seat = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$seat) {
                throw new RuntimeException('Seat record not found.');
            }

            logAllocation($conn, $user_id, 'seat', $id, $seat['seat_number'], $action, 'Study Area');

            $conn->commit();

            if ($action === 'reserved' && $studentEmail !== '') {
                try {
                    $sent = sendSeatEmail(
                        $studentEmail,
                        $studentName,
                        $seat['seat_number'],
                        'Study Area – Library'
                    );
                    if (!$sent) {
                        sysLog("Seat email returned false for user ID {$user_id}");
                    }
                } catch (Throwable $mailEx) {
                    sysLog("Seat email exception for user ID {$user_id}: " . $mailEx->getMessage());
                }
            }

            if ($action === 'reserved') {
                $isPending = !empty($seat['expires_at']);
                jsonResponse([
                    'success' => true,
                    'action' => $action,
                    'label' => $seat['seat_number'],
                    'emailSent' => ($studentEmail !== ''),
                    'expires_at' => $seat['expires_at'] ?? null,
                    'message' => $isPending
                        ? ('Seat ' . $seat['seat_number']
                            . ' reserved. Tap your RFID card to TIME_IN within '
                            . RESERVATION_CONFIRM_WINDOW_MINUTES
                            . ' minutes to confirm and keep this allocation.')
                        : ('Seat ' . $seat['seat_number']
                            . ' reserved and confirmed because your RFID status is already TIME_IN.'),
                ]);
            }

            jsonResponse([
                'success' => true,
                'action' => $action,
                'label' => $seat['seat_number'],
                'emailSent' => false,
                'message' => 'Seat ' . $seat['seat_number'] . ' released successfully.',
            ]);
        }

        if (isset($_POST['computer_id'])) {

            $id = (int) $_POST['computer_id'];
            if ($id <= 0) {
                throw new InvalidArgumentException('Invalid computer ID.');
            }

            $conn->begin_transaction();

            $peekStmt = $conn->prepare(
                'SELECT status, reserved_by FROM computers WHERE id = ? LIMIT 1'
            );
            $peekStmt->bind_param('i', $id);
            $peekStmt->execute();
            $peekComp  = $peekStmt->get_result()->fetch_assoc();
            $peekStmt->close();

            $isRelease = ($peekComp && (int)$peekComp['reserved_by'] === $user_id);

            if (!$isRelease) {
                // Temporarily disabled daily reservation limit for testing.
                // if (seatSystemHasReservedToday($conn, $user_id)) {
                //     $conn->rollback();
                //     jsonResponse([
                //         'success' => false,
                //         'message' => 'Daily limit reached: you can reserve only once per day.',
                //     ]);
                // }

                $limitStmt = $conn->prepare(
                    'SELECT
                        (SELECT COUNT(*) FROM seats     WHERE reserved_by = ?) +
                        (SELECT COUNT(*) FROM computers WHERE reserved_by = ?) AS total'
                );
                $limitStmt->bind_param('ii', $user_id, $user_id);
                $limitStmt->execute();
                $currentTotal = (int) $limitStmt->get_result()->fetch_row()[0];
                $limitStmt->close();

                if ($currentTotal >= 1) {
                    $conn->rollback();
                    jsonResponse([
                        'success' => false,
                        'message' => 'You have reached the maximum of 1 allocation. Please release one before allocating another.',
                    ]);
                }
            }

            $hasComputerReservationStatus = seatSystemColumnExists($conn, 'computers', 'reservation_status');
            $reserveAsConfirmed = isUserTimedInToday($conn, $user_id);
            if ($hasComputerReservationStatus) {
                if ($reserveAsConfirmed) {
                    $stmt = $conn->prepare(
                        'UPDATE computers
                         SET    status = "reserved",
                                reserved_by = ?,
                                reserved_at = NOW(),
                                expires_at = NULL,
                                reservation_status = "confirmed"
                         WHERE  id = ? AND status = "available"'
                    );
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE computers
                         SET    status = "reserved",
                                reserved_by = ?,
                                reserved_at = NOW(),
                                expires_at = DATE_ADD(NOW(), INTERVAL ' . RESERVATION_CONFIRM_WINDOW_MINUTES . ' MINUTE),
                                reservation_status = "pending"
                         WHERE  id = ? AND status = "available"'
                    );
                }
            } else {
                if ($reserveAsConfirmed) {
                    $stmt = $conn->prepare(
                        'UPDATE computers
                         SET    status = "reserved",
                                reserved_by = ?,
                                reserved_at = NOW(),
                                expires_at = NULL
                         WHERE  id = ? AND status = "available"'
                    );
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE computers
                         SET    status = "reserved",
                                reserved_by = ?,
                                reserved_at = NOW(),
                                expires_at = DATE_ADD(NOW(), INTERVAL ' . RESERVATION_CONFIRM_WINDOW_MINUTES . ' MINUTE)
                         WHERE  id = ? AND status = "available"'
                    );
                }
            }
            $stmt->bind_param('ii', $user_id, $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected > 0) {
                $action = 'reserved';
            } else {
                if ($hasComputerReservationStatus) {
                    $stmt = $conn->prepare(
                        'UPDATE computers
                         SET    status = "available",
                                reserved_by = NULL,
                                reserved_at = NULL,
                                expires_at = NULL,
                                reservation_status = NULL
                         WHERE  id = ? AND reserved_by = ?'
                    );
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE computers
                         SET    status = "available",
                                reserved_by = NULL,
                                reserved_at = NULL,
                                expires_at = NULL
                         WHERE  id = ? AND reserved_by = ?'
                    );
                }
                $stmt->bind_param('ii', $id, $user_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();

                if ($affected > 0) {
                    $action = 'released';
                } else {
                    throw new RuntimeException(
                        'This computer is already taken by someone else, or you do not own it.'
                    );
                }
            }

            $stmt = $conn->prepare('SELECT computer_number, expires_at FROM computers WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $comp = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$comp) {
                throw new RuntimeException('Computer record not found.');
            }

            logAllocation($conn, $user_id, 'computer', $id, $comp['computer_number'], $action, 'Computer Area');

            $conn->commit();

            if ($action === 'reserved' && $studentEmail !== '') {
                try {
                    $sent = sendComputerEmail(
                        $studentEmail,
                        $studentName,
                        $comp['computer_number'],
                        'Computer Area – Computer Table'
                    );
                    if (!$sent) {
                        sysLog("Computer email returned false for user ID {$user_id}");
                    }
                } catch (Throwable $mailEx) {
                    sysLog("Computer email exception for user ID {$user_id}: " . $mailEx->getMessage());
                }
            }

            if ($action === 'reserved') {
                $isPending = !empty($comp['expires_at']);
                jsonResponse([
                    'success' => true,
                    'action' => $action,
                    'label' => $comp['computer_number'],
                    'emailSent' => ($studentEmail !== ''),
                    'expires_at' => $comp['expires_at'] ?? null,
                    'message' => $isPending
                        ? ('Computer ' . $comp['computer_number']
                            . ' reserved. Tap your RFID card to TIME_IN within '
                            . RESERVATION_CONFIRM_WINDOW_MINUTES
                            . ' minutes to confirm and keep this allocation.')
                        : ('Computer ' . $comp['computer_number']
                            . ' reserved and confirmed because your RFID status is already TIME_IN.'),
                ]);
            }

            jsonResponse([
                'success' => true,
                'action' => $action,
                'label' => $comp['computer_number'],
                'emailSent' => false,
                'message' => 'Computer ' . $comp['computer_number'] . ' released successfully.',
            ]);
        }

        jsonResponse(['success' => false, 'message' => 'No reservation target specified.']);

    } catch (Throwable $e) {
        if ($conn->in_transaction) {
            $conn->rollback();
        }
        sysLog('AJAX error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => $e->getMessage()]);
    }
}

$seatsStmt = $conn->prepare(
    'SELECT id, seat_number, status, reserved_by, reserved_at, expires_at FROM seats ORDER BY id'
);
$seatsStmt->execute();
$seats = $seatsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$seatsStmt->close();

$compsStmt = $conn->prepare(
    'SELECT id, computer_number, status, reserved_by, reserved_at, expires_at FROM computers ORDER BY id'
);
$compsStmt->execute();
$computers = $compsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$compsStmt->close();

$seatMap     = array_column($seats,     null, 'id');
$computerMap = array_column($computers, null, 'id');

$mySeats     = array_filter($seats,     fn($s) => (int)$s['reserved_by'] === $user_id);
$myComputers = array_filter($computers, fn($c) => (int)$c['reserved_by'] === $user_id);

function getSeat(int $id): ?array
{
    global $seatMap;
    return $seatMap[$id] ?? null;
}

function getComputer(int $id): ?array
{
    global $computerMap;
    return $computerMap[$id] ?? null;
}

function seatButton(?array $seat, bool $forceDisabled = false): string
{
    if (!$seat) return '';
    global $user_id, $csrfToken;

    $isMine = ((int)$seat['reserved_by'] === $user_id);
    $isPending = ($seat['status'] === 'reserved' && !empty($seat['expires_at']));
    $cls    = 'seat-button';

    if ($isPending) {
        $cls .= ' pending-confirmation';
    } elseif ($isMine) {
        $cls .= ' reserved-by-you';
    } elseif ($seat['status'] !== 'available') {
        $cls .= ' taken';
    }
    if ($forceDisabled) {
        $cls .= ' disabled';
    }

    $isDisabled = $forceDisabled || ($seat['status'] !== 'available' && !$isMine);
    $disabled   = $isDisabled ? 'disabled' : '';
    $action     = $isMine ? 'release' : 'reserve';
    $seatId     = (int)$seat['id'];
    $label      = htmlspecialchars($seat['seat_number'], ENT_QUOTES, 'UTF-8');
    $forceFlag  = $forceDisabled ? '1' : '0';

    return <<<HTML
    <form class="reservation-form" method="POST" style="display:inline;">
        <input type="hidden" name="csrf"    value="{$csrfToken}">
        <input type="hidden" name="seat_id" value="{$seatId}">
        <button type="submit" class="{$cls}"
                data-seat-id="{$seatId}" data-action="{$action}" data-force-disabled="{$forceFlag}" {$disabled}>
            {$label}
        </button>
    </form>
    HTML;
}

function computerButton(?array $comp): string
{
    if (!$comp) return '';
    global $user_id, $csrfToken;

    $isMine = ((int)$comp['reserved_by'] === $user_id);
    $isPending = ($comp['status'] === 'reserved' && !empty($comp['expires_at']));
    $cls    = 'computer-button';

    if ($isPending) {
        $cls .= ' pending-confirmation';
    } elseif ($isMine) {
        $cls .= ' reserved-by-you';
    } elseif ($comp['status'] !== 'available') {
        $cls .= ' taken';
    }
    $isDisabled = ($comp['status'] !== 'available' && !$isMine);
    $disabled   = $isDisabled ? 'disabled' : '';
    $action     = $isMine ? 'release' : 'reserve';
    $compId     = (int)$comp['id'];
    $label      = htmlspecialchars($comp['computer_number'], ENT_QUOTES, 'UTF-8');

    return <<<HTML
    <form class="reservation-form" method="POST" style="display:inline;">
        <input type="hidden" name="csrf"        value="{$csrfToken}">
        <input type="hidden" name="computer_id" value="{$compId}">
        <button type="submit" class="{$cls}"
                data-computer-id="{$compId}" data-action="{$action}" data-force-disabled="0" {$disabled}>
            🖥️ {$label}
        </button>
    </form>
    HTML;
}

$singleTables = [
    'FACULTY TABLE'    => ['seats' => [11],       'layout' => 'single'],
    'Table 2'          => ['seats' => [7,8,9,19], 'layout' => 'double-side'],
    'Table 1'          => ['seats' => [3,4,5,6],  'layout' => 'double-side'],
    'Library Entrance' => ['seats' => [2],         'layout' => 'single', 'disabled' => true],
    'Attendance Table' => ['seats' => [1],         'layout' => 'single', 'disabled' => true],
];

$longTables = [
    range(12, 19),
    range(20, 27),
    range(28, 35),
    range(36, 43),
    range(44, 51),
];

$topTables = [
    'FACULTY TABLE A' => [52, 53, 54],
    'FACULTY TABLE B' => [55, 56, 57],
    'FACULTY TABLE C' => [58, 59, 60],
];

$mixedTable1   = ['seats' => [61, 62],            'computers' => [6, 7, 8]];
$mixedTable2   = ['seats' => [63, 64, 65],         'computers' => [8, 9, 10]];
$computerTable = ['seats' => [66, 67, 68, 69, 70], 'computers' => [1, 2, 3, 4, 5]];

$selfUrl = htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seat &amp; Computer Allocation – SCC Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    
    --gold:          #c8a96e;
    --gold-light:    #e2c99a;
    --gold-dim:      rgba(200, 169, 110, 0.18);
    --gold-glow:     rgba(200, 169, 110, 0.28);
    --dark:          #0a0e14;
    --dark-2:        #0d1117;
    --dark-3:        #111827;

    
    --glass-bg:      rgba(255, 255, 255, 0.055);
    --glass-bg-md:   rgba(255, 255, 255, 0.08);
    --glass-border:  rgba(200, 169, 110, 0.18);
    --glass-blur:    blur(18px) saturate(150%);


    --seat-avail:    #22c55e;
    --seat-avail-dk: #16a34a;
    --seat-mine:     #c8a96e;       
    --seat-taken:    #ef4444;

    --text-main:     #f0ece4;
    --text-sub:      rgba(240, 236, 228, 0.65);
    --text-muted:    rgba(240, 236, 228, 0.38);


    --radius:        12px;
    --radius-lg:     18px;
    --transition:    0.25s cubic-bezier(0.4, 0, 0.2, 1);
    --shadow:        0 8px 32px rgba(0,0,0,0.45);
    --shadow-sm:     0 2px 10px rgba(0,0,0,0.30);
}

html, body { height: 100%; }

body {
    font-family: 'DM Sans', sans-serif;
    color: var(--text-main);
    min-height: 100vh;
    position: relative;
    overflow-x: hidden;
}

/* Fixed background image */
.bg-layer {
    position: fixed;
    inset: 0;
    background-image: url('bg.jpg');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    z-index: -2;
}

.bg-overlay {
    position: fixed;
    inset: 0;
    background: linear-gradient(
        135deg,
        rgba(10, 14, 20, 0.97) 0%,
        rgba(10, 14, 20, 0.92) 35%,
        rgba(10, 14, 20, 0.82) 65%,
        rgba(10, 14, 20, 0.88) 100%
    );
    z-index: -1;
}

/* ═══════════════════════════════════════════════════════════
   NAVBAR
═══════════════════════════════════════════════════════════ */
.navbar {
    background: rgba(10, 14, 20, 0.80);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
    padding: 14px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--glass-border);
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 24px rgba(0,0,0,0.40);
}

.navbar-brand { display: flex; align-items: center; gap: 14px; }

.brand-icon {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: rgba(200, 169, 110, 0.10);
    border: 1.5px solid var(--glass-border);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 0 0 4px rgba(200,169,110,0.06);
}
.brand-icon img { width: 26px; height: auto; display: block; filter: drop-shadow(0 1px 4px rgba(200,169,110,0.25)); }

.brand-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--gold-light);
    line-height: 1.2;
}
.brand-sub {
    font-size: 0.71rem;
    color: var(--text-muted);
    letter-spacing: 0.04em;
}

.navbar-right { display: flex; align-items: center; gap: 14px; }

.user-info   { text-align: right; }
.user-name   { font-size: 0.88rem; font-weight: 600; color: var(--text-main); }
.user-email  { font-size: 0.72rem; color: var(--text-muted); }

.btn-logout {
    background: transparent;
    color: var(--text-sub);
    border: 1.5px solid var(--glass-border);
    padding: 8px 16px;
    border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    display: flex; align-items: center; gap: 6px;
    transition: all var(--transition);
    text-decoration: none;
    letter-spacing: 0.02em;
}
.btn-logout:hover {
    background: rgba(239, 68, 68, 0.12);
    border-color: rgba(239, 68, 68, 0.40);
    color: #f87171;
}

/* ═══════════════════════════════════════════════════════════
   ALLOCATION BAR
═══════════════════════════════════════════════════════════ */
.my-allocations-bar {
    background: rgba(200, 169, 110, 0.08);
    border-bottom: 1px solid rgba(200, 169, 110, 0.22);
    padding: 9px 28px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.82rem;
    color: var(--text-sub);
    font-weight: 500;
    flex-wrap: wrap;
    min-height: 40px;
    backdrop-filter: blur(8px);
}
.my-allocations-bar.hidden { display: none; }

.attendance-status {
    border-bottom: 1px solid rgba(200, 169, 110, 0.22);
    padding: 9px 28px;
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 0.81rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    backdrop-filter: blur(8px);
}
.attendance-status.in {
    background: rgba(34, 197, 94, 0.10);
    color: #9be8b7;
}
.attendance-status.out {
    background: rgba(239, 68, 68, 0.10);
    color: #f9b4b4;
}
.attendance-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.attendance-status.in .attendance-dot {
    background: #22c55e;
    box-shadow: 0 0 8px rgba(34, 197, 94, 0.50);
}
.attendance-status.out .attendance-dot {
    background: #ef4444;
    box-shadow: 0 0 8px rgba(239, 68, 68, 0.45);
}

.alloc-label { font-weight: 700; color: var(--gold); margin-right: 4px; letter-spacing: 0.03em; }

.alloc-chip {
    background: rgba(200, 169, 110, 0.12);
    border: 1.5px solid rgba(200, 169, 110, 0.35);
    color: var(--gold-light);
    border-radius: 20px;
    padding: 3px 12px;
    font-size: 0.78rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.alloc-counter {
    margin-left: auto;
    font-size: 0.76rem;
    color: var(--text-muted);
    white-space: nowrap;
}

/* ═══════════════════════════════════════════════════════════
   MAIN LAYOUT 
═══════════════════════════════════════════════════════════ */
.main { display: flex; min-height: calc(100vh - 65px); }

/* ── Left panel ── */
.left-panel {
    width: 230px;
    min-width: 230px;
    background: rgba(10, 14, 20, 0.55);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
    border-right: 1px solid var(--glass-border);
    padding: 22px 14px;
    display: flex;
    flex-direction: column;
    gap: 0;
}

/* ── Right panel ── */
.right-panel {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 278px;
    gap: 0 20px;
    padding: 26px 24px 90px 26px;
    align-items: start;
    overflow-y: auto;
    min-width: 0;
}

.main-content { min-width: 0; display: flex; flex-direction: column; }

.ct-sidebar {
    position: sticky;
    bottom: 56px; top: auto;
    align-self: end;
    max-height: calc(100vh - 120px);
    display: flex;
    flex-direction: column;
}

.mobile-panel-toggle {
    display: none;
    position: fixed;
    bottom: 70px; right: 18px;
    z-index: 200;
    background: linear-gradient(135deg, #c8a96e 0%, #a07840 100%);
    color: #1a1208;
    border: none;
    border-radius: 50%;
    width: 50px; height: 50px;
    font-size: 1.3rem;
    cursor: pointer;
    box-shadow: 0 4px 18px rgba(200,169,110,0.45);
    align-items: center; justify-content: center;
    transition: all var(--transition);
}
.mobile-panel-toggle:hover { filter: brightness(1.1); transform: translateY(-2px); }

.mobile-drawer-overlay {
    display: none;
    position: fixed; inset: 0;
    z-index: 300;
    background: rgba(0,0,0,0.60);
    backdrop-filter: blur(4px);
}
.mobile-drawer-overlay.open { display: block; }

.mobile-drawer {
    position: fixed;
    left: 0; top: 0; bottom: 0;
    width: 260px;
    background: rgba(13, 17, 23, 0.95);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
    border-right: 1px solid var(--glass-border);
    z-index: 301;
    padding: 22px 14px 80px;
    overflow-y: auto;
    transform: translateX(-100%);
    transition: transform 0.25s ease;
}
.mobile-drawer.open { transform: translateX(0); }

.mobile-drawer-close {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 14px;
}
.mobile-drawer-close button {
    background: none; border: none; font-size: 1.3rem;
    color: var(--text-muted); cursor: pointer;
    transition: color var(--transition);
}
.mobile-drawer-close button:hover { color: var(--gold); }

.panel-title, .section-title {
    font-size: 0.68rem;
    font-weight: 700;
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 0.12em;
    margin-bottom: 14px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--gold-dim);
}

.table-card {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: 14px 12px 16px;
    margin-bottom: 10px;
    text-align: center;
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(12px);
    transition: border-color var(--transition), box-shadow var(--transition);
}
.table-card:hover {
    border-color: rgba(200, 169, 110, 0.30);
    box-shadow: 0 4px 20px rgba(0,0,0,0.40);
}

.table-card-title {
    font-size: 0.68rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 12px;
}


.single-layout      { display: flex; justify-content: center; }
.double-side-layout { display: flex; flex-direction: column; align-items: center; gap: 7px; }
.chair-row          { display: flex; gap: 6px; justify-content: center; }

.table-visual {
    width: 86px; height: 10px;
    background: rgba(200, 169, 110, 0.15);
    border: 1px solid var(--glass-border);
    border-radius: 4px;
    flex-shrink: 0;
}

/* Long table cards */
.long-table-card {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: 18px 22px;
    margin-bottom: 14px;
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(12px);
    transition: border-color var(--transition), box-shadow var(--transition);
}
.long-table-card:hover {
    border-color: rgba(200, 169, 110, 0.30);
}

.long-table-name {
    font-family: 'Cormorant Garamond', serif;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gold-light);
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 16px;
}

.horizontal-facing-layout { display: flex; flex-direction: column; align-items: center; gap: 7px; }
.horizontal-side           { display: flex; gap: 7px; justify-content: center; flex-wrap: wrap; }

.horizontal-table-bar {
    width: 100%; max-width: 420px;
    height: 14px;
    background: rgba(200, 169, 110, 0.12);
    border: 1px solid var(--glass-border);
    border-radius: 6px;
}

/* Top faculty table cards */
.top-tables-row { display: flex; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; }

.top-table-card {
    flex: 1; min-width: 154px;
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: 16px 12px;
    text-align: center;
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(12px);
    transition: border-color var(--transition), box-shadow var(--transition);
}
.top-table-card:hover { border-color: rgba(200, 169, 110, 0.30); }

.vertical-table             { display: flex; flex-direction: column; align-items: center; gap: 7px; }
.vertical-table .top-chair  { margin-bottom: 2px; }
.vertical-table .middle-row { display: flex; align-items: center; gap: 8px; }
.vertical-table .table-bar  {
    width: 14px; height: 78px;
    background: rgba(200, 169, 110, 0.12);
    border: 1px solid var(--glass-border);
    border-radius: 4px;
}

/* Mixed table cards */
.mixed-table-card {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: 18px 22px;
    margin-bottom: 14px;
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(12px);
    transition: border-color var(--transition);
}
.mixed-table-card:hover { border-color: rgba(200, 169, 110, 0.30); }

.mixed-table-name {
    font-family: 'Cormorant Garamond', serif;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gold-light);
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 16px;
}

.mixed-layout    { display: flex; flex-direction: column; align-items: center; gap: 9px; }
.mixed-row       { display: flex; gap: 7px; justify-content: center; flex-wrap: wrap; }

.mixed-table-bar {
    width: 100%; max-width: 240px;
    height: 12px;
    background: rgba(200, 169, 110, 0.12);
    border: 1px solid var(--glass-border);
    border-radius: 6px;
}

.seat-button,
.computer-button {
    border: none;
    border-radius: var(--radius);
    padding: 10px 13px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    color: #fff;
    background: var(--seat-avail);
    min-width: 52px;
    position: relative;
    transition: opacity 0.15s, transform 0.12s, background 0.15s, box-shadow 0.15s;
    box-shadow: 0 2px 8px rgba(34, 197, 94, 0.28);
    letter-spacing: 0.01em;
}
.seat-button:hover:not(:disabled),
.computer-button:hover:not(:disabled) {
    opacity: 0.88;
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 5px 16px rgba(34, 197, 94, 0.40);
}
.seat-button:active:not(:disabled),
.computer-button:active:not(:disabled) { transform: scale(0.97); }

.seat-button.reserved-by-you,
.computer-button.reserved-by-you {
    background: var(--seat-taken);
    color: #fff;
    box-shadow: 0 2px 10px rgba(239, 68, 68, 0.42);
}
.seat-button.reserved-by-you:hover:not(:disabled),
.computer-button.reserved-by-you:hover:not(:disabled) {
    box-shadow: 0 5px 18px rgba(239, 68, 68, 0.56);
    filter: brightness(1.06);
}

.seat-button.pending-confirmation,
.computer-button.pending-confirmation {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: #1a1208;
    box-shadow: 0 2px 10px rgba(245, 158, 11, 0.45);
}
.seat-button.pending-confirmation:hover:not(:disabled),
.computer-button.pending-confirmation:hover:not(:disabled) {
    box-shadow: 0 5px 18px rgba(245, 158, 11, 0.55);
    filter: brightness(1.06);
}

.seat-button.taken,
.computer-button.taken {
    background: var(--seat-taken);
    cursor: not-allowed;
    opacity: 0.65;
    box-shadow: none;
}
.seat-button:disabled,
.computer-button:disabled { cursor: not-allowed; }

.seat-button.disabled {
    background: rgba(255,255,255,0.10) !important;
    color: var(--text-muted) !important;
    box-shadow: none !important;
    cursor: not-allowed;
    border: 1px solid var(--glass-border);
}

.seat-button.attendance-locked,
.computer-button.attendance-locked {
    background: rgba(255,255,255,0.10) !important;
    color: var(--text-muted) !important;
    box-shadow: none !important;
    border: 1px solid var(--glass-border);
}

.seat-button.loading,
.computer-button.loading { pointer-events: none; opacity: 0.70; }
.seat-button.loading::after,
.computer-button.loading::after {
    content: '';
    display: inline-block;
    width: 10px; height: 10px;
    border: 2px solid rgba(255,255,255,0.35);
    border-top-color: white;
    border-radius: 50%;
    animation: btn-spin 0.55s linear infinite;
    margin-left: 6px;
    vertical-align: middle;
}
@keyframes btn-spin { to { transform: rotate(360deg); } }

.ct-card {
    background: var(--glass-bg-md);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: 28px 14px 32px;
    text-align: center;
    box-shadow: var(--shadow);
    backdrop-filter: blur(20px);
    transform: translateY(-120px);
}

.ct-card-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1rem;
    font-weight: 600;
    color: var(--gold-light);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 18px;
}

.ct-facing-layout {
    display: flex;
    align-items: stretch;
    justify-content: center;
}
.ct-facing-side {
    display: flex;
    flex-direction: column;
    gap: 7px;
    justify-content: center;
    align-items: center;
}
.ct-col-label {
    font-size: 0.62rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.10em;
    margin-bottom: 5px;
    display: block;
}
.ct-table-bar {
    width: 18px;
    background: rgba(200, 169, 110, 0.15);
    border: 1px solid var(--glass-border);
    border-radius: 4px;
    margin: 0 14px;
}

.poll-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--seat-avail);
    display: inline-block;
    margin-right: 5px;
    box-shadow: 0 0 6px rgba(34, 197, 94, 0.60);
    animation: pulse-dot 2.2s ease-in-out infinite;
}
@keyframes pulse-dot {
    0%, 100% { opacity: 1; box-shadow: 0 0 6px rgba(34,197,94,0.60); }
    50%       { opacity: 0.30; box-shadow: none; }
}
.legend {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    background: rgba(10, 14, 20, 0.88);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
    border-top: 1px solid var(--glass-border);
    padding: 12px 28px;
    display: flex;
    justify-content: center;
    gap: 32px;
    z-index: 50;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.35);
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    color: var(--text-sub);
    font-weight: 600;
    letter-spacing: 0.02em;
}
.legend-dot {
    width: 14px; height: 14px;
    border-radius: 5px;
    flex-shrink: 0;
}
.legend-dot.available { background: var(--seat-avail); box-shadow: 0 0 8px rgba(34,197,94,0.40); }
.legend-dot.pending  {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    box-shadow: 0 0 8px rgba(245,158,11,0.40);
}
.legend-dot.taken     { background: var(--seat-taken); box-shadow: 0 0 8px rgba(239,68,68,0.35); }

.loading-overlay {
    display: none;
    position: fixed; inset: 0;
    z-index: 9998;
    background: rgba(10, 14, 20, 0.60);
    backdrop-filter: blur(6px);
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 16px;
}
.loading-overlay.visible { display: flex; }

.spinner {
    width: 46px; height: 46px;
    border: 3px solid rgba(200, 169, 110, 0.20);
    border-top-color: var(--gold);
    border-radius: 50%;
    animation: spin 0.70s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.loading-text {
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--text-sub);
    letter-spacing: 0.04em;
}

.confirm-backdrop {
    display: none;
    position: fixed; inset: 0;
    z-index: 10000;
    background: rgba(0,0,0,0.60);
    backdrop-filter: blur(6px);
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.confirm-backdrop.visible { display: flex; }

.confirm-box {
    background: rgba(13, 17, 23, 0.96);
    border: 1px solid var(--glass-border);
    border-radius: 20px;
    padding: 36px 36px 30px;
    max-width: 380px;
    width: 100%;
    text-align: center;
    box-shadow: 0 24px 60px rgba(0,0,0,0.60);
    animation: modal-pop 0.22s cubic-bezier(0.34, 1.56, 0.64, 1) both;
    backdrop-filter: blur(24px);
}
.confirm-icon  { font-size: 2.2rem; margin-bottom: 16px; }
.confirm-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 10px;
}
.confirm-body  {
    font-size: 0.88rem;
    color: var(--text-sub);
    line-height: 1.65;
    margin-bottom: 26px;
}
.confirm-btns  { display: flex; gap: 10px; justify-content: center; }

.confirm-btn-cancel {
    flex: 1;
    padding: 11px;
    border: 1.5px solid var(--glass-border);
    background: transparent;
    color: var(--text-sub);
    border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition);
}
.confirm-btn-cancel:hover {
    background: rgba(255,255,255,0.06);
    border-color: rgba(255,255,255,0.20);
    color: var(--text-main);
}

.confirm-btn-confirm {
    flex: 1;
    padding: 11px;
    border: none;
    background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
    color: white;
    border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    transition: filter var(--transition), transform var(--transition);
    box-shadow: 0 4px 14px rgba(239,68,68,0.30);
}
.confirm-btn-confirm:hover { filter: brightness(1.10); transform: translateY(-1px); }
.confirm-btn-confirm:active { transform: scale(0.97); }

.modal-backdrop {
    display: none;
    position: fixed; inset: 0;
    z-index: 9999;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(6px);
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.modal-backdrop.visible { display: flex; }

.modal-box {
    background: rgba(13, 17, 23, 0.96);
    border: 1px solid var(--glass-border);
    border-radius: 22px;
    padding: 40px 40px 36px;
    max-width: 420px;
    width: 100%;
    text-align: center;
    box-shadow: 0 24px 60px rgba(0,0,0,0.60), 0 1px 0 rgba(255,255,255,0.04) inset;
    animation: modal-pop 0.26s cubic-bezier(0.34, 1.56, 0.64, 1) both;
    backdrop-filter: blur(28px);
}
@keyframes modal-pop {
    from { transform: scale(0.82); opacity: 0; }
    to   { transform: scale(1);    opacity: 1; }
}

.modal-icon {
    width: 66px; height: 66px;
    border-radius: 50%;
    margin: 0 auto 20px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.9rem;
}
.modal-icon.success { background: rgba(34, 197, 94, 0.12); border: 1.5px solid rgba(34,197,94,0.30); }
.modal-icon.error   { background: rgba(239, 68, 68, 0.12); border: 1.5px solid rgba(239,68,68,0.30); }

.modal-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.35rem;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 12px;
}
.modal-body {
    font-size: 0.90rem;
    color: var(--text-sub);
    line-height: 1.65;
    margin-bottom: 16px;
}
.modal-email-note {
    font-size: 0.78rem;
    color: var(--text-muted);
    background: rgba(200, 169, 110, 0.07);
    border: 1px solid var(--gold-dim);
    border-radius: 9px;
    padding: 10px 14px;
    margin-bottom: 22px;
}

.modal-close {
    display: inline-block;
    background: linear-gradient(135deg, #c8a96e 0%, #a07840 100%);
    color: #1a1208;
    border: none;
    border-radius: var(--radius);
    padding: 12px 36px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.9rem;
    font-weight: 700;
    cursor: pointer;
    letter-spacing: 0.5px;
    transition: filter var(--transition), transform var(--transition), box-shadow var(--transition);
    box-shadow: 0 4px 18px rgba(200,169,110,0.32);
}
.modal-close:hover  { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 6px 24px rgba(200,169,110,0.42); }
.modal-close:active { transform: scale(0.97); }

@media (max-width: 960px) {
    .right-panel {
        grid-template-columns: 1fr;
        padding: 18px 14px 90px;
    }
    .ct-sidebar { position: static; align-self: auto; max-height: none; margin-top: 14px; }
    .ct-card    { transform: none; }
}
@media (max-width: 680px) {
    .left-panel          { display: none; }
    .mobile-panel-toggle { display: flex; }
    .navbar-right .user-info { display: none; }
}
</style>
</head>
<body>

<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<!-- ══════════════════ NAVBAR ══════════════════ -->
<nav class="navbar">
  <div class="navbar-brand">
    <div class="brand-icon">
      <img src="logo.png" alt="St. Clare College Logo">
    </div>
    <div>
      <div class="brand-title">St. Clare College of Caloocan</div>
      <div class="brand-sub">Seat &amp; Computer Allocation System</div>
    </div>
  </div>

  <div class="navbar-right">
    <div class="user-info">
      <div class="user-name"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></div>
      <div class="user-email"><?= htmlspecialchars($_SESSION['email'] ?? '') ?></div>
    </div>
    <a href="logout.php" class="btn-logout">Logout</a>
  </div>
</nav>

<!-- ══════════════════ ALLOCATION BAR ══════════════════ -->
<div class="my-allocations-bar <?= (count($mySeats) + count($myComputers) === 0) ? 'hidden' : '' ?>"
     id="myAllocBar">
  <span class="alloc-label">Your allocations:</span>
  <span id="allocChips">
    <?php foreach ($mySeats as $s): ?>
      <span class="alloc-chip" data-seat-id="<?= $s['id'] ?>">
        🪑 <?= htmlspecialchars($s['seat_number']) ?>
      </span>
    <?php endforeach; ?>
    <?php foreach ($myComputers as $c): ?>
      <span class="alloc-chip" data-computer-id="<?= $c['id'] ?>">
        🖥️ <?= htmlspecialchars($c['computer_number']) ?>
      </span>
    <?php endforeach; ?>
  </span>
  <span class="alloc-counter" id="allocCounter">
    <span class="poll-dot" title="Live updates active"></span>
    <?= count($mySeats) + count($myComputers) ?> / 1 used
  </span>
</div>

<div class="attendance-status <?= $userTimedInNow ? 'in' : 'out' ?>" id="attendanceStatus">
    <span class="attendance-dot"></span>
    <span id="attendanceStatusText">
        <?= $userTimedInNow
                ? 'RFID status: TIME_IN. Any pending reservation is now confirmed.'
                : 'RFID status: Not timed in yet. You can reserve now, then tap TIME_IN within 15 minutes to confirm.' ?>
    </span>
</div>

<!-- ══════════════════ MAIN ══════════════════ -->
<div class="main">

  <!-- Left panel -->
  <aside class="left-panel" id="leftPanelDesktop">
    <div class="panel-title">Faculty &amp; Entrance</div>

    <?php foreach ($singleTables as $name => $config):
        $ids           = $config['seats'];
        $layout        = $config['layout'];
        $forceDisabled = !empty($config['disabled']);
    ?>
    <div class="table-card">
      <div class="table-card-title"><?= htmlspecialchars($name) ?></div>

      <?php if ($layout === 'double-side'): ?>
        <div class="double-side-layout">
          <div class="chair-row">
            <?= seatButton(getSeat($ids[0]), $forceDisabled) ?>
            <?= seatButton(getSeat($ids[1]), $forceDisabled) ?>
          </div>
          <div class="table-visual"></div>
          <div class="chair-row">
            <?= seatButton(getSeat($ids[2]), $forceDisabled) ?>
            <?= seatButton(getSeat($ids[3] ?? 0), $forceDisabled) ?>
          </div>
        </div>
      <?php else: ?>
        <div class="single-layout">
          <?= seatButton(getSeat($ids[0]), $forceDisabled) ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </aside>

  <!-- Right panel -->
  <main class="right-panel">

    <div class="main-content">

      <!-- Faculty tables -->
      <div class="section-title">Faculty Tables</div>
      <div class="top-tables-row">
        <?php foreach ($topTables as $tName => $tSeats): ?>
        <div class="top-table-card">
          <div class="table-card-title"><?= htmlspecialchars($tName) ?></div>
          <div class="vertical-table">
            <div class="top-chair">
              <?= isset($tSeats[2]) ? seatButton(getSeat($tSeats[2])) : '' ?>
            </div>
            <div class="middle-row">
              <?= seatButton(getSeat($tSeats[0])) ?>
              <div class="table-bar"></div>
              <?= isset($tSeats[1]) ? seatButton(getSeat($tSeats[1])) : '' ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Study tables -->
      <div class="section-title">Study Tables</div>

      <?php
      $longTableLabels = ['E', 'D', 'C', 'B', 'A'];
      foreach ($longTables as $index => $seatIds):
          $label       = $longTableLabels[$index] ?? ($index + 1);
          $topSeats    = array_slice($seatIds, 0, 4);
          $bottomSeats = array_slice($seatIds, 4, 4);
      ?>
      <div class="long-table-card">
        <div class="long-table-name">Long Table <?= htmlspecialchars((string)$label) ?></div>
        <div class="horizontal-facing-layout">
          <div class="horizontal-side">
            <?php foreach ($topSeats    as $sid) echo seatButton(getSeat($sid)); ?>
          </div>
          <div class="horizontal-table-bar"></div>
          <div class="horizontal-side">
            <?php foreach ($bottomSeats as $sid) echo seatButton(getSeat($sid)); ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Mixed table 1 -->
      <div class="mixed-table-card">
        <div class="mixed-table-name">Mixed Table 1</div>
        <div class="mixed-layout">
          <div class="mixed-row">
            <?php foreach ($mixedTable1['seats']     as $sid) echo seatButton(getSeat($sid)); ?>
          </div>
          <div class="mixed-table-bar"></div>
          <div class="mixed-row">
            <?php foreach ($mixedTable1['computers'] as $cid) echo computerButton(getComputer($cid)); ?>
          </div>
        </div>
      </div>

      <!-- Mixed table 2 -->
      <div class="mixed-table-card">
        <div class="mixed-table-name">Mixed Table 2</div>
        <div class="mixed-layout">
          <div class="mixed-row">
            <?php foreach ($mixedTable2['seats']     as $sid) echo seatButton(getSeat($sid)); ?>
          </div>
          <div class="mixed-table-bar"></div>
          <div class="mixed-row">
            <?php foreach ($mixedTable2['computers'] as $cid) echo computerButton(getComputer($cid)); ?>
          </div>
        </div>
      </div>

    </div>

    <!-- Computer table sidebar -->
    <div class="ct-sidebar">
      <div class="ct-card">
        <div class="ct-card-title">Computer Table</div>
        <div class="ct-facing-layout">

          <div class="ct-facing-side">
            <span class="ct-col-label">Seats</span>
            <?php foreach ($computerTable['seats']     as $sid) echo seatButton(getSeat($sid)); ?>
          </div>

          <div class="ct-table-bar" role="presentation"></div>

          <div class="ct-facing-side">
            <span class="ct-col-label">Computers</span>
            <?php foreach ($computerTable['computers'] as $cid) echo computerButton(getComputer($cid)); ?>
          </div>

        </div>
      </div>
    </div>

  </main>
</div>

<!-- Mobile drawer toggle -->
<button class="mobile-panel-toggle" id="drawerToggle" aria-label="Open faculty tables">🪑</button>

<!-- Mobile drawer overlay + panel -->
<div class="mobile-drawer-overlay" id="drawerOverlay">
  <div class="mobile-drawer" id="mobileDrawer">
    <div class="mobile-drawer-close">
      <button id="drawerClose" aria-label="Close">✕</button>
    </div>
    <div class="panel-title">Faculty &amp; Entrance</div>

    <?php foreach ($singleTables as $name => $config):
        $ids           = $config['seats'];
        $layout        = $config['layout'];
        $forceDisabled = !empty($config['disabled']);
    ?>
    <div class="table-card">
      <div class="table-card-title"><?= htmlspecialchars($name) ?></div>
      <?php if ($layout === 'double-side'): ?>
        <div class="double-side-layout">
          <div class="chair-row">
            <?= seatButton(getSeat($ids[0]), $forceDisabled) ?>
            <?= seatButton(getSeat($ids[1]), $forceDisabled) ?>
          </div>
          <div class="table-visual"></div>
          <div class="chair-row">
            <?= seatButton(getSeat($ids[2]), $forceDisabled) ?>
            <?= seatButton(getSeat($ids[3] ?? 0), $forceDisabled) ?>
          </div>
        </div>
      <?php else: ?>
        <div class="single-layout">
          <?= seatButton(getSeat($ids[0]), $forceDisabled) ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ── Legend ── -->
<div class="legend">
  <div class="legend-item"><div class="legend-dot available"></div> Available</div>
    <div class="legend-item"><div class="legend-dot pending"></div>  Pending Confirmation</div>
  <div class="legend-item"><div class="legend-dot taken"></div>     Occupied</div>
</div>

<!-- ── Loading overlay ── -->
<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner"></div>
  <div class="loading-text">Processing allocation…</div>
</div>

<!-- ── Confirm dialog ── -->
<div class="confirm-backdrop" id="confirmBackdrop">
  <div class="confirm-box">
    <div class="confirm-icon">⚠️</div>
    <div class="confirm-title">Release your allocation?</div>
    <div class="confirm-body">This will make it available to other students. You can re-allocate it if it is still free.</div>
    <div class="confirm-btns">
      <button class="confirm-btn-cancel"  id="confirmCancel">Keep it</button>
      <button class="confirm-btn-confirm" id="confirmProceed">Release</button>
    </div>
  </div>
</div>

<!-- ── Result modal ── -->
<div class="modal-backdrop" id="modalBackdrop">
  <div class="modal-box">
    <div class="modal-icon"  id="modalIcon">✅</div>
    <div class="modal-title" id="modalTitle">Success</div>
    <div class="modal-body"  id="modalBody">Message.</div>
    <div class="modal-email-note" id="modalEmailNote" style="display:none;"></div>
    <button class="modal-close" id="modalClose">Got it</button>
  </div>
</div>

<script>
'use strict';

const ENDPOINT = '<?= $selfUrl ?>';
const MY_USER  = <?= $user_id ?>;

const loadingOverlay  = document.getElementById('loadingOverlay');
const modalBackdrop   = document.getElementById('modalBackdrop');
const modalIcon       = document.getElementById('modalIcon');
const modalTitle      = document.getElementById('modalTitle');
const modalBody       = document.getElementById('modalBody');
const modalEmailNote  = document.getElementById('modalEmailNote');
const modalClose      = document.getElementById('modalClose');
const confirmBackdrop = document.getElementById('confirmBackdrop');
const confirmCancel   = document.getElementById('confirmCancel');
const confirmProceed  = document.getElementById('confirmProceed');
const myAllocBar      = document.getElementById('myAllocBar');
const allocChips      = document.getElementById('allocChips');
const allocCounter    = document.getElementById('allocCounter');
const attendanceStatus = document.getElementById('attendanceStatus');
const attendanceStatusText = document.getElementById('attendanceStatusText');
const drawerToggle    = document.getElementById('drawerToggle');
const drawerOverlay   = document.getElementById('drawerOverlay');
const mobileDrawer    = document.getElementById('mobileDrawer');
const drawerClose     = document.getElementById('drawerClose');
let userTimedIn       = <?= $userTimedInNow ? 'true' : 'false' ?>;

function showModal(type, title, body, emailNote) {
    modalIcon.className    = 'modal-icon ' + type;
    modalIcon.textContent  = type === 'success' ? '✅' : '❌';
    modalTitle.textContent = title;
    modalBody.innerHTML    = body;
    modalEmailNote.style.display = emailNote ? 'block' : 'none';
    if (emailNote) modalEmailNote.textContent = '📧 ' + emailNote;
    modalBackdrop.classList.add('visible');
}
function closeModal() { modalBackdrop.classList.remove('visible'); }
modalClose.addEventListener('click', closeModal);
modalBackdrop.addEventListener('click', e => { if (e.target === modalBackdrop) closeModal(); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal();
        confirmBackdrop.classList.remove('visible');
    }
});

function openDrawer()  { mobileDrawer.classList.add('open'); drawerOverlay.classList.add('open'); }
function closeDrawer() { mobileDrawer.classList.remove('open'); drawerOverlay.classList.remove('open'); }
drawerToggle.addEventListener('click', openDrawer);
drawerClose.addEventListener('click', closeDrawer);
drawerOverlay.addEventListener('click', e => { if (e.target === drawerOverlay) closeDrawer(); });

function syncAttendanceStatus(isTimedIn) {
    attendanceStatus.classList.toggle('in', isTimedIn);
    attendanceStatus.classList.toggle('out', !isTimedIn);
    attendanceStatusText.textContent = isTimedIn
        ? 'RFID status: TIME_IN. Any pending reservation is now confirmed.'
    : 'RFID status: Not timed in yet. You can reserve now, then tap TIME_IN within 15 minutes to confirm.';
}

syncAttendanceStatus(userTimedIn);

function refreshAllocBanner(seatsData, compsData) {
    const mySeats = seatsData.filter(s => s.reserved_by == MY_USER);
    const myComps = compsData.filter(c => c.reserved_by == MY_USER);
    const total   = mySeats.length + myComps.length;

    const pendingLabel = (expiresAt) => {
        if (!expiresAt) return '';
        const ms = new Date(expiresAt.replace(' ', 'T')).getTime() - Date.now();
        const mins = Math.max(1, Math.ceil(ms / 60000));
        return ` ⏳${mins}m`;
    };

    allocChips.innerHTML = [
        ...mySeats.map(s => `<span class="alloc-chip" data-seat-id="${s.id}">🪑 ${escHtml(s.seat_number)}${pendingLabel(s.expires_at)}</span>`),
        ...myComps.map(c => `<span class="alloc-chip" data-computer-id="${c.id}">🖥️ ${escHtml(c.computer_number)}${pendingLabel(c.expires_at)}</span>`),
    ].join('');

    allocCounter.innerHTML =
        `<span class="poll-dot" title="Live updates active"></span>${total} / 1 used`;

    myAllocBar.classList.toggle('hidden', total === 0);
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function syncButtons(seatsData, compsData) {
    const seatMap = Object.fromEntries(seatsData.map(s => [s.id, s]));
    const compMap = Object.fromEntries(compsData.map(c => [c.id, c]));

    document.querySelectorAll('.seat-button').forEach(btn => {
        const id   = parseInt(btn.dataset.seatId);
        const seat = seatMap[id];
        if (!seat) return;
        applyButtonState(btn, seat.status, seat.reserved_by, seat.expires_at, 'seat');
    });

    document.querySelectorAll('.computer-button').forEach(btn => {
        const id   = parseInt(btn.dataset.computerId);
        const comp = compMap[id];
        if (!comp) return;
        applyButtonState(btn, comp.status, comp.reserved_by, comp.expires_at, 'computer');
    });
}

function applyButtonState(btn, status, reservedBy, expiresAt, type) {
    const isMine  = (parseInt(reservedBy) === MY_USER);
    const isPending = (status === 'reserved' && !!expiresAt);
    const isTaken = (status !== 'available' && !isMine && !isPending);
    const forceDisabled = (btn.dataset.forceDisabled === '1');

    btn.classList.toggle('reserved-by-you', isMine && !isPending);
    btn.classList.toggle('pending-confirmation', isPending);
    btn.classList.toggle('taken',           isTaken);

    btn.disabled = forceDisabled || (status !== 'available' && !isMine);
    btn.dataset.action = isMine ? 'release' : 'reserve';
}

let lastPollData = null;
let pollTimer = null;
const POLL_MS_ACTIVE = 2000;
const POLL_MS_HIDDEN = 6000;

function poll() {
    fetch(ENDPOINT + '?poll=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.ok ? r.json() : null)
        .then(data => {
            if (!data) return;
            lastPollData = data;
            if (typeof data.timed_in !== 'undefined') {
                userTimedIn = !!data.timed_in;
                syncAttendanceStatus(userTimedIn);
            }
            syncButtons(data.seats, data.computers);
            refreshAllocBanner(data.seats, data.computers);
        })
        .catch(() => {});
}

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    const interval = document.hidden ? POLL_MS_HIDDEN : POLL_MS_ACTIVE;
    pollTimer = setInterval(poll, interval);
}

document.addEventListener('visibilitychange', startPolling);
startPolling();
poll();

let pendingFormData = null;
let pendingBtn      = null;

function showConfirm() { confirmBackdrop.classList.add('visible'); }
function hideConfirm() {
    confirmBackdrop.classList.remove('visible');
    if (pendingBtn) pendingBtn.classList.remove('loading');
    pendingFormData = null;
    pendingBtn      = null;
}
confirmCancel.addEventListener('click', hideConfirm);
confirmBackdrop.addEventListener('click', e => { if (e.target === confirmBackdrop) hideConfirm(); });

confirmProceed.addEventListener('click', () => {
    confirmBackdrop.classList.remove('visible');
    if (pendingFormData && pendingBtn) {
        doFetch(pendingFormData, pendingBtn);
        pendingFormData = null;
        pendingBtn      = null;
    }
});

function doFetch(formData, btn) {
    fetch(ENDPOINT, {
        method:  'POST',
        body:    formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    .then(function(res) {
        const ct = res.headers.get('content-type') || '';
        if (!res.ok || !ct.includes('application/json')) {
            return res.text().then(text => {
                console.error('Non-JSON response (' + res.status + '):', text.substring(0, 500));
                throw new Error('Server returned ' + res.status + ' ' + res.statusText + '.');
            });
        }
        return res.json();
    })
    .then(function(data) {
        btn.classList.remove('loading');

        if (data.success) {
            if (data.action === 'reserved') {
                const isPending = !!data.expires_at;
                btn.classList.toggle('reserved-by-you', !isPending);
                btn.classList.toggle('pending-confirmation', isPending);
                btn.classList.remove('taken');
                btn.dataset.action = 'release';
            } else {
                btn.classList.remove('reserved-by-you', 'pending-confirmation', 'taken');
                btn.dataset.action = 'reserve';
                btn.disabled = false;
            }

            poll();

            const emailNote = (data.action === 'reserved' && data.emailSent)
                ? 'A reservation email with the 15-minute confirmation policy was sent to your registered address.'
                : null;

            showModal('success', 'Success', data.message, emailNote);
        } else {
            showModal('error', 'Allocation Failed', data.message, null);
        }
    })
    .catch(function(err) {
        btn.classList.remove('loading');
        console.error('[SeatSystem] Fetch error:', err);
        showModal('error', 'Request Failed',
            err.message || 'A network error occurred. Please check your connection and try again.',
            null);
    });
}

document.addEventListener('submit', function(e) {
    if (!e.target.classList.contains('reservation-form')) return;
    e.preventDefault();

    const form = e.target;
    const btn  = form.querySelector('button');
    if (btn.classList.contains('loading')) return;

    const formData = new FormData(form);
    formData.append('ajax', '1');

    const isRelease = (btn.dataset.action === 'release');

    if (isRelease) {
        btn.classList.add('loading');
        pendingFormData = formData;
        pendingBtn      = btn;
        showConfirm();
        return;
    }

    btn.classList.add('loading');
    doFetch(formData, btn);
});
</script>

</body>
</html>