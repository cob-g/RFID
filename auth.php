<?php
// ════════════════════════════════════════════════════════════════
//  AUTH HELPER — Secure version
// ════════════════════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_httponly', 1);

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }

    session_start();
}

require_once __DIR__ . '/system_hours.php';

// Roles
define('ROLE_ADMIN',   'admin');
define('ROLE_STUDENT', 'student');
define('ROLE_FACULTY', 'faculty');

// Helpers
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function currentUserRole(): ?string
{
    return $_SESSION['role'] ?? null;
}

// Require login
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header("Location: index.php");
        exit;
    }
}

// Require role (also enforces login)
function requireRole(string ...$allowedRoles): void
{
    requireLogin();

    if (!isset($_SESSION['role'])) {
        http_response_code(403);
        exit('Access denied.');
    }

    $userRole = strtolower($_SESSION['role']);
    $allowedRoles = array_map('strtolower', $allowedRoles);

    if (!in_array($userRole, $allowedRoles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}

// Admin shortcut
function requireAdmin(): void
{
    requireRole(ROLE_ADMIN);
}

// Call after login
function secureLoginSession(): void
{
    session_regenerate_id(true);
}
// ════════════════════════════════════════════════════════════════
// CSRF PROTECTION
// ════════════════════════════════════════════════════════════════

function ensureCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token']) &&
           hash_equals($_SESSION['csrf_token'], $token);
}

function roleIsSubjectToOperatingHours(string $role): bool
{
    return in_array($role, ['admin', 'librarian', 'assistant', 'faculty', 'student'], true);
}

function enforceOperatingHoursPageGate(
    mysqli $conn,
    array $roles = [],
    string $title = 'Library Access Temporarily Closed',
    string $subtitle = 'This section is unavailable outside operating hours.'
): void {
    $currentRole = strtolower((string) ($_SESSION['role'] ?? ''));
    if ($currentRole === '') {
        return;
    }

    if ($currentRole === 'superadmin') {
        return;
    }

    $normalizedRoles = array_values(array_filter(array_map(
        static fn(string $role): string => strtolower(trim($role)),
        $roles
    ), static fn(string $role): bool => $role !== ''));

    if ($normalizedRoles === []) {
        if (!roleIsSubjectToOperatingHours($currentRole)) {
            return;
        }
    } elseif (!in_array($currentRole, $normalizedRoles, true)) {
        return;
    }

    $hoursState = libraryHoursEvaluate($conn);
    if ($hoursState['is_open']) {
        return;
    }

    libraryHoursRenderClosedPage($conn, $title, $subtitle, 403, $hoursState);
}

function operatingHoursClosedApiPayload(
    mysqli $conn,
    string $prefix = 'Library access is currently closed.',
    ?array $hoursState = null
): array {
    $state = is_array($hoursState) ? $hoursState : libraryHoursEvaluate($conn);
    return libraryHoursApiClosedPayload($conn, $prefix, $state);
}