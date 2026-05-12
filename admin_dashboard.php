<?php
session_start();
include 'auth.php';
requireLogin();
requireRole('admin', 'superadmin');
include 'db.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$current_role = strtolower((string) ($_SESSION['role'] ?? ''));
$isSuperadmin = ($current_role === 'superadmin');
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_system_hours'])) {
    if (!$isSuperadmin) {
        $_SESSION['error'] = 'Only superadmin can update system hours.';
        header('Location: admin_dashboard.php?tab=dashboard');
        exit;
    }

    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!verifyCsrf($csrf)) {
        $_SESSION['error'] = 'Invalid request token. Please refresh and try again.';
        header('Location: admin_dashboard.php?tab=system-hours');
        exit;
    }

    $openTimeRaw = (string) ($_POST['open_time'] ?? '');
    $closeTimeRaw = (string) ($_POST['close_time'] ?? '');
    $openTime = libraryHoursNormalizeTimeInput($openTimeRaw);
    $closeTime = libraryHoursNormalizeTimeInput($closeTimeRaw);

    if ($openTime === null || $closeTime === null) {
        $_SESSION['error'] = 'Please provide valid opening and closing times.';
        header('Location: admin_dashboard.php?tab=system-hours');
        exit;
    }

    try {
        libraryHoursSetConfig($conn, $openTime, $closeTime, $currentUserId > 0 ? $currentUserId : null);
        $_SESSION['success'] = 'System hours updated successfully.';
    } catch (InvalidArgumentException $e) {
        $_SESSION['error'] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[admin_dashboard/system_hours] ' . $e->getMessage());
        $_SESSION['error'] = 'Unable to update system hours right now. Please try again.';
    }

    header('Location: admin_dashboard.php?tab=system-hours');
    exit;
}

$hoursState = libraryHoursEvaluate($conn);
if ($current_role === 'admin' && !$hoursState['is_open']) {
    libraryHoursRenderClosedPage(
        $conn,
        'Admin Access Temporarily Closed',
        'Admin actions are available only during operating hours.',
        403,
        $hoursState
    );
}

$hoursConfig = (array) ($hoursState['config'] ?? libraryHoursGetConfig($conn));
$hoursNotice = libraryHoursBuildNotice($hoursState);
$hoursOpenTime = (string) ($hoursConfig['open_time'] ?? LIBRARY_HOURS_DEFAULT_OPEN);
$hoursCloseTime = (string) ($hoursConfig['close_time'] ?? LIBRARY_HOURS_DEFAULT_CLOSE);
$hoursOpenInput = substr($hoursOpenTime, 0, 5);
$hoursCloseInput = substr($hoursCloseTime, 0, 5);
$hoursStatusText = $hoursState['is_open'] ? 'Open now' : 'Closed now';
$hoursStatusClass = $hoursState['is_open'] ? 'available' : 'reserved';
$hoursNextOpenDisplay = (string) ($hoursNotice['reopen_display'] ?? '');
if ($hoursNextOpenDisplay === '') {
    $hoursNextOpenDisplay = 'Reopening time unavailable';
}

$allowedInitialTabs = ['dashboard', 'users', 'seats', 'computers', 'rfid-portal', 'logs'];
if ($isSuperadmin) {
    $allowedInitialTabs[] = 'system-hours';
}
$initialTab = 'dashboard';

$requestedTab = trim((string) ($_GET['tab'] ?? ''));
if ($requestedTab !== '' && in_array($requestedTab, $allowedInitialTabs, true)) {
    $initialTab = $requestedTab;
}

if (!empty($_SESSION['redirect_to_users'])) {
    $initialTab = 'users';
    unset($_SESSION['redirect_to_users']);
}

$pageSuccess = isset($_SESSION['success']) ? (string) $_SESSION['success'] : '';
$pageError = isset($_SESSION['error']) ? (string) $_SESSION['error'] : '';
unset($_SESSION['success'], $_SESSION['error']);

$rfidPortalUrl = getenv('RFID_PORTAL_URL');
if ($rfidPortalUrl === false || trim($rfidPortalUrl) === '') {
    $rfidPortalUrl = 'http://172.20.10.3/';
}
$rfidPortalUrl = rtrim((string) $rfidPortalUrl, '/') . '/';
if (!filter_var($rfidPortalUrl, FILTER_VALIDATE_URL)) {
    $rfidPortalUrl = 'http://172.20.10.3/';
}

function adminTableExists(mysqli $conn, string $table): bool
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

function adminColumnExists(mysqli $conn, string $table, string $column): bool
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

function adminClampPage($value): int
{
    $page = (int) $value;
    return $page > 0 ? $page : 1;
}

function adminPaginationState(int $total, int $perPage, int $page): array
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    $start = $total === 0 ? 0 : ($offset + 1);
    $end = $total === 0 ? 0 : min($offset + $perPage, $total);

    return [$page, $totalPages, $offset, $start, $end];
}

function adminPaginationTokens(int $totalPages, int $currentPage): array
{
    if ($totalPages <= 7) {
        return range(1, $totalPages);
    }

    $tokens = [1];
    $start = max(2, $currentPage - 1);
    $end = min($totalPages - 1, $currentPage + 1);

    if ($start > 2) {
        $tokens[] = 'ellipsis-left';
    }

    for ($page = $start; $page <= $end; $page += 1) {
        $tokens[] = $page;
    }

    if ($end < $totalPages - 1) {
        $tokens[] = 'ellipsis-right';
    }

    $tokens[] = $totalPages;
    return $tokens;
}

function adminBuildQuery(array $overrides): string
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return http_build_query($params);
}

function adminFormatDateTime(?string $date, ?string $time): string
{
    $date = trim((string) $date);
    $time = trim((string) $time);
    if ($date === '' || $time === '') {
        return '—';
    }

    $stamp = strtotime($date . ' ' . $time);
    if ($stamp === false) {
        return '—';
    }

    return date('M j, Y H:i', $stamp);
}

function adminFormatTimestamp(?string $timestamp): string
{
    $timestamp = trim((string) $timestamp);
    if ($timestamp === '') {
        return '—';
    }

    $stamp = strtotime($timestamp);
    if ($stamp === false) {
        return '—';
    }

    return date('M j, Y H:i', $stamp);
}

function adminFormatDuration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0m';
    }

    $hours = (int) floor($seconds / 3600);
    $minutes = (int) floor(($seconds % 3600) / 60);
    if ($hours <= 0) {
        return $minutes . 'm';
    }

    return sprintf('%dh %02dm', $hours, $minutes);
}

function adminTruncate(string $value, int $limit = 60): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, max(0, $limit - 1)) . '…';
}

function adminSendCsv(string $filename, array $headers, callable $writer): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    $writer($output);
    fclose($output);
    exit;
}

function adminExportAttendanceEvents(mysqli $conn): void
{
    if (!adminTableExists($conn, 'attendance')) {
        adminSendCsv('attendance_events.csv', ['Date Time', 'Action', 'User', 'Role', 'UID', 'Device'], static function () {
        });
    }

    $hasUserId = adminColumnExists($conn, 'attendance', 'user_id');
    $hasDevice = adminColumnExists($conn, 'attendance', 'device');
    $columns = ['a.name', 'a.uid', 'a.date', 'a.time', 'a.action'];
    if ($hasDevice) {
        $columns[] = 'a.device';
    }
    if ($hasUserId) {
        $columns[] = 'a.user_id';
        $columns[] = 'u.username';
        $columns[] = 'u.role';
    }

    $sql = 'SELECT ' . implode(', ', $columns) . ' FROM attendance a';
    if ($hasUserId) {
        $sql .= ' LEFT JOIN users u ON u.id = a.user_id';
    }
    $sql .= ' ORDER BY a.id DESC';

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();

    adminSendCsv('attendance_events.csv', ['Date Time', 'Action', 'User', 'Role', 'UID', 'Device'], static function ($output) use ($result, $hasDevice) {
        while ($row = $result->fetch_assoc()) {
            $userLabel = '—';
            $rowUserId = (int) ($row['user_id'] ?? 0);
            if (!empty($row['username'])) {
                $userLabel = (string) $row['username'];
            } elseif (!empty($row['name'])) {
                $userLabel = (string) $row['name'];
            } elseif ($rowUserId > 0) {
                $userLabel = 'User #' . $rowUserId;
            }
            $roleLabel = $row['role'] !== null && $row['role'] !== '' ? ucfirst((string) $row['role']) : '';
            $deviceLabel = $hasDevice ? (string) ($row['device'] ?? '') : '';

            fputcsv($output, [
                adminFormatDateTime($row['date'] ?? '', $row['time'] ?? ''),
                (string) ($row['action'] ?? ''),
                $userLabel,
                $roleLabel,
                (string) ($row['uid'] ?? ''),
                $deviceLabel,
            ]);
        }
    });
}

