<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/auth.php';
requireLogin();
requireRole('admin', 'superadmin');
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

function rfidProxyRespond(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rfidProxyPortalBaseUrl(): string
{
    $portalUrl = getenv('RFID_PORTAL_URL');
    if ($portalUrl === false || trim($portalUrl) === '') {
        $portalUrl = 'http://192.168.0.100/';
    }

    $portalUrl = rtrim((string) $portalUrl, '/') . '/';
    if (!filter_var($portalUrl, FILTER_VALIDATE_URL)) {
        return 'http://192.168.0.100/';
    }

    return $portalUrl;
}

function rfidProxyRequest(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Unable to initialize cURL.'];
        }

        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $requestHeaders,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $respBody = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($respBody === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err !== '' ? $err : 'Unknown request error.'];
        }

        return ['ok' => true, 'status' => $status, 'body' => (string) $respBody, 'error' => ''];
    }

    $headerLines = '';
    foreach ($headers as $name => $value) {
        $headerLines .= $name . ': ' . $value . "\r\n";
    }

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => $headerLines,
            'content' => $body ?? '',
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ];

    $context = stream_context_create($options);
    $respBody = @file_get_contents($url, false, $context);
    if ($respBody === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Unable to connect to RFID portal.'];
    }

    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $line, $m)) {
                $status = (int) $m[1];
                break;
            }
        }
    }

    return ['ok' => true, 'status' => $status, 'body' => (string) $respBody, 'error' => ''];
}

$currentRole = strtolower((string) ($_SESSION['role'] ?? ''));
if ($currentRole === 'admin') {
    $hoursState = libraryHoursEvaluate($conn);
    if (!$hoursState['is_open']) {
        $closed = operatingHoursClosedApiPayload(
            $conn,
            'RFID portal actions are unavailable while the library is closed.',
            $hoursState
        );

        rfidProxyRespond(200, [
            'success' => false,
            'status' => 'closed',
            'message' => $closed['msg'],
            'reopens_at' => $closed['reopens_at'],
            'hours' => $closed['hours'],
        ]);
    }
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    rfidProxyRespond(405, ['success' => false, 'message' => 'POST only.']);
}

$csrf = (string) ($_POST['csrf'] ?? '');
if (!isset($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
    rfidProxyRespond(403, ['success' => false, 'message' => 'Invalid CSRF token.']);
}

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
if ($action === '') {
    rfidProxyRespond(400, ['success' => false, 'message' => 'Missing action.']);
}

$base = rfidProxyPortalBaseUrl();

if ($action === 'ping') {
    $res = rfidProxyRequest($base, 'GET');
    if (!$res['ok']) {
        rfidProxyRespond(502, ['success' => false, 'message' => 'RFID portal is unreachable: ' . $res['error']]);
    }
    if ($res['status'] < 200 || $res['status'] >= 400) {
        rfidProxyRespond(502, ['success' => false, 'message' => 'RFID portal responded with HTTP ' . $res['status'] . '.']);
    }

    rfidProxyRespond(200, [
        'success' => true,
        'message' => 'RFID portal is reachable at ' . $base,
    ]);
}

if ($action === 'queue_clear') {
    $res = rfidProxyRequest($base . 'queue/clear', 'POST', '');
    if (!$res['ok']) {
        rfidProxyRespond(502, ['success' => false, 'message' => 'Queue clear failed: ' . $res['error']]);
    }
    if ($res['status'] < 200 || $res['status'] >= 400) {
        rfidProxyRespond(502, ['success' => false, 'message' => 'Queue clear endpoint returned HTTP ' . $res['status'] . '.']);
    }

    $text = trim($res['body']);
    rfidProxyRespond(200, [
        'success' => true,
        'message' => $text !== '' ? $text : 'Offline queue cleared on RFID device.',
    ]);
}

if ($action === 'reset_sessions') {
    $uid = strtoupper(trim((string) ($_POST['uid'] ?? '')));
    if ($uid !== '' && !preg_match('/^([0-9A-F]{2}:){3,6}[0-9A-F]{2}$/', $uid)) {
        rfidProxyRespond(400, ['success' => false, 'message' => 'Invalid UID format. Use AA:BB:CC:DD pattern.']);
    }

    $url = $base . 'sessions/reset';
    if ($uid !== '') {
        $url .= '?uid=' . rawurlencode($uid);
    }

    $res = rfidProxyRequest($url, 'POST', '');
    if (!$res['ok']) {
        rfidProxyRespond(502, ['success' => false, 'message' => 'Session reset failed: ' . $res['error']]);
    }

    if ($res['status'] === 404) {
        rfidProxyRespond(400, [
            'success' => false,
            'message' => 'This firmware does not expose /sessions/reset yet. Restart ESP32 to clear in-memory sessions.',
        ]);
    }

    if ($res['status'] < 200 || $res['status'] >= 400) {
        rfidProxyRespond(502, ['success' => false, 'message' => 'Session reset endpoint returned HTTP ' . $res['status'] . '.']);
    }

    $text = trim($res['body']);
    rfidProxyRespond(200, [
        'success' => true,
        'message' => $text !== '' ? $text : 'Offline session memory reset completed.',
    ]);
}

rfidProxyRespond(400, ['success' => false, 'message' => 'Unsupported action.']);