function adminExportAttendanceSessions(mysqli $conn): void
{
    if (!adminTableExists($conn, 'attendance')) {
        adminSendCsv('attendance_sessions.csv', ['Time In', 'Time Out', 'Duration', 'User', 'Role', 'UID', 'Device'], static function () {
        });
    }

    $hasUserId = adminColumnExists($conn, 'attendance', 'user_id');
    $hasDevice = adminColumnExists($conn, 'attendance', 'device');

    $matchCondition = $hasUserId
        ? "((a.user_id IS NOT NULL AND a2.user_id = a.user_id) OR (a.user_id IS NULL AND a2.uid = a.uid))"
        : 'a2.uid = a.uid';

    $timeOutDateSql = "SELECT a2.date FROM attendance a2 WHERE a2.action = 'TIME_OUT' AND a2.id > a.id AND {$matchCondition} ORDER BY a2.id ASC LIMIT 1";
    $timeOutTimeSql = "SELECT a2.time FROM attendance a2 WHERE a2.action = 'TIME_OUT' AND a2.id > a.id AND {$matchCondition} ORDER BY a2.id ASC LIMIT 1";
    $timeOutDeviceSql = $hasDevice
        ? "SELECT a2.device FROM attendance a2 WHERE a2.action = 'TIME_OUT' AND a2.id > a.id AND {$matchCondition} ORDER BY a2.id ASC LIMIT 1"
        : '';

    $columns = [
        'a.user_id',
        'a.name',
        'a.uid',
        'a.date AS time_in_date',
        'a.time AS time_in_time',
        $hasDevice ? 'a.device AS time_in_device' : null,
        "({$timeOutDateSql}) AS time_out_date",
        "({$timeOutTimeSql}) AS time_out_time",
        $hasDevice ? "({$timeOutDeviceSql}) AS time_out_device" : null,
    ];

    if ($hasUserId) {
        $columns[] = 'u.username';
        $columns[] = 'u.role';
    }

    $columns = array_values(array_filter($columns));
    $sql = 'SELECT ' . implode(', ', $columns) . ' FROM attendance a';
    if ($hasUserId) {
        $sql .= ' LEFT JOIN users u ON u.id = a.user_id';
    }
    $sql .= " WHERE a.action = 'TIME_IN' ORDER BY a.id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();

    adminSendCsv('attendance_sessions.csv', ['Time In', 'Time Out', 'Duration', 'User', 'Role', 'UID', 'Device'], static function ($output) use ($result) {
        while ($row = $result->fetch_assoc()) {
            $userLabel = '—';
            $rowUserId = (int) ($row['user_id'] ?? 0);
            if (!empty($row['username'])) {
                $userLabel = (string) $row['username'];
            } elseif (!empty($row['name'])) {
                $userLabel = (string) $row['name'];
            } elseif ($rowUserId > 0) {
                $userLabel = 'User #' . $rowUserId;
            }
            $roleLabel = $row['role'] !== null && $row['role'] !== '' ? ucfirst((string) $row['role']) : '';

            $startStamp = strtotime(($row['time_in_date'] ?? '') . ' ' . ($row['time_in_time'] ?? ''));
            $endStamp = strtotime(($row['time_out_date'] ?? '') . ' ' . ($row['time_out_time'] ?? ''));
            $durationLabel = 'Active';
            if ($startStamp !== false && $endStamp !== false && $endStamp >= $startStamp) {
                $durationLabel = adminFormatDuration((int) ($endStamp - $startStamp));
            }

            $deviceLabel = '—';
            $inDevice = (string) ($row['time_in_device'] ?? '');
            $outDevice = (string) ($row['time_out_device'] ?? '');
            if ($inDevice !== '' || $outDevice !== '') {
                if ($inDevice !== '' && $outDevice !== '' && $outDevice !== $inDevice) {
                    $deviceLabel = $inDevice . ' / ' . $outDevice;
                } else {
                    $deviceLabel = $inDevice !== '' ? $inDevice : $outDevice;
                }
            }

            fputcsv($output, [
                adminFormatDateTime($row['time_in_date'] ?? '', $row['time_in_time'] ?? ''),
                adminFormatDateTime($row['time_out_date'] ?? '', $row['time_out_time'] ?? ''),
                $durationLabel,
                $userLabel,
                $roleLabel,
                (string) ($row['uid'] ?? ''),
                $deviceLabel === '—' ? '' : $deviceLabel,
            ]);
        }
    });
}

function adminExportAuthEvents(mysqli $conn): void
{
    if (!adminTableExists($conn, 'auth_log')) {
        adminSendCsv('auth_events.csv', ['Date Time', 'Action', 'Username', 'Identity', 'Role', 'IP Address', 'Session', 'User Agent'], static function () {
        });
    }

    $sql = 'SELECT username, role, identity, action, session_id, ip_address, user_agent, created_at FROM auth_log ORDER BY id DESC';
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();

    adminSendCsv('auth_events.csv', ['Date Time', 'Action', 'Username', 'Identity', 'Role', 'IP Address', 'Session', 'User Agent'], static function ($output) use ($result) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                adminFormatTimestamp($row['created_at'] ?? ''),
                (string) ($row['action'] ?? ''),
                (string) ($row['username'] ?? ''),
                (string) ($row['identity'] ?? ''),
                (string) ($row['role'] ?? ''),
                (string) ($row['ip_address'] ?? ''),
                (string) ($row['session_id'] ?? ''),
                (string) ($row['user_agent'] ?? ''),
            ]);
        }
    });
}

function adminExportAuthSessions(mysqli $conn): void
{
    if (!adminTableExists($conn, 'auth_log')) {
        adminSendCsv('auth_sessions.csv', ['Login', 'Logout', 'Duration', 'Username', 'Identity', 'Role', 'IP Address', 'Session', 'User Agent'], static function () {
        });
    }

    $sql = "SELECT l.username, l.role, l.identity, l.session_id, l.ip_address, l.user_agent, l.created_at AS login_at,"
        . " (SELECT l2.created_at FROM auth_log l2 WHERE l2.action = 'logout' AND l2.id > l.id AND l2.session_id = l.session_id ORDER BY l2.id ASC LIMIT 1) AS logout_at"
        . " FROM auth_log l WHERE l.action = 'login_success' ORDER BY l.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();

    adminSendCsv('auth_sessions.csv', ['Login', 'Logout', 'Duration', 'Username', 'Identity', 'Role', 'IP Address', 'Session', 'User Agent'], static function ($output) use ($result) {
        while ($row = $result->fetch_assoc()) {
            $startStamp = strtotime((string) ($row['login_at'] ?? ''));
            $endStamp = strtotime((string) ($row['logout_at'] ?? ''));
            $durationLabel = 'Active';
            if ($startStamp !== false && $endStamp !== false && $endStamp >= $startStamp) {
                $durationLabel = adminFormatDuration((int) ($endStamp - $startStamp));
            }

            fputcsv($output, [
                adminFormatTimestamp($row['login_at'] ?? ''),
                adminFormatTimestamp($row['logout_at'] ?? ''),
                $durationLabel,
                (string) ($row['username'] ?? ''),
                (string) ($row['identity'] ?? ''),
                (string) ($row['role'] ?? ''),
                (string) ($row['ip_address'] ?? ''),
                (string) ($row['session_id'] ?? ''),
                (string) ($row['user_agent'] ?? ''),
            ]);
        }
    });
}

if (isset($_GET['export'])) {
    $export = trim((string) $_GET['export']);
    if ($export === 'attendance-events') {
        adminExportAttendanceEvents($conn);
    } elseif ($export === 'attendance-sessions') {
        adminExportAttendanceSessions($conn);
    } elseif ($export === 'auth-events') {
        adminExportAuthEvents($conn);
    } elseif ($export === 'auth-sessions') {
        adminExportAuthSessions($conn);
    }
}

$total_users               = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM users"))['total'];
$total_reserved            = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM seats WHERE status='reserved'"))['total'];
$total_available           = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM seats WHERE status='available'"))['total'];
$total_computers           = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM computers WHERE status='reserved'"))['total'];
$total_computers_available = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM computers WHERE status='available'"))['total'];

$seats_query = mysqli_query($conn, "
    SELECT seats.id, seats.seat_number, seats.status, users.username
    FROM seats
    LEFT JOIN users ON seats.reserved_by = users.id
");

$computers_query = mysqli_query($conn, "
    SELECT computers.id, computers.computer_number, computers.status,
           computers.reserved_at, computers.expires_at, users.username
    FROM computers
    LEFT JOIN users ON computers.reserved_by = users.id
");

$attendanceLogAvailable = adminTableExists($conn, 'attendance');
$attendanceHasUserId = $attendanceLogAvailable && adminColumnExists($conn, 'attendance', 'user_id');
$attendanceHasDevice = $attendanceLogAvailable && adminColumnExists($conn, 'attendance', 'device');

$attendanceEvents = [];
$attendanceEventsTotal = 0;
$attendanceEventsPerPage = 20;
$attendanceEventsPage = adminClampPage($_GET['attPage'] ?? 1);
$attendanceEventsTotalPages = 1;
$attendanceEventsOffset = 0;
$attendanceEventsStart = 0;
$attendanceEventsEnd = 0;

if ($attendanceLogAvailable) {
    $countResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM attendance');
    $attendanceEventsTotal = (int) mysqli_fetch_assoc($countResult)['total'];
    [$attendanceEventsPage, $attendanceEventsTotalPages, $attendanceEventsOffset, $attendanceEventsStart, $attendanceEventsEnd]
        = adminPaginationState($attendanceEventsTotal, $attendanceEventsPerPage, $attendanceEventsPage);

    $columns = ['a.id', 'a.name', 'a.uid', 'a.date', 'a.time', 'a.action', 'a.created_at'];
    if ($attendanceHasDevice) {
        $columns[] = 'a.device';
    }
    if ($attendanceHasUserId) {
        $columns[] = 'a.user_id';
        $columns[] = 'u.username';
        $columns[] = 'u.role';
        $columns[] = 'u.email';
    }

    $sql = 'SELECT ' . implode(', ', $columns) . ' FROM attendance a';
    if ($attendanceHasUserId) {
        $sql .= ' LEFT JOIN users u ON u.id = a.user_id';
    }
    $sql .= ' ORDER BY a.id DESC LIMIT ? OFFSET ?';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $attendanceEventsPerPage, $attendanceEventsOffset);
    $stmt->execute();
    $attendanceEvents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$attendanceSessions = [];
$attendanceSessionsTotal = 0;
$attendanceSessionsPerPage = 15;
$attendanceSessionsPage = adminClampPage($_GET['attSessionPage'] ?? 1);
$attendanceSessionsTotalPages = 1;
$attendanceSessionsOffset = 0;
$attendanceSessionsStart = 0;
$attendanceSessionsEnd = 0;

if ($attendanceLogAvailable) {
    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM attendance WHERE action = 'TIME_IN'");
    $attendanceSessionsTotal = (int) mysqli_fetch_assoc($countResult)['total'];
    [$attendanceSessionsPage, $attendanceSessionsTotalPages, $attendanceSessionsOffset, $attendanceSessionsStart, $attendanceSessionsEnd]
        = adminPaginationState($attendanceSessionsTotal, $attendanceSessionsPerPage, $attendanceSessionsPage);

    $matchCondition = $attendanceHasUserId
        ? "((a.user_id IS NOT NULL AND a2.user_id = a.user_id) OR (a.user_id IS NULL AND a2.uid = a.uid))"
        : 'a2.uid = a.uid';

    $timeOutDateSql = "SELECT a2.date FROM attendance a2 WHERE a2.action = 'TIME_OUT' AND a2.id > a.id AND {$matchCondition} ORDER BY a2.id ASC LIMIT 1";
    $timeOutTimeSql = "SELECT a2.time FROM attendance a2 WHERE a2.action = 'TIME_OUT' AND a2.id > a.id AND {$matchCondition} ORDER BY a2.id ASC LIMIT 1";
    $timeOutDeviceSql = $attendanceHasDevice
        ? "SELECT a2.device FROM attendance a2 WHERE a2.action = 'TIME_OUT' AND a2.id > a.id AND {$matchCondition} ORDER BY a2.id ASC LIMIT 1"
        : '';

    $columns = [
        'a.id AS time_in_id',
        'a.user_id',
        'a.name',
        'a.uid',
        'a.date AS time_in_date',
        'a.time AS time_in_time',
        $attendanceHasDevice ? 'a.device AS time_in_device' : null,
        "({$timeOutDateSql}) AS time_out_date",
        "({$timeOutTimeSql}) AS time_out_time",
        $attendanceHasDevice ? "({$timeOutDeviceSql}) AS time_out_device" : null,
    ];

    if ($attendanceHasUserId) {
        $columns[] = 'u.username';
        $columns[] = 'u.role';
        $columns[] = 'u.email';
    }

    $columns = array_values(array_filter($columns));

    $sql = 'SELECT ' . implode(', ', $columns) . ' FROM attendance a';
    if ($attendanceHasUserId) {
        $sql .= ' LEFT JOIN users u ON u.id = a.user_id';
    }
    $sql .= " WHERE a.action = 'TIME_IN' ORDER BY a.id DESC LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $attendanceSessionsPerPage, $attendanceSessionsOffset);
    $stmt->execute();
    $attendanceSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($attendanceSessions as &$row) {
        $startStamp = strtotime(($row['time_in_date'] ?? '') . ' ' . ($row['time_in_time'] ?? ''));
        $endStamp = strtotime(($row['time_out_date'] ?? '') . ' ' . ($row['time_out_time'] ?? ''));
        if ($startStamp !== false && $endStamp !== false && $endStamp >= $startStamp) {
            $row['duration_label'] = adminFormatDuration((int) ($endStamp - $startStamp));
        } else {
            $row['duration_label'] = 'Active';
        }
    }
    unset($row);
}

$authLogAvailable = adminTableExists($conn, 'auth_log');
$authEvents = [];
$authEventsTotal = 0;
$authEventsPerPage = 20;
$authEventsPage = adminClampPage($_GET['authPage'] ?? 1);
$authEventsTotalPages = 1;
$authEventsOffset = 0;
$authEventsStart = 0;
$authEventsEnd = 0;

if ($authLogAvailable) {
    $countResult = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM auth_log');
    $authEventsTotal = (int) mysqli_fetch_assoc($countResult)['total'];
    [$authEventsPage, $authEventsTotalPages, $authEventsOffset, $authEventsStart, $authEventsEnd]
        = adminPaginationState($authEventsTotal, $authEventsPerPage, $authEventsPage);

    $sql = 'SELECT id, user_id, username, role, identity, action, session_id, ip_address, user_agent, created_at '
        . 'FROM auth_log ORDER BY id DESC LIMIT ? OFFSET ?';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $authEventsPerPage, $authEventsOffset);
    $stmt->execute();
    $authEvents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$authSessions = [];
$authSessionsTotal = 0;
$authSessionsPerPage = 15;
$authSessionsPage = adminClampPage($_GET['authSessionPage'] ?? 1);
$authSessionsTotalPages = 1;
$authSessionsOffset = 0;
$authSessionsStart = 0;
$authSessionsEnd = 0;

if ($authLogAvailable) {
    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM auth_log WHERE action = 'login_success'");
    $authSessionsTotal = (int) mysqli_fetch_assoc($countResult)['total'];
    [$authSessionsPage, $authSessionsTotalPages, $authSessionsOffset, $authSessionsStart, $authSessionsEnd]
        = adminPaginationState($authSessionsTotal, $authSessionsPerPage, $authSessionsPage);

    $matchCondition = 'l2.session_id = l.session_id';
    $sql = "SELECT l.id AS login_id, l.user_id, l.username, l.role, l.identity, l.session_id, l.ip_address, l.user_agent, l.created_at AS login_at,"
        . " (SELECT l2.created_at FROM auth_log l2 WHERE l2.action = 'logout' AND l2.id > l.id AND {$matchCondition} ORDER BY l2.id ASC LIMIT 1) AS logout_at"
        . " FROM auth_log l WHERE l.action = 'login_success' ORDER BY l.id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $authSessionsPerPage, $authSessionsOffset);
    $stmt->execute();
    $authSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($authSessions as &$row) {
        $startStamp = strtotime((string) ($row['login_at'] ?? ''));
        $endStamp = strtotime((string) ($row['logout_at'] ?? ''));
        if ($startStamp !== false && $endStamp !== false && $endStamp >= $startStamp) {
            $row['duration_label'] = adminFormatDuration((int) ($endStamp - $startStamp));
        } else {
            $row['duration_label'] = 'Active';
        }
    }
    unset($row);
}

$rfidUidByUserId = [];

if (
    adminTableExists($conn, 'rfid_cards')
    && adminColumnExists($conn, 'rfid_cards', 'user_id')
    && adminColumnExists($conn, 'rfid_cards', 'uid')
) {
    $cardsStatusFilter = adminColumnExists($conn, 'rfid_cards', 'status')
        ? " WHERE status='active'"
        : '';

    $cardsResult = mysqli_query(
        $conn,
        "SELECT user_id, GROUP_CONCAT(DISTINCT uid ORDER BY uid SEPARATOR ', ') AS uids
         FROM rfid_cards{$cardsStatusFilter}
         GROUP BY user_id"
    );

    if ($cardsResult instanceof mysqli_result) {
        while ($row = mysqli_fetch_assoc($cardsResult)) {
            $uidOwner = (int) ($row['user_id'] ?? 0);
            if ($uidOwner <= 0) {
                continue;
            }
            $uids = array_filter(array_map('trim', explode(',', (string) ($row['uids'] ?? ''))));
            foreach ($uids as $uid) {
                if (!isset($rfidUidByUserId[$uidOwner])) {
                    $rfidUidByUserId[$uidOwner] = [];
                }
                $rfidUidByUserId[$uidOwner][$uid] = true;
            }
        }
    }
}

if (
    adminTableExists($conn, 'rfid_devices')
    && adminColumnExists($conn, 'rfid_devices', 'user_id')
    && adminColumnExists($conn, 'rfid_devices', 'uid')
) {
    $devicesStatusFilter = adminColumnExists($conn, 'rfid_devices', 'status')
        ? " WHERE status='active'"
        : '';

    $devicesResult = mysqli_query(
        $conn,
        "SELECT user_id, GROUP_CONCAT(DISTINCT uid ORDER BY uid SEPARATOR ', ') AS uids
         FROM rfid_devices{$devicesStatusFilter}
         GROUP BY user_id"
    );

    if ($devicesResult instanceof mysqli_result) {
        while ($row = mysqli_fetch_assoc($devicesResult)) {
            $uidOwner = (int) ($row['user_id'] ?? 0);
            if ($uidOwner <= 0) {
                continue;
            }
            $uids = array_filter(array_map('trim', explode(',', (string) ($row['uids'] ?? ''))));
            foreach ($uids as $uid) {
                if (!isset($rfidUidByUserId[$uidOwner])) {
                    $rfidUidByUserId[$uidOwner] = [];
                }
                $rfidUidByUserId[$uidOwner][$uid] = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — SCC Library</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>


*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --gold:         #c8a96e;
    --gold-light:   #e2c99a;
    --gold-dim:     rgba(200, 169, 110, 0.18);
    --gold-glow:    rgba(200, 169, 110, 0.30);
    --dark:         #0a0e14;

    --glass-bg:     rgba(255, 255, 255, 0.055);
    --glass-bg-md:  rgba(255, 255, 255, 0.08);
    --glass-bg-hv:  rgba(255, 255, 255, 0.10);
    --glass-border: rgba(200, 169, 110, 0.20);
    --glass-blur:   blur(20px) saturate(150%);

    --green:        #22c55e;
    --green-glow:   rgba(34,197,94,0.30);
    --amber:        #f59e0b;
    --red:          #ef4444;
    --red-glow:     rgba(239,68,68,0.28);
    --blue:         #3b82f6;

    --text-main:    #f0ece4;
    --text-sub:     rgba(240, 236, 228, 0.65);
    --text-muted:   rgba(240, 236, 228, 0.38);

    --radius:       12px;
    --radius-lg:    18px;
    --radius-xl:    22px;
    --transition:   0.25s cubic-bezier(0.4, 0, 0.2, 1);
    --shadow:       0 8px 32px rgba(0,0,0,0.50);
    --shadow-sm:    0 2px 12px rgba(0,0,0,0.35);

    --sidebar-w:    240px;
}

html, body { height: 100%; }

body {
    font-family: 'DM Sans', sans-serif;
    color: var(--text-main);
    min-height: 100vh;
    overflow-x: hidden;
}

.bg-layer {
    position: fixed; inset: 0;
    background-image: url('bg.jpg');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    z-index: -2;
}

.bg-overlay {
    position: fixed; inset: 0;
    background: linear-gradient(
        135deg,
        rgba(10,14,20,0.97) 0%,
        rgba(10,14,20,0.93) 40%,
        rgba(10,14,20,0.85) 70%,
        rgba(10,14,20,0.90) 100%
    );
    z-index: -1;
}

.wrapper {
    display: flex;
    min-height: 100vh;
}

.sidebar {
    width: var(--sidebar-w);
    min-width: var(--sidebar-w);
    background: rgba(10, 14, 20, 0.75);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
    border-right: 1px solid var(--glass-border);
    display: flex;
    flex-direction: column;
    padding: 0;
    position: sticky;
    top: 0;
    height: 100vh;
    z-index: 90;
    transition: transform var(--transition);
    flex-shrink: 0;
}

.sidebar-header {
    padding: 28px 20px 22px;
    border-bottom: 1px solid var(--glass-border);
    display: flex;
    align-items: center;
    gap: 13px;
}

.sidebar-logo-ring {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: rgba(200,169,110,0.10);
    border: 1.5px solid var(--glass-border);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 0 0 4px rgba(200,169,110,0.06);
}
.sidebar-logo-ring img { width: 26px; height: auto; filter: drop-shadow(0 1px 4px rgba(200,169,110,0.30)); }

.sidebar-brand-text .brand-name {
    font-family: 'Cormorant Garamond', serif;
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--gold-light);
    line-height: 1.2;
}
.sidebar-brand-text .brand-role {
    font-size: 0.67rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.10em;
    margin-top: 2px;
}

.sidebar-nav {
    flex: 1;
    padding: 18px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    overflow-y: auto;
}

.nav-section-label {
    font-size: 0.62rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.14em;
    padding: 10px 10px 6px;
}

.tab-button {
    background: transparent;
    border: none;
    border-radius: var(--radius);
    color: var(--text-sub);
    padding: 11px 14px;
    text-align: left;
    cursor: pointer;
    width: 100%;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.88rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all var(--transition);
    border: 1px solid transparent;
    letter-spacing: 0.01em;
}
.tab-button .nav-icon { font-size: 1.05rem; flex-shrink: 0; width: 20px; text-align: center; }

.tab-button:hover {
    background: var(--glass-bg);
    color: var(--text-main);
    border-color: var(--glass-border);
}

.tab-button.active {
    background: rgba(200, 169, 110, 0.12);
    border-color: rgba(200, 169, 110, 0.32);
    color: var(--gold-light);
    font-weight: 600;
    box-shadow: 0 2px 12px rgba(200,169,110,0.12);
}

.sidebar-footer {
    padding: 16px 14px;
    border-top: 1px solid var(--glass-border);
}

.btn-logout {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 14px;
    background: rgba(239, 68, 68, 0.08);
    border: 1.5px solid rgba(239, 68, 68, 0.22);
    border-radius: var(--radius);
    color: #f87171;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.84rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all var(--transition);
    letter-spacing: 0.02em;
}
.btn-logout:hover {
    background: rgba(239,68,68,0.16);
    border-color: rgba(239,68,68,0.45);
    color: #fca5a5;
}

.topbar {
    display: none;
    position: sticky;
    top: 0;
    z-index: 100;
    background: rgba(10, 14, 20, 0.88);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
    border-bottom: 1px solid var(--glass-border);
    padding: 12px 18px;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.topbar-brand {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1rem;
    font-weight: 600;
    color: var(--gold-light);
}
.hamburger {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: 9px;
    color: var(--text-main);
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    cursor: pointer;
    transition: all var(--transition);
    flex-shrink: 0;
}
.hamburger:hover { background: var(--glass-bg-md); }

.drawer-overlay {
    display: none;
    position: fixed; inset: 0;
    z-index: 200;
    background: rgba(0,0,0,0.60);
    backdrop-filter: blur(4px);
}
.drawer-overlay.open { display: block; }

@media (max-width: 768px) {
    .topbar  { display: flex; }
    .sidebar {
        position: fixed;
        left: 0; top: 0; bottom: 0;
        transform: translateX(-100%);
        z-index: 201;
        height: 100%;
    }
    .sidebar.open { transform: translateX(0); }
}


.main-content {
    flex: 1;
    padding: 30px 28px 60px;
    overflow-x: hidden;
    min-width: 0;
}

.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 14px;
}

.page-header-text .greeting {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 0.12em;
    margin-bottom: 4px;
}

.page-header-text h1 {
    font-family: 'Cormorant Garamond', serif;
    font-size: clamp(1.6rem, 3vw, 2.2rem);
    font-weight: 300;
    color: var(--text-main);
    line-height: 1.15;
}
.page-header-text h1 em { font-style: italic; color: var(--gold-light); }

.header-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(200,169,110,0.10);
    border: 1px solid var(--glass-border);
    border-radius: 20px;
    padding: 6px 14px;
    font-size: 0.76rem;
    font-weight: 600;
    color: var(--gold);
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-top: 6px;
}

.panel-flash {
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 0.84rem;
    line-height: 1.5;
    margin-bottom: 14px;
}

.panel-flash.ok {
    background: rgba(34, 197, 94, 0.14);
    border: 1px solid rgba(34, 197, 94, 0.30);
    color: #4ade80;
}

.panel-flash.err {
    background: rgba(239, 68, 68, 0.14);
    border: 1px solid rgba(239, 68, 68, 0.30);
    color: #fca5a5;
}

/* ── Sections ── */
.section { display: none; }
.section.active { display: block; }

/* ── Section title ── */
.section-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.4rem;
    font-weight: 400;
    color: var(--text-main);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.section-title::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(to right, var(--glass-border), transparent);
}

.section-subtitle {
    font-size: 0.98rem;
    font-weight: 600;
    color: var(--gold-light);
    margin: 18px 0 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: 0.02em;
}

.section-subtitle::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(to right, rgba(200,169,110,0.35), transparent);
}
.cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}

.card {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: 22px 18px;
    text-align: center;
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow: var(--shadow-sm);
    transition: border-color var(--transition), box-shadow var(--transition), transform var(--transition);
    position: relative;
    overflow: hidden;
}
.card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(to right, transparent, var(--gold), transparent);
    opacity: 0;
    transition: opacity var(--transition);
}
.card:hover {
    border-color: var(--gold-glow);
    box-shadow: 0 8px 28px rgba(0,0,0,0.45);
    transform: translateY(-3px);
}
.card:hover::before { opacity: 1; }

.card-icon { font-size: 1.55rem; margin-bottom: 10px; display: block; }

.card h3 {
    font-size: 0.70rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.10em;
    margin-bottom: 8px;
}

.card p {
    font-family: 'Cormorant Garamond', serif;
    font-size: 2.2rem;
    font-weight: 600;
    color: var(--gold-light);
    line-height: 1;
}

.card.green p { color: #4ade80; }
.card.red   p { color: #f87171; }
.card.blue  p { color: #60a5fa; }
.card.amber p { color: #fbbf24; }

.welcome-box {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: 22px 24px;
    color: var(--text-sub);
    font-size: 0.90rem;
    line-height: 1.7;
    backdrop-filter: blur(14px);
}
.welcome-box strong { color: var(--gold); }

.table-wrap {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    margin-bottom: 24px;
}

.table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 480px;
}

thead tr {
    background: rgba(200, 169, 110, 0.10);
    border-bottom: 1px solid var(--glass-border);
}

th {
    padding: 13px 16px;
    text-align: left;
    font-size: 0.70rem;
    font-weight: 700;
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 0.10em;
    white-space: nowrap;
}

td {
    padding: 12px 16px;
    border-bottom: 1px solid rgba(200,169,110,0.07);
    font-size: 0.88rem;
    color: var(--text-sub);
    vertical-align: middle;
}

tbody tr { transition: background var(--transition); }
tbody tr:hover { background: rgba(255,255,255,0.04); }
tbody tr:last-child td { border-bottom: none; }


.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.badge.reserved {
    background: rgba(200,169,110,0.14);
    border: 1px solid rgba(200,169,110,0.32);
    color: var(--gold-light);
}
.badge.available {
    background: rgba(34,197,94,0.12);
    border: 1px solid rgba(34,197,94,0.28);
    color: #4ade80;
}
.badge.success {
    background: rgba(34,197,94,0.12);
    border: 1px solid rgba(34,197,94,0.28);
    color: #4ade80;
}
.badge.warn {
    background: rgba(245,158,11,0.12);
    border: 1px solid rgba(245,158,11,0.30);
    color: #fbbf24;
}
.badge.danger {
    background: rgba(239,68,68,0.12);
    border: 1px solid rgba(239,68,68,0.28);
    color: #f87171;
}
.badge.info {
    background: rgba(59,130,246,0.12);
    border: 1px solid rgba(59,130,246,0.30);
    color: #93c5fd;
}
.badge::before {
    content: '';
    width: 5px; height: 5px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}

.cell-sub {
    display: block;
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 4px;
}

.actions { display: flex; gap: 7px; flex-wrap: wrap; }

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: 8px;
    text-decoration: none;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.78rem;
    font-weight: 600;
    transition: all var(--transition);
    white-space: nowrap;
    border: 1px solid transparent;
}

.btn-action.edit {
    background: rgba(59,130,246,0.12);
    border-color: rgba(59,130,246,0.28);
    color: #93c5fd;
}
.btn-action.edit:hover {
    background: rgba(59,130,246,0.22);
    border-color: rgba(59,130,246,0.50);
    color: #bfdbfe;
}

.btn-action.delete {
    background: rgba(239,68,68,0.10);
    border-color: rgba(239,68,68,0.25);
    color: #f87171;
}
.btn-action.delete:hover {
    background: rgba(239,68,68,0.20);
    border-color: rgba(239,68,68,0.48);
    color: #fca5a5;
}

.btn-action.free {
    background: rgba(239,68,68,0.10);
    border-color: rgba(239,68,68,0.25);
    color: #f87171;
}
.btn-action.free:hover {
    background: rgba(239,68,68,0.20);
    border-color: rgba(239,68,68,0.48);
    color: #fca5a5;
}

.dash-cell { color: var(--text-muted); }

.empty-row td {
    text-align: center;
    color: var(--text-muted);
    font-style: italic;
    padding: 32px;
}

.table-pagination {
    border-top: 1px solid rgba(200,169,110,0.12);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px 14px;
    flex-wrap: wrap;
}

.pagination-meta {
    font-size: 0.78rem;
    color: var(--text-muted);
    letter-spacing: 0.02em;
}

.pagination-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-left: auto;
}

.pagination-pages {
    display: flex;
    align-items: center;
    gap: 6px;
}

.page-btn,
.page-number {
    appearance: none;
    border: 1px solid var(--glass-border);
    background: rgba(255,255,255,0.05);
    color: var(--text-sub);
    border-radius: 9px;
    min-width: 34px;
    height: 32px;
    padding: 0 10px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: background var(--transition), border-color var(--transition), color var(--transition), transform var(--transition);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.page-btn:hover:not(:disabled),
.page-number:hover:not(:disabled) {
    background: rgba(200,169,110,0.16);
    border-color: rgba(200,169,110,0.40);
    color: var(--gold-light);
    transform: translateY(-1px);
}

.page-btn:disabled,
.page-number:disabled {
    opacity: 0.42;
    cursor: not-allowed;
    transform: none;
}

.page-number.active,
.page-number[aria-current='page'] {
    background: linear-gradient(135deg, rgba(200,169,110,0.90) 0%, rgba(160,120,64,0.90) 100%);
    border-color: rgba(200,169,110,0.75);
    color: #1a1208;
    cursor: default;
}

.page-ellipsis {
    color: var(--text-muted);
    font-size: 0.86rem;
    padding: 0 2px;
    user-select: none;
}

.rfid-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}

.rfid-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid var(--glass-border);
    background: var(--glass-bg);
    color: var(--text-main);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.84rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all var(--transition);
}

.rfid-btn:hover {
    background: var(--glass-bg-md);
    border-color: rgba(200, 169, 110, 0.35);
}

.rfid-btn.warn {
    background: rgba(245, 158, 11, 0.10);
    border-color: rgba(245, 158, 11, 0.30);
    color: #fbbf24;
}

.rfid-btn.warn:hover {
    background: rgba(245, 158, 11, 0.18);
    border-color: rgba(245, 158, 11, 0.48);
}

.rfid-btn.danger {
    background: rgba(239, 68, 68, 0.10);
    border-color: rgba(239, 68, 68, 0.30);
    color: #f87171;
}

.rfid-btn.danger:hover {
    background: rgba(239, 68, 68, 0.18);
    border-color: rgba(239, 68, 68, 0.50);
}

.rfid-inline-panel {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    padding: 12px;
    margin-bottom: 14px;
}

.rfid-inline-panel label {
    display: block;
    font-size: 0.78rem;
    color: var(--text-sub);
    margin-bottom: 7px;
}

.rfid-inline-panel input {
    width: 100%;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--glass-border);
    border-radius: 8px;
    color: var(--text-main);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.84rem;
    padding: 10px 11px;
    margin-bottom: 8px;
}

.rfid-inline-panel input:focus {
    outline: none;
    border-color: rgba(200, 169, 110, 0.50);
    box-shadow: 0 0 0 3px rgba(200, 169, 110, 0.16);
}

.rfid-note {
    font-size: 0.76rem;
    color: var(--text-muted);
    line-height: 1.5;
}

.rfid-ops-message {
    margin-bottom: 14px;
    display: none;
}

.rfid-ops-message.ok,
.rfid-ops-message.err,
.rfid-ops-message.warn {
    display: block;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 0.84rem;
    line-height: 1.5;
}

.rfid-ops-message.ok {
    background: rgba(34, 197, 94, 0.14);
    border: 1px solid rgba(34, 197, 94, 0.30);
    color: #4ade80;
}

.rfid-ops-message.warn {
    background: rgba(245, 158, 11, 0.14);
    border: 1px solid rgba(245, 158, 11, 0.30);
    color: #fbbf24;
}

.rfid-ops-message.err {
    background: rgba(239, 68, 68, 0.14);
    border: 1px solid rgba(239, 68, 68, 0.30);
    color: #fca5a5;
}

.rfid-frame-wrap {
    overflow: hidden;
    padding: 0;
}

.rfid-frame {
    width: 100%;
    min-height: 780px;
    border: 0;
    display: block;
    background: rgba(10, 14, 20, 0.72);
}

.hours-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
    margin-bottom: 14px;
}

.hours-card {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    padding: 14px;
}

.hours-label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    margin-bottom: 6px;
}

.hours-value {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.45rem;
    color: var(--gold-light);
    line-height: 1.1;
}

.hours-form {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    padding: 16px;
}

.hours-input-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 12px;
}

.hours-input-group label {
    display: block;
    font-size: 0.78rem;
    color: var(--text-sub);
    margin-bottom: 6px;
}

.hours-input-group input[type='time'] {
    width: 100%;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--glass-border);
    border-radius: 8px;
    color: var(--text-main);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.9rem;
    padding: 10px 11px;
}

.hours-input-group input[type='time']:focus {
    outline: none;
    border-color: rgba(200, 169, 110, 0.50);
    box-shadow: 0 0 0 3px rgba(200, 169, 110, 0.16);
}

.hours-help {
    font-size: 0.78rem;
    color: var(--text-muted);
    line-height: 1.5;
    margin-bottom: 12px;
}

.hours-submit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 14px;
    border-radius: 9px;
    border: 1px solid rgba(200, 169, 110, 0.38);
    background: rgba(200, 169, 110, 0.14);
    color: var(--gold-light);
    font-family: 'DM Sans', sans-serif;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all var(--transition);
}

.hours-submit:hover {
    background: rgba(200, 169, 110, 0.22);
    border-color: rgba(200, 169, 110, 0.55);
}

/* ═══════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════ */
@media (max-width: 900px) {
    .main-content { padding: 24px 18px 60px; }
}

@media (max-width: 600px) {
    .main-content { padding: 18px 14px 60px; }
    .cards { grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .card p { font-size: 1.8rem; }
    .page-header-text h1 { font-size: 1.5rem; }
    th, td { padding: 10px 12px; }
    .rfid-frame { min-height: 640px; }
    .table-pagination { padding: 10px 12px 14px; }
    .pagination-controls { width: 100%; justify-content: space-between; margin-left: 0; }
    .pagination-pages { flex: 1; justify-content: center; }
}

@media (max-width: 380px) {
    .cards { grid-template-columns: 1fr 1fr; }
}

@media print {
    body {
        background: #fff;
        color: #111;
    }
    .bg-layer,
    .bg-overlay,
    .sidebar,
    .topbar,
    .drawer-overlay,
    .no-print {
        display: none !important;
    }
    .main-content {
        padding: 0;
    }
    .section {
        display: none !important;
    }
    #logs {
        display: block !important;
    }
    .table-wrap {
        box-shadow: none;
        border: 1px solid #ccc;
    }
    th {
        color: #111;
    }
    td {
        color: #222;
    }
}
</style>
</head>
<body>

<!-- ── Background ── -->
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div class="drawer-overlay" id="drawerOverlay"></div>

<!-- ══════════════════════════════════
     SIDEBAR
══════════════════════════════════ -->
<div class="wrapper">
<nav class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <div class="sidebar-logo-ring">
            <img src="logo.png" alt="SCC Logo">
        </div>
        <div class="sidebar-brand-text">
            <div class="brand-name">SCC Library</div>
            <div class="brand-role"><?= ucfirst(htmlspecialchars($current_role)) ?> Panel</div>
        </div>
    </div>

    <div class="sidebar-nav">
        <div class="nav-section-label">Navigation</div>

        <button class="tab-button <?= $initialTab === 'dashboard' ? 'active' : '' ?>" data-tab="dashboard">
            <span class="nav-icon">🏠</span> Dashboard
        </button>
        <button class="tab-button <?= $initialTab === 'users' ? 'active' : '' ?>" data-tab="users">
            <span class="nav-icon">👥</span> Manage Users
        </button>
        <button class="tab-button <?= $initialTab === 'seats' ? 'active' : '' ?>" data-tab="seats">
            <span class="nav-icon">🪑</span> Seat Allocation
        </button>
        <button class="tab-button <?= $initialTab === 'computers' ? 'active' : '' ?>" data-tab="computers">
            <span class="nav-icon">🖥️</span> Manage Computers
        </button>
        <button class="tab-button <?= $initialTab === 'rfid-portal' ? 'active' : '' ?>" data-tab="rfid-portal">
            <span class="nav-icon">📡</span> RFID Portal
        </button>
        <button class="tab-button <?= $initialTab === 'logs' ? 'active' : '' ?>" data-tab="logs">
            <span class="nav-icon">📋</span> Logs
        </button>
        <?php if ($isSuperadmin): ?>
        <button class="tab-button <?= $initialTab === 'system-hours' ? 'active' : '' ?>" data-tab="system-hours">
            <span class="nav-icon">⏰</span> System Hours
        </button>
        <?php endif; ?>
    </div>

    <div class="sidebar-footer">
        <a href="logout.php" class="btn-logout">
            <span>⎋</span> Logout
        </a>
    </div>
</nav>

<!-- ══════════════════════════════════
     MAIN CONTENT
══════════════════════════════════ -->
<div style="flex:1; display:flex; flex-direction:column; min-width:0; overflow:hidden;">

    <!-- Mobile topbar -->
    <div class="topbar" id="topbar">
        <button class="hamburger" id="hamburger" aria-label="Open menu">☰</button>
        <span class="topbar-brand">Admin Dashboard</span>
        <a href="logout.php" style="font-size:0.78rem; color:#f87171; text-decoration:none; font-weight:600;">Logout</a>
    </div>

    <div class="main-content">

        <!-- Page header -->
        <div class="page-header">
            <div class="page-header-text">
                <div class="greeting">Control Center</div>
                <h1>Welcome, <em><?= htmlspecialchars($_SESSION['username']) ?></em></h1>
                <div class="header-badge">📅 <?= date('F j, Y') ?></div>
            </div>
        </div>

        <?php if ($pageSuccess !== ''): ?>
        <div class="panel-flash ok"><?= htmlspecialchars($pageSuccess) ?></div>
        <?php endif; ?>
        <?php if ($pageError !== ''): ?>
        <div class="panel-flash err"><?= htmlspecialchars($pageError) ?></div>
        <?php endif; ?>

        <!-- ══════ DASHBOARD SECTION ══════ -->
        <section id="dashboard" class="section <?= $initialTab === 'dashboard' ? 'active' : '' ?>">

            <div class="cards">
                <div class="card">
                    <span class="card-icon">👥</span>
                    <h3>Total Users</h3>
                    <p><?= $total_users ?></p>
                </div>
                <div class="card green">
                    <span class="card-icon">🪑</span>
                    <h3>Available Seats</h3>
                    <p><?= $total_available ?></p>
                </div>
                <div class="card amber">
                    <span class="card-icon">📌</span>
                    <h3>Allocated Seats</h3>
                    <p><?= $total_reserved ?></p>
                </div>
                <div class="card blue">
                    <span class="card-icon">💻</span>
                    <h3>Available Computers</h3>
                    <p><?= $total_computers_available ?></p>
                </div>
                <div class="card red">
                    <span class="card-icon">🔒</span>
                    <h3>Allocated Computers</h3>
                    <p><?= $total_computers ?></p>
                </div>
            </div>

            <div class="welcome-box">
                Welcome to the <strong>St. Clare College of Caloocan Library Admin Panel</strong>.
                Use the sidebar to manage users, monitor seat and computer allocations,
                and keep the library system running smoothly.
            </div>
        </section>

        <!-- ══════ USERS SECTION ══════ -->
        <section id="users" class="section <?= $initialTab === 'users' ? 'active' : '' ?>">
            <div class="section-title">Manage Users</div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>RFID UID</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $result = mysqli_query($conn, "SELECT * FROM users");
                            $hasRows = false;
                            while ($user = mysqli_fetch_assoc($result)):
                                $hasRows = true;
                                $uidDisplay = '—';
                                $userRowId = (int) ($user['id'] ?? 0);
                                if (isset($rfidUidByUserId[$userRowId]) && !empty($rfidUidByUserId[$userRowId])) {
                                    $uidValues = array_keys($rfidUidByUserId[$userRowId]);
                                    sort($uidValues, SORT_NATURAL | SORT_FLAG_CASE);
                                    $uidDisplay = implode(', ', $uidValues);
                                }
                                $canEdit = $canDelete = false;
                                if ($current_role === 'superadmin') {
                                    $canEdit = $canDelete = true;
                                } elseif ($current_role === 'admin' && in_array($user['role'], ['student', 'faculty', 'assistant', 'librarian'])) {
                                    $canEdit = $canDelete = true;
                                }
                            ?>
                            <tr>
                                <td style="color:var(--text-muted); font-size:0.80rem;">#<?= $user['id'] ?></td>
                                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($user['username']) ?></td>
                                <td style="color:var(--text-sub); font-size:0.85rem; max-width: 260px; overflow-wrap:anywhere;">
                                    <?= htmlspecialchars($user['email'] ?? '') ?>
                                </td>
                                <td>
                                    <span class="badge <?= in_array($user['role'],['admin','superadmin']) ? 'reserved' : 'available' ?>">
                                        <?= ucfirst($user['role']) ?>
                                    </span>
                                </td>
                                <td style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, monospace; font-size:0.80rem; color:var(--text-sub);">
                                    <?= htmlspecialchars($uidDisplay) ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <?php if ($canEdit): ?>
                                            <a href="edit_user.php?id=<?= $user['id'] ?>" class="btn-action edit">✏️ Edit</a>
                                        <?php endif; ?>
                                        <?php if ($canDelete): ?>
                                            <a href="delete_user.php?id=<?= $user['id'] ?>" class="btn-action delete"
                                               onclick="return confirm('Delete this user?');">🗑 Delete</a>
                                        <?php endif; ?>
                                        <?php if (!$canEdit && !$canDelete): ?>
                                            <span class="dash-cell">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if (!$hasRows): ?>
                            <tr class="empty-row"><td colspan="6">No users found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ══════ SEATS SECTION ══════ -->
        <section id="seats" class="section <?= $initialTab === 'seats' ? 'active' : '' ?>">
            <div class="section-title">Seat Allocation</div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table id="seatAllocationTable">
                        <thead>
                            <tr>
                                <th>Seat Number</th>
                                <th>Status</th>
                                <th>Allocated By</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="seatAllocationBody">
                            <?php
                            $hasRows = false;
                            while ($row = mysqli_fetch_assoc($seats_query)):
                                $hasRows = true;
                            ?>
                            <tr class="seat-row">
                                <td style="font-weight:600; color:var(--text-main);">
                                    🪑 <?= htmlspecialchars($row['seat_number']) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $row['status'] === 'reserved' ? 'reserved' : 'available' ?>">
                                        <?= ucfirst($row['status']) ?>
                                    </span>
                                </td>
                                <td><?= $row['username'] ? htmlspecialchars($row['username']) : '<span class="dash-cell">—</span>' ?></td>
                                <td>
                                    <?php if ($row['status'] === 'reserved'): ?>
                                        <a href="free_item_admin.php?type=seat&id=<?= (int)$row['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>"
                                           class="btn-action free">🔓 Remove</a>
                                    <?php else: ?>
                                        <span class="dash-cell">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if (!$hasRows): ?>
                            <tr class="empty-row"><td colspan="4">No seat records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-pagination" id="seatPagination" hidden>
                    <div class="pagination-meta" id="seatPaginationMeta"></div>
                    <div class="pagination-controls">
                        <button type="button" class="page-btn" id="seatPrevPage" aria-label="Previous seat page">Prev</button>
                        <div class="pagination-pages" id="seatPaginationPages" aria-label="Seat page list"></div>
                        <button type="button" class="page-btn" id="seatNextPage" aria-label="Next seat page">Next</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ══════ COMPUTERS SECTION ══════ -->
        <section id="computers" class="section <?= $initialTab === 'computers' ? 'active' : '' ?>">
            <div class="section-title">Computer Allocation</div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Computer</th>
                                <th>Status</th>
                                <th>Allocated By</th>
                                <th>Allocated At</th>
                                <th>Expires At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $hasRows = false;
                            while ($row = mysqli_fetch_assoc($computers_query)):
                                $hasRows = true;
                            ?>
                            <tr>
                                <td style="font-weight:600; color:var(--text-main);">
                                    🖥️ <?= htmlspecialchars($row['computer_number']) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $row['status'] === 'reserved' ? 'reserved' : 'available' ?>">
                                        <?= ucfirst($row['status']) ?>
                                    </span>
                                </td>
                                <td><?= $row['username'] ? htmlspecialchars($row['username']) : '<span class="dash-cell">—</span>' ?></td>
                                <td style="font-size:0.80rem; color:var(--text-muted); white-space:nowrap;">
                                    <?= $row['reserved_at'] ? date("M j, Y H:i", strtotime($row['reserved_at'])) : '<span class="dash-cell">—</span>' ?>
                                </td>
                                <td style="font-size:0.80rem; color:var(--text-muted); white-space:nowrap;">
                                    <?= $row['expires_at']  ? date("M j, Y H:i", strtotime($row['expires_at']))  : '<span class="dash-cell">—</span>' ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'reserved'): ?>
                                        <a href="free_item_admin.php?type=computer&id=<?= (int)$row['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>"
                                           class="btn-action free">🔓 Remove</a>
                                    <?php else: ?>
                                        <span class="dash-cell">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if (!$hasRows): ?>
                            <tr class="empty-row"><td colspan="6">No computer records found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ══════ RFID PORTAL SECTION ══════ -->
        <section id="rfid-portal" class="section <?= $initialTab === 'rfid-portal' ? 'active' : '' ?>">
            <div class="section-title">RFID Device Portal</div>

            <div class="welcome-box" style="margin-bottom:14px;">
                This section embeds the ESP32 portal inside the admin dashboard.
                Use it to run enrollment actions and device maintenance without leaving the panel.
                <br>
                Device URL:
                <a href="<?= htmlspecialchars($rfidPortalUrl) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--gold-light);">
                    <?= htmlspecialchars($rfidPortalUrl) ?>
                </a>
            </div>

            <div class="rfid-actions-grid">
                <button type="button" class="rfid-btn" id="btnRefreshRfidFrame">↻ Refresh Embedded Portal</button>
                <a class="rfid-btn" href="<?= htmlspecialchars($rfidPortalUrl) ?>" target="_blank" rel="noopener noreferrer">↗ Open Portal in New Tab</a>
                <button type="button" class="rfid-btn warn" id="btnCheckRfidPortal">📶 Check Device Connection</button>
                <button type="button" class="rfid-btn danger" id="btnClearOfflineQueue">🧹 Clear Offline Queue</button>
            </div>

            <div class="rfid-inline-panel">
                <label for="rfidResetUid">Reset offline one-time-in/out test state</label>
                <input type="text" id="rfidResetUid" placeholder="Optional UID (AA:BB:CC:DD). Leave blank to reset all sessions.">
                <button type="button" class="rfid-btn warn" id="btnResetOfflineSessions">🔁 Reset Offline Sessions</button>
                <div class="rfid-note">
                    Queue reset uses <strong>/queue/clear</strong>. Session reset uses <strong>/sessions/reset</strong>.
                    If your firmware does not yet support <strong>/sessions/reset</strong>, restart the ESP32 to clear in-memory card sessions.
                </div>
            </div>

            <div id="rfidOpsMessage" class="rfid-ops-message"></div>

            <div class="table-wrap rfid-frame-wrap">
                <iframe
                    id="rfidPortalFrame"
                    class="rfid-frame"
                    src="<?= htmlspecialchars($rfidPortalUrl) ?>"
                    data-src="<?= htmlspecialchars($rfidPortalUrl) ?>"
                    title="RFID Device Portal"
                    loading="lazy"
                ></iframe>
            </div>
        </section>

        <!-- ══════ LOGS SECTION ══════ -->
        <section id="logs" class="section <?= $initialTab === 'logs' ? 'active' : '' ?>">
            <div class="section-title">Login &amp; Attendance Logs</div>

            <div class="welcome-box" style="margin-bottom:14px;">
                Review RFID attendance activity and web login/logout history, including failed login attempts.
            </div>

            <div class="rfid-actions-grid no-print" style="margin-bottom:14px;">
                <a class="rfid-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'export' => 'attendance-events', 'attPage' => null])) ?>">⬇️ Export Attendance Events CSV</a>
                <a class="rfid-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'export' => 'attendance-sessions', 'attSessionPage' => null])) ?>">⬇️ Export Attendance Sessions CSV</a>
                <a class="rfid-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'export' => 'auth-events', 'authPage' => null])) ?>">⬇️ Export Login Events CSV</a>
                <a class="rfid-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'export' => 'auth-sessions', 'authSessionPage' => null])) ?>">⬇️ Export Login Sessions CSV</a>
                <button type="button" class="rfid-btn warn" id="btnPrintLogs">🖨️ Print Logs</button>
            </div>

            <div class="section-subtitle">RFID Attendance Events</div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Action</th>
                                <th>User</th>
                                <th>UID</th>
                                <th>Device</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$attendanceLogAvailable): ?>
                                <tr class="empty-row"><td colspan="5">Attendance log table not available.</td></tr>
                            <?php elseif (empty($attendanceEvents)): ?>
                                <tr class="empty-row"><td colspan="5">No attendance events found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($attendanceEvents as $row): ?>
                                    <?php
                                    $userLabel = '—';
                                    $userMeta = '';
                                    $rowUserId = (int) ($row['user_id'] ?? 0);
                                    if (!empty($row['username'])) {
                                        $userLabel = (string) $row['username'];
                                    } elseif (!empty($row['name'])) {
                                        $userLabel = (string) $row['name'];
                                    } elseif ($rowUserId > 0) {
                                        $userLabel = 'User #' . $rowUserId;
                                    }
                                    if (!empty($row['role'])) {
                                        $userMeta = ucfirst((string) $row['role']);
                                    }
                                    $action = strtoupper((string) ($row['action'] ?? ''));
                                    $actionClass = $action === 'TIME_IN' ? 'success' : 'warn';
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars(adminFormatDateTime($row['date'] ?? '', $row['time'] ?? '')) ?></td>
                                        <td><span class="badge <?= $actionClass ?>"><?= htmlspecialchars($action) ?></span></td>
                                        <td>
                                            <span style="font-weight:600; color:var(--text-main);">
                                                <?= htmlspecialchars($userLabel) ?>
                                            </span>
                                            <?php if ($userMeta !== ''): ?>
                                                <span class="cell-sub"><?= htmlspecialchars($userMeta) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, monospace; font-size:0.80rem; color:var(--text-sub);">
                                            <?= htmlspecialchars((string) ($row['uid'] ?? '—')) ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($row['device'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($attendanceLogAvailable && $attendanceEventsTotalPages > 1): ?>
                    <div class="table-pagination">
                        <div class="pagination-meta">
                            Showing <?= $attendanceEventsStart ?>-<?= $attendanceEventsEnd ?> of <?= $attendanceEventsTotal ?> events
                        </div>
                        <div class="pagination-controls">
                            <?php if ($attendanceEventsPage > 1): ?>
                                <a class="page-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'attPage' => $attendanceEventsPage - 1])) ?>">Prev</a>
                            <?php else: ?>
                                <button type="button" class="page-btn" disabled>Prev</button>
                            <?php endif; ?>
                            <div class="pagination-pages" aria-label="Attendance event page list">
                                <?php foreach (adminPaginationTokens($attendanceEventsTotalPages, $attendanceEventsPage) as $token): ?>
                                    <?php if (!is_int($token)): ?>
                                        <span class="page-ellipsis">…</span>
                                    <?php elseif ($token === $attendanceEventsPage): ?>
                                        <button type="button" class="page-number" aria-current="page" disabled><?= $token ?></button>
                                    <?php else: ?>
                                        <a class="page-number" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'attPage' => $token])) ?>"><?= $token ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($attendanceEventsPage < $attendanceEventsTotalPages): ?>
                                <a class="page-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'attPage' => $attendanceEventsPage + 1])) ?>">Next</a>
                            <?php else: ?>
                                <button type="button" class="page-btn" disabled>Next</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="section-subtitle">RFID Attendance Sessions</div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Duration</th>
                                <th>User</th>
                                <th>UID</th>
                                <th>Device</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$attendanceLogAvailable): ?>
                                <tr class="empty-row"><td colspan="6">Attendance log table not available.</td></tr>
                            <?php elseif (empty($attendanceSessions)): ?>
                                <tr class="empty-row"><td colspan="6">No attendance sessions found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($attendanceSessions as $row): ?>
                                    <?php
                                    $userLabel = '—';
                                    $userMeta = '';
                                    $rowUserId = (int) ($row['user_id'] ?? 0);
                                    if (!empty($row['username'])) {
                                        $userLabel = (string) $row['username'];
                                    } elseif (!empty($row['name'])) {
                                        $userLabel = (string) $row['name'];
                                    } elseif ($rowUserId > 0) {
                                        $userLabel = 'User #' . $rowUserId;
                                    }
                                    if (!empty($row['role'])) {
                                        $userMeta = ucfirst((string) $row['role']);
                                    }
                                    $deviceLabel = '—';
                                    $inDevice = (string) ($row['time_in_device'] ?? '');
                                    $outDevice = (string) ($row['time_out_device'] ?? '');
                                    if ($inDevice !== '' || $outDevice !== '') {
                                        if ($inDevice !== '' && $outDevice !== '' && $outDevice !== $inDevice) {
                                            $deviceLabel = $inDevice . ' / ' . $outDevice;
                                        } else {
                                            $deviceLabel = $inDevice !== '' ? $inDevice : $outDevice;
                                        }
                                    }
                                    $timeOutDisplay = adminFormatDateTime($row['time_out_date'] ?? '', $row['time_out_time'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars(adminFormatDateTime($row['time_in_date'] ?? '', $row['time_in_time'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($timeOutDisplay) ?></td>
                                        <td>
                                            <?php if (($row['duration_label'] ?? '') === 'Active'): ?>
                                                <span class="badge info">Active</span>
                                            <?php else: ?>
                                                <?= htmlspecialchars((string) ($row['duration_label'] ?? '—')) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight:600; color:var(--text-main);">
                                                <?= htmlspecialchars($userLabel) ?>
                                            </span>
                                            <?php if ($userMeta !== ''): ?>
                                                <span class="cell-sub"><?= htmlspecialchars($userMeta) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, monospace; font-size:0.80rem; color:var(--text-sub);">
                                            <?= htmlspecialchars((string) ($row['uid'] ?? '—')) ?>
                                        </td>
                                        <td><?= htmlspecialchars($deviceLabel) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($attendanceLogAvailable && $attendanceSessionsTotalPages > 1): ?>
                    <div class="table-pagination">
                        <div class="pagination-meta">
                            Showing <?= $attendanceSessionsStart ?>-<?= $attendanceSessionsEnd ?> of <?= $attendanceSessionsTotal ?> sessions
                        </div>
                        <div class="pagination-controls">
                            <?php if ($attendanceSessionsPage > 1): ?>
                                <a class="page-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'attSessionPage' => $attendanceSessionsPage - 1])) ?>">Prev</a>
                            <?php else: ?>
                                <button type="button" class="page-btn" disabled>Prev</button>
                            <?php endif; ?>
                            <div class="pagination-pages" aria-label="Attendance session page list">
                                <?php foreach (adminPaginationTokens($attendanceSessionsTotalPages, $attendanceSessionsPage) as $token): ?>
                                    <?php if (!is_int($token)): ?>
                                        <span class="page-ellipsis">…</span>
                                    <?php elseif ($token === $attendanceSessionsPage): ?>
                                        <button type="button" class="page-number" aria-current="page" disabled><?= $token ?></button>
                                    <?php else: ?>
                                        <a class="page-number" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'attSessionPage' => $token])) ?>"><?= $token ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($attendanceSessionsPage < $attendanceSessionsTotalPages): ?>
                                <a class="page-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'attSessionPage' => $attendanceSessionsPage + 1])) ?>">Next</a>
                            <?php else: ?>
                                <button type="button" class="page-btn" disabled>Next</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="section-subtitle">Web Login Events</div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Action</th>
                                <th>User / Identity</th>
                                <th>Role</th>
                                <th>IP Address</th>
                                <th>Session</th>
                                <th>User Agent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$authLogAvailable): ?>
                                <tr class="empty-row"><td colspan="7">Auth log table not available.</td></tr>
                            <?php elseif (empty($authEvents)): ?>
                                <tr class="empty-row"><td colspan="7">No login events found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($authEvents as $row): ?>
                                    <?php
                                    $action = (string) ($row['action'] ?? '');
                                    $actionLabel = $action;
                                    $actionClass = 'info';
                                    if ($action === 'login_success') {
                                        $actionLabel = 'Login';
                                        $actionClass = 'success';
                                    } elseif ($action === 'login_failed') {
                                        $actionLabel = 'Login Failed';
                                        $actionClass = 'danger';
                                    } elseif ($action === 'logout') {
                                        $actionLabel = 'Logout';
                                        $actionClass = 'warn';
                                    }
                                    $identity = (string) ($row['identity'] ?? '');
                                    $username = (string) ($row['username'] ?? '');
                                    $userLabel = $username !== '' ? $username : ($identity !== '' ? $identity : '—');
                                    $userMeta = ($username !== '' && $identity !== '' && $identity !== $username) ? ('ID: ' . $identity) : '';
                                    $sessionFull = (string) ($row['session_id'] ?? '');
                                    $sessionShort = $sessionFull !== '' ? adminTruncate($sessionFull, 12) : '—';
                                    $agentFull = (string) ($row['user_agent'] ?? '');
                                    $agentShort = $agentFull !== '' ? adminTruncate($agentFull, 48) : '—';
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars(adminFormatTimestamp($row['created_at'] ?? '')) ?></td>
                                        <td><span class="badge <?= $actionClass ?>"><?= htmlspecialchars($actionLabel) ?></span></td>
                                        <td>
                                            <span style="font-weight:600; color:var(--text-main);">
                                                <?= htmlspecialchars($userLabel) ?>
                                            </span>
                                            <?php if ($userMeta !== ''): ?>
                                                <span class="cell-sub"><?= htmlspecialchars($userMeta) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['role'] !== null && $row['role'] !== '' ? ucfirst((string) $row['role']) : '—') ?></td>
                                        <td><?= htmlspecialchars((string) ($row['ip_address'] ?? '—')) ?></td>
                                        <td title="<?= htmlspecialchars($sessionFull) ?>" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, monospace; font-size:0.80rem; color:var(--text-sub);">
                                            <?= htmlspecialchars($sessionShort) ?>
                                        </td>
                                        <td title="<?= htmlspecialchars($agentFull) ?>">
                                            <?= htmlspecialchars($agentShort) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($authLogAvailable && $authEventsTotalPages > 1): ?>
                    <div class="table-pagination">
                        <div class="pagination-meta">
                            Showing <?= $authEventsStart ?>-<?= $authEventsEnd ?> of <?= $authEventsTotal ?> events
                        </div>
                        <div class="pagination-controls">
                            <?php if ($authEventsPage > 1): ?>
                                <a class="page-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'authPage' => $authEventsPage - 1])) ?>">Prev</a>
                            <?php else: ?>
                                <button type="button" class="page-btn" disabled>Prev</button>
                            <?php endif; ?>
                            <div class="pagination-pages" aria-label="Auth event page list">
                                <?php foreach (adminPaginationTokens($authEventsTotalPages, $authEventsPage) as $token): ?>
                                    <?php if (!is_int($token)): ?>
                                        <span class="page-ellipsis">…</span>
                                    <?php elseif ($token === $authEventsPage): ?>
                                        <button type="button" class="page-number" aria-current="page" disabled><?= $token ?></button>
                                    <?php else: ?>
                                        <a class="page-number" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'authPage' => $token])) ?>"><?= $token ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($authEventsPage < $authEventsTotalPages): ?>
                                <a class="page-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'authPage' => $authEventsPage + 1])) ?>">Next</a>
                            <?php else: ?>
                                <button type="button" class="page-btn" disabled>Next</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="section-subtitle">Web Login Sessions</div>

            <div class="table-wrap">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Login</th>
                                <th>Logout</th>
                                <th>Duration</th>
                                <th>User / Identity</th>
                                <th>IP Address</th>
                                <th>Session</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$authLogAvailable): ?>
                                <tr class="empty-row"><td colspan="6">Auth log table not available.</td></tr>
                            <?php elseif (empty($authSessions)): ?>
                                <tr class="empty-row"><td colspan="6">No login sessions found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($authSessions as $row): ?>
                                    <?php
                                    $identity = (string) ($row['identity'] ?? '');
                                    $username = (string) ($row['username'] ?? '');
                                    $userLabel = $username !== '' ? $username : ($identity !== '' ? $identity : '—');
                                    $userMeta = ($username !== '' && $identity !== '' && $identity !== $username) ? ('ID: ' . $identity) : '';
                                    $sessionFull = (string) ($row['session_id'] ?? '');
                                    $sessionShort = $sessionFull !== '' ? adminTruncate($sessionFull, 12) : '—';
                                    $logoutLabel = adminFormatTimestamp($row['logout_at'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars(adminFormatTimestamp($row['login_at'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($logoutLabel) ?></td>
                                        <td>
                                            <?php if (($row['duration_label'] ?? '') === 'Active'): ?>
                                                <span class="badge info">Active</span>
                                            <?php else: ?>
                                                <?= htmlspecialchars((string) ($row['duration_label'] ?? '—')) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight:600; color:var(--text-main);">
                                                <?= htmlspecialchars($userLabel) ?>
                                            </span>
                                            <?php if ($userMeta !== ''): ?>
                                                <span class="cell-sub"><?= htmlspecialchars($userMeta) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($row['ip_address'] ?? '—')) ?></td>
                                        <td title="<?= htmlspecialchars($sessionFull) ?>" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, monospace; font-size:0.80rem; color:var(--text-sub);">
                                            <?= htmlspecialchars($sessionShort) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($authLogAvailable && $authSessionsTotalPages > 1): ?>
                    <div class="table-pagination">
                        <div class="pagination-meta">
                            Showing <?= $authSessionsStart ?>-<?= $authSessionsEnd ?> of <?= $authSessionsTotal ?> sessions
                        </div>
                        <div class="pagination-controls">
                            <?php if ($authSessionsPage > 1): ?>
                                <a class="page-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'authSessionPage' => $authSessionsPage - 1])) ?>">Prev</a>
                            <?php else: ?>
                                <button type="button" class="page-btn" disabled>Prev</button>
                            <?php endif; ?>
                            <div class="pagination-pages" aria-label="Auth session page list">
                                <?php foreach (adminPaginationTokens($authSessionsTotalPages, $authSessionsPage) as $token): ?>
                                    <?php if (!is_int($token)): ?>
                                        <span class="page-ellipsis">…</span>
                                    <?php elseif ($token === $authSessionsPage): ?>
                                        <button type="button" class="page-number" aria-current="page" disabled><?= $token ?></button>
                                    <?php else: ?>
                                        <a class="page-number" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'authSessionPage' => $token])) ?>"><?= $token ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($authSessionsPage < $authSessionsTotalPages): ?>
                                <a class="page-btn" href="?<?= htmlspecialchars(adminBuildQuery(['tab' => 'logs', 'authSessionPage' => $authSessionsPage + 1])) ?>">Next</a>
                            <?php else: ?>
                                <button type="button" class="page-btn" disabled>Next</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($isSuperadmin): ?>
        <section id="system-hours" class="section <?= $initialTab === 'system-hours' ? 'active' : '' ?>">
            <div class="section-title">System Operating Hours</div>

            <div class="hours-grid">
                <div class="hours-card">
                    <div class="hours-label">Current Status</div>
                    <div class="hours-value">
                        <span class="badge <?= $hoursStatusClass ?>"><?= htmlspecialchars($hoursStatusText) ?></span>
                    </div>
                </div>
                <div class="hours-card">
                    <div class="hours-label">Schedule</div>
                    <div class="hours-value"><?= htmlspecialchars(libraryHoursFormatTime($hoursOpenTime)) ?> - <?= htmlspecialchars(libraryHoursFormatTime($hoursCloseTime)) ?></div>
                </div>
                <div class="hours-card">
                    <div class="hours-label">Next Opening</div>
                    <div class="hours-value" style="font-size:1.15rem;"><?= htmlspecialchars($hoursNextOpenDisplay) ?></div>
                </div>
            </div>

            <div class="welcome-box" style="margin-bottom:14px;">
                Sunday is permanently closed. Only superadmin can edit opening and closing times.
                <br>
                <?= htmlspecialchars((string) ($hoursNotice['hours_line'] ?? '')) ?>
            </div>

            <form method="POST" class="hours-form" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION['csrf_token']) ?>">
                <input type="hidden" name="save_system_hours" value="1">

                <div class="hours-input-row">
                    <div class="hours-input-group">
                        <label for="open_time">Opening Time (Monday-Saturday)</label>
                        <input id="open_time" name="open_time" type="time" step="60" value="<?= htmlspecialchars($hoursOpenInput) ?>" required>
                    </div>
                    <div class="hours-input-group">
                        <label for="close_time">Closing Time (Monday-Saturday)</label>
                        <input id="close_time" name="close_time" type="time" step="60" value="<?= htmlspecialchars($hoursCloseInput) ?>" required>
                    </div>
                </div>

                <div class="hours-help">
                    Changes apply globally to admin, librarian, assistant, faculty, and student access. Superadmin remains exempt from closed-hour restrictions.
                </div>

                <button type="submit" class="hours-submit">Save Operating Hours</button>
            </form>
        </section>
        <?php endif; ?>

    </div><!-- /main-content -->
</div><!-- /flex col -->
</div><!-- /wrapper -->

<script>
'use strict';

/* ── Tab switching ── */
const buttons  = document.querySelectorAll('.tab-button');
const sections = document.querySelectorAll('.section');

buttons.forEach(btn => {
    btn.addEventListener('click', () => {
        buttons.forEach(b  => b.classList.remove('active'));
        sections.forEach(s => s.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
        const nextUrl = window.location.pathname + '?tab=' + encodeURIComponent(btn.dataset.tab);
        window.history.replaceState(null, '', nextUrl);
        closeSidebar();
    });
});

/* ── Seat pagination ── */
const seatPagination = document.getElementById('seatPagination');
const seatPaginationMeta = document.getElementById('seatPaginationMeta');
const seatPaginationPages = document.getElementById('seatPaginationPages');
const seatPrevPage = document.getElementById('seatPrevPage');
const seatNextPage = document.getElementById('seatNextPage');
const seatRows = Array.from(document.querySelectorAll('#seatAllocationBody .seat-row'));

const SEAT_ROWS_PER_PAGE = 10;
let seatCurrentPage = 1;
const seatTotalPages = Math.max(1, Math.ceil(seatRows.length / SEAT_ROWS_PER_PAGE));

function clampSeatPage(page) {
    return Math.max(1, Math.min(seatTotalPages, page));
}

function seatPageTokens(totalPages, currentPage) {
    if (totalPages <= 7) {
        return Array.from({ length: totalPages }, (_, idx) => idx + 1);
    }

    const tokens = [1];
    const start = Math.max(2, currentPage - 1);
    const end = Math.min(totalPages - 1, currentPage + 1);

    if (start > 2) {
        tokens.push('ellipsis-left');
    }

    for (let page = start; page <= end; page += 1) {
        tokens.push(page);
    }

    if (end < totalPages - 1) {
        tokens.push('ellipsis-right');
    }

    tokens.push(totalPages);
    return tokens;
}

function syncSeatPageUrl() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') !== 'seats') {
        return;
    }

    if (seatCurrentPage > 1) {
        params.set('seatPage', String(seatCurrentPage));
    } else {
        params.delete('seatPage');
    }

    const query = params.toString();
    const nextUrl = window.location.pathname + (query ? ('?' + query) : '');
    window.history.replaceState(null, '', nextUrl);
}

function renderSeatPaginationPages() {
    if (!seatPaginationPages) return;

    seatPaginationPages.innerHTML = '';
    seatPageTokens(seatTotalPages, seatCurrentPage).forEach(token => {
        if (typeof token !== 'number') {
            const dots = document.createElement('span');
            dots.className = 'page-ellipsis';
            dots.textContent = '…';
            dots.setAttribute('aria-hidden', 'true');
            seatPaginationPages.appendChild(dots);
            return;
        }

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'page-number' + (token === seatCurrentPage ? ' active' : '');
        btn.textContent = String(token);
        btn.setAttribute('aria-label', 'Go to seat page ' + token);

        if (token === seatCurrentPage) {
            btn.setAttribute('aria-current', 'page');
            btn.disabled = true;
        } else {
            btn.addEventListener('click', () => setSeatPage(token));
        }

        seatPaginationPages.appendChild(btn);
    });
}

function setSeatPage(page) {
    seatCurrentPage = clampSeatPage(page);

    const startIndex = (seatCurrentPage - 1) * SEAT_ROWS_PER_PAGE;
    const endIndex = startIndex + SEAT_ROWS_PER_PAGE;

    seatRows.forEach((row, idx) => {
        row.hidden = !(idx >= startIndex && idx < endIndex);
    });

    const firstLabel = seatRows.length === 0 ? 0 : (startIndex + 1);
    const lastLabel = Math.min(endIndex, seatRows.length);

    if (seatPaginationMeta) {
        seatPaginationMeta.textContent = 'Showing ' + firstLabel + '-' + lastLabel + ' of ' + seatRows.length + ' seats';
    }
    if (seatPrevPage) seatPrevPage.disabled = seatCurrentPage <= 1;
    if (seatNextPage) seatNextPage.disabled = seatCurrentPage >= seatTotalPages;

    renderSeatPaginationPages();
    syncSeatPageUrl();
}

function initSeatPagination() {
    if (!seatPagination || seatRows.length === 0) {
        return;
    }

    if (seatRows.length <= SEAT_ROWS_PER_PAGE) {
        seatPagination.hidden = true;
        return;
    }

    const params = new URLSearchParams(window.location.search);
    const parsedPage = parseInt(params.get('seatPage') || '1', 10);
    seatCurrentPage = Number.isFinite(parsedPage) ? clampSeatPage(parsedPage) : 1;

    seatPagination.hidden = false;
    if (seatPrevPage) {
        seatPrevPage.addEventListener('click', () => setSeatPage(seatCurrentPage - 1));
    }
    if (seatNextPage) {
        seatNextPage.addEventListener('click', () => setSeatPage(seatCurrentPage + 1));
    }

    setSeatPage(seatCurrentPage);
}

initSeatPagination();

const RFID_PROXY_URL = 'rfid_portal_proxy.php';
const CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>';

const rfidPortalFrame = document.getElementById('rfidPortalFrame');
const rfidOpsMessage = document.getElementById('rfidOpsMessage');
const btnRefreshRfidFrame = document.getElementById('btnRefreshRfidFrame');
const btnCheckRfidPortal = document.getElementById('btnCheckRfidPortal');
const btnClearOfflineQueue = document.getElementById('btnClearOfflineQueue');
const btnResetOfflineSessions = document.getElementById('btnResetOfflineSessions');
const rfidResetUid = document.getElementById('rfidResetUid');
const btnPrintLogs = document.getElementById('btnPrintLogs');

function showRfidMessage(type, text) {
    if (!rfidOpsMessage) return;
    rfidOpsMessage.className = 'rfid-ops-message ' + type;
    rfidOpsMessage.textContent = text;
}

function normalizeUid(rawUid) {
    return String(rawUid || '').trim().toUpperCase();
}

async function postRfidProxy(action, payload = {}) {
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('csrf', CSRF_TOKEN);
    Object.entries(payload).forEach(([k, v]) => {
        body.set(k, String(v));
    });

    const res = await fetch(RFID_PROXY_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body.toString(),
    });

    let data = null;
    try {
        data = await res.json();
    } catch (e) {
        throw new Error('RFID proxy returned an invalid response.');
    }

    if (!res.ok || !data || data.success !== true) {
        throw new Error((data && data.message) ? data.message : 'RFID action failed.');
    }

    return data;
}

if (btnRefreshRfidFrame && rfidPortalFrame) {
    btnRefreshRfidFrame.addEventListener('click', () => {
        const src = rfidPortalFrame.dataset.src || rfidPortalFrame.src;
        rfidPortalFrame.src = src + (src.includes('?') ? '&' : '?') + 't=' + Date.now();
        showRfidMessage('ok', 'Embedded RFID portal refreshed.');
    });
}

if (btnCheckRfidPortal) {
    btnCheckRfidPortal.addEventListener('click', async () => {
        try {
            const res = await postRfidProxy('ping');
            showRfidMessage('ok', res.message || 'RFID device is reachable.');
        } catch (err) {
            showRfidMessage('err', err.message || 'Unable to reach RFID device.');
        }
    });
}

if (btnClearOfflineQueue) {
    btnClearOfflineQueue.addEventListener('click', async () => {
        if (!confirm('Clear all offline queued taps on the RFID device?')) return;
        try {
            const res = await postRfidProxy('queue_clear');
            showRfidMessage('ok', res.message || 'Offline queue cleared.');
        } catch (err) {
            showRfidMessage('err', err.message || 'Failed to clear offline queue.');
        }
    });
}

if (btnResetOfflineSessions) {
    btnResetOfflineSessions.addEventListener('click', async () => {
        const uid = normalizeUid(rfidResetUid ? rfidResetUid.value : '');
        const ask = uid !== ''
            ? ('Reset offline session for UID ' + uid + '?')
            : 'Reset all offline sessions on the RFID device?';
        if (!confirm(ask)) return;

        try {
            const payload = {};
            if (uid !== '') payload.uid = uid;
            const res = await postRfidProxy('reset_sessions', payload);
            showRfidMessage('ok', res.message || 'Offline sessions reset.');
        } catch (err) {
            showRfidMessage('warn', err.message || 'Session reset is unavailable on this firmware.');
        }
    });
}

if (btnPrintLogs) {
    btnPrintLogs.addEventListener('click', () => window.print());
}

/* ── Mobile sidebar ── */
const sidebar       = document.getElementById('sidebar');
const drawerOverlay = document.getElementById('drawerOverlay');
const hamburger     = document.getElementById('hamburger');

function openSidebar()  { sidebar.classList.add('open'); drawerOverlay.classList.add('open'); }
function closeSidebar() { sidebar.classList.remove('open'); drawerOverlay.classList.remove('open'); }

hamburger.addEventListener('click', openSidebar);
drawerOverlay.addEventListener('click', closeSidebar);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
</script>

</body>
</html>