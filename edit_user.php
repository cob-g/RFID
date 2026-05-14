<?php
include 'auth.php';
requireRole('admin', 'superadmin');
include 'db.php';

enforceOperatingHoursPageGate(
    $conn,
    ['admin'],
    'Admin Access Temporarily Closed',
    'Admin account management is available only during operating hours.'
);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    exit('No valid user ID specified.');
}

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    exit('User not found.');
}

$current_role = strtolower((string) ($_SESSION['role'] ?? ''));
$isSuperadmin = ($current_role === 'superadmin');
$canViewLogs = in_array($current_role, ['admin', 'superadmin'], true);
$target_role = strtolower((string) ($user['role'] ?? ''));

if ($current_role === 'admin' && in_array($target_role, ['admin', 'superadmin'], true)) {
    $_SESSION['error'] = 'You do not have permission to edit this account.';
    $_SESSION['redirect_to_users'] = true;
    header('Location: admin_dashboard.php?tab=users');
    exit;
}

$allowedRoles = ['superadmin', 'admin', 'librarian', 'assistant', 'faculty', 'student'];

function studentIdTaken(mysqli $conn, string $studentId, int $currentUserId): bool
{
    $studentId = trim($studentId);
    if ($studentId === '' || !dbTableExists($conn, 'student_profiles')) {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT user_id FROM student_profiles WHERE student_id = ? AND user_id <> ? LIMIT 1'
    );
    $stmt->bind_param('si', $studentId, $currentUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (bool) $row;
}

$formError = '';
$usernameInput = (string) ($user['username'] ?? '');
$roleInput = (string) ($user['role'] ?? '');

// Student profile data
$studentProfile = studentProfileFetch($conn, $id);
$studentIdInput = (string) ($studentProfile['student_id'] ?? '');
$courseInput = (string) ($studentProfile['course_or_department'] ?? '');
$yearLevelInput = (string) ($studentProfile['year_level'] ?? '');
$sectionInput = (string) ($studentProfile['section'] ?? '');
$addressInput = (string) ($studentProfile['address'] ?? '');
$firstNameInput = (string) ($studentProfile['first_name'] ?? '');
$middleInitialInput = (string) ($studentProfile['middle_initial'] ?? '');
$lastNameInput = (string) ($studentProfile['last_name'] ?? '');

// Faculty profile data
$facultyProfile = [];
$facultyFirstNameInput = '';
$facultyLastNameInput = '';
$facultyMiddleInitialInput = '';
$facultyLevelInput = '';
$facultyDepartmentInput = '';

if (function_exists('facultyProfileFetch')) {
    $facultyProfile = facultyProfileFetch($conn, $id);
} else {
    // Fallback in case helper isn't yet in db.php – avoids breaking the page
    $facultyProfile = [];
}
if (!empty($facultyProfile)) {
    $facultyFirstNameInput = (string) ($facultyProfile['first_name'] ?? '');
    $facultyLastNameInput = (string) ($facultyProfile['last_name'] ?? '');
    $facultyMiddleInitialInput = (string) ($facultyProfile['middle_initial'] ?? '');
    $facultyLevelInput = (string) ($facultyProfile['faculty_level'] ?? '');
    $facultyDepartmentInput = (string) ($facultyProfile['department'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $formError = 'Invalid request token. Please refresh and try again.';
    }

    $usernameInput = trim((string) ($_POST['username'] ?? ''));
    $roleInput = trim((string) ($_POST['role'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Student fields
    $firstNameInput = trim((string) ($_POST['first_name'] ?? $firstNameInput));
    $middleInitialInput = trim((string) ($_POST['middle_initial'] ?? $middleInitialInput));
    $lastNameInput = trim((string) ($_POST['last_name'] ?? $lastNameInput));
    $studentIdInput = trim((string) ($_POST['student_id'] ?? $studentIdInput));
    $courseInput = trim((string) ($_POST['course_or_department'] ?? $courseInput));
    $yearLevelInput = trim((string) ($_POST['year_level'] ?? $yearLevelInput));
    $sectionInput = trim((string) ($_POST['section'] ?? $sectionInput));
    $addressInput = trim((string) ($_POST['address'] ?? $addressInput));

    // Faculty fields
    $facultyFirstNameInput = trim((string) ($_POST['faculty_first_name'] ?? $facultyFirstNameInput));
    $facultyLastNameInput = trim((string) ($_POST['faculty_last_name'] ?? $facultyLastNameInput));
    $facultyMiddleInitialInput = trim((string) ($_POST['faculty_middle_initial'] ?? $facultyMiddleInitialInput));
    $facultyLevelInput = trim((string) ($_POST['faculty_level'] ?? $facultyLevelInput));
    $facultyDepartmentInput = trim((string) ($_POST['faculty_department'] ?? $facultyDepartmentInput));

    if ($formError === '' && $usernameInput === '') {
        $formError = 'Username is required.';
    }

    if ($formError === '' && !in_array($roleInput, $allowedRoles, true)) {
        $formError = 'Please choose a valid role.';
    }

    if ($formError === '' && $current_role === 'admin' && in_array($roleInput, ['admin', 'superadmin'], true)) {
        $formError = 'You cannot assign this role.';
    }

    // Student validation
    if ($formError === '' && $roleInput === 'student') {
        if ($studentIdInput === '' || $courseInput === '' || $yearLevelInput === '' || $sectionInput === '' || $addressInput === '') {
            $formError = 'Please complete all student profile fields.';
        } elseif (studentIdTaken($conn, $studentIdInput, $id)) {
            $formError = 'Student ID is already assigned to another user.';
        }
    }

    // Faculty validation
    if ($formError === '' && $roleInput === 'faculty') {
        if ($facultyLevelInput === '') {
            $formError = 'Faculty Level is required for faculty accounts.';
        }
        // Optional: require first/last name for faculty as well
        if ($facultyFirstNameInput === '' || $facultyLastNameInput === '') {
            $formError = 'First name and last name are required for faculty.';
        }
    }

    if ($formError === '') {
        $originalUsername = (string) ($user['username'] ?? '');
        $originalRole = (string) ($user['role'] ?? '');
        $studentProfileChanged = false;
        $facultyProfileChanged = false;

        if ($roleInput === 'student') {
            $studentProfileChanged = (
                $firstNameInput !== (string) ($studentProfile['first_name'] ?? '')
                || $lastNameInput !== (string) ($studentProfile['last_name'] ?? '')
                || $middleInitialInput !== (string) ($studentProfile['middle_initial'] ?? '')
                || $studentIdInput !== (string) ($studentProfile['student_id'] ?? '')
                || $courseInput !== (string) ($studentProfile['course_or_department'] ?? '')
                || $yearLevelInput !== (string) ($studentProfile['year_level'] ?? '')
                || $sectionInput !== (string) ($studentProfile['section'] ?? '')
                || $addressInput !== (string) ($studentProfile['address'] ?? '')
            );
        }

        if ($roleInput === 'faculty') {
            $facultyProfileChanged = (
                $facultyFirstNameInput !== (string) ($facultyProfile['first_name'] ?? '')
                || $facultyLastNameInput !== (string) ($facultyProfile['last_name'] ?? '')
                || $facultyMiddleInitialInput !== (string) ($facultyProfile['middle_initial'] ?? '')
                || $facultyLevelInput !== (string) ($facultyProfile['faculty_level'] ?? '')
                || $facultyDepartmentInput !== (string) ($facultyProfile['department'] ?? '')
            );
        }

        try {
            $conn->begin_transaction();

            // Update users table
            if ($password !== '') {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET username = ?, role = ?, password = ? WHERE id = ?');
                $stmt->bind_param('sssi', $usernameInput, $roleInput, $password_hash, $id);
            } else {
                $stmt = $conn->prepare('UPDATE users SET username = ?, role = ? WHERE id = ?');
                $stmt->bind_param('ssi', $usernameInput, $roleInput, $id);
            }
            $stmt->execute();
            $stmt->close();

            // Handle student profile
            if ($roleInput === 'student') {
                studentProfileUpsert($conn, $id, [
                    'first_name' => $firstNameInput,
                    'last_name' => $lastNameInput,
                    'middle_initial' => $middleInitialInput,
                    'student_id' => $studentIdInput,
                    'course_or_department' => $courseInput,
                    'year_level' => $yearLevelInput,
                    'section' => $sectionInput,
                    'address' => $addressInput,
                ]);
            }

            // Handle faculty profile (requires facultyProfileUpsert in db.php)
            if ($roleInput === 'faculty' && function_exists('facultyProfileUpsert')) {
                facultyProfileUpsert($conn, $id, [
                    'first_name' => $facultyFirstNameInput,
                    'last_name' => $facultyLastNameInput,
                    'middle_initial' => $facultyMiddleInitialInput,
                    'faculty_level' => $facultyLevelInput,
                    'department' => $facultyDepartmentInput,
                ]);
            } elseif ($roleInput === 'faculty' && !function_exists('facultyProfileUpsert')) {
                // Log error but allow commit – helper will be added later
                error_log('[edit_user] facultyProfileUpsert missing, faculty data not saved');
            }

            $conn->commit();

            // Audit trail
            $changedFields = [];
            if ($usernameInput !== $originalUsername) $changedFields[] = 'username';
            if ($roleInput !== $originalRole) $changedFields[] = 'role';
            if ($password !== '') $changedFields[] = 'password';
            if ($studentProfileChanged) $changedFields[] = 'student_profile';
            if ($facultyProfileChanged) $changedFields[] = 'faculty_profile';

            $auditDetails = [
                'changed' => $changedFields,
                'role_before' => $originalRole,
                'role_after' => $roleInput,
            ];
            if ($roleInput === 'student') {
                $auditDetails['student_profile'] = [
                    'first_name' => $firstNameInput,
                    'last_name' => $lastNameInput,
                    'middle_initial' => $middleInitialInput,
                    'student_id' => $studentIdInput,
                    'course_or_department' => $courseInput,
                    'year_level' => $yearLevelInput,
                    'section' => $sectionInput,
                    'address' => $addressInput,
                ];
            }
            if ($roleInput === 'faculty') {
                $auditDetails['faculty_profile'] = [
                    'first_name' => $facultyFirstNameInput,
                    'last_name' => $facultyLastNameInput,
                    'middle_initial' => $facultyMiddleInitialInput,
                    'faculty_level' => $facultyLevelInput,
                    'department' => $facultyDepartmentInput,
                ];
            }

            auditLogWrite($conn, [
                'actor_user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'actor_username' => (string) ($_SESSION['username'] ?? ''),
                'actor_role' => (string) ($_SESSION['role'] ?? ''),
                'action' => 'user_update',
                'target_type' => 'user',
                'target_id' => $id,
                'target_label' => $usernameInput,
                'details' => $auditDetails,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            $_SESSION['success'] = 'User updated successfully.';
            $_SESSION['redirect_to_users'] = true;
            header('Location: admin_dashboard.php?tab=users');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[edit_user] Update failed: ' . $e->getMessage());
            $formError = 'Error updating user.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit User - SCC Library</title>
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
    --glass-border: rgba(200, 169, 110, 0.20);
    --glass-blur:   blur(20px) saturate(150%);

    --text-main:    #f0ece4;
    --text-sub:     rgba(240, 236, 228, 0.65);
    --text-muted:   rgba(240, 236, 228, 0.38);

    --red:          #ef4444;
    --radius:       12px;
    --radius-lg:    18px;
    --transition:   0.25s cubic-bezier(0.4, 0, 0.2, 1);
    --shadow:       0 8px 32px rgba(0, 0, 0, 0.50);
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
        rgba(10, 14, 20, 0.93) 40%,
        rgba(10, 14, 20, 0.85) 70%,
        rgba(10, 14, 20, 0.90) 100%
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
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: rgba(200, 169, 110, 0.10);
    border: 1.5px solid var(--glass-border);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 0 0 4px rgba(200, 169, 110, 0.06);
}

.sidebar-logo-ring img {
    width: 26px;
    height: auto;
    filter: drop-shadow(0 1px 4px rgba(200, 169, 110, 0.30));
}

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

.nav-link {
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius);
    color: var(--text-sub);
    padding: 11px 14px;
    text-decoration: none;
    width: 100%;
    font-size: 0.88rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all var(--transition);
    letter-spacing: 0.01em;
}

.nav-link .nav-icon {
    font-size: 1.05rem;
    width: 20px;
    text-align: center;
    flex-shrink: 0;
}

.nav-link:hover {
    background: var(--glass-bg);
    color: var(--text-main);
    border-color: var(--glass-border);
}

.nav-link.active {
    background: rgba(200, 169, 110, 0.12);
    border-color: rgba(200, 169, 110, 0.32);
    color: var(--gold-light);
    font-weight: 600;
    box-shadow: 0 2px 12px rgba(200, 169, 110, 0.12);
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
    font-size: 0.84rem;
    font-weight: 600;
    text-decoration: none;
    transition: all var(--transition);
    letter-spacing: 0.02em;
}

.btn-logout:hover {
    background: rgba(239, 68, 68, 0.16);
    border-color: rgba(239, 68, 68, 0.45);
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
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    cursor: pointer;
    transition: all var(--transition);
    flex-shrink: 0;
}

.hamburger:hover { background: var(--glass-bg-md); }

.drawer-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 200;
    background: rgba(0, 0, 0, 0.60);
    backdrop-filter: blur(4px);
}

.drawer-overlay.open { display: block; }

.main-shell {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow: hidden;
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
    margin-bottom: 28px;
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

.page-header-text h1 em {
    font-style: italic;
    color: var(--gold-light);
}

.header-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(200, 169, 110, 0.10);
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

.section-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.4rem;
    font-weight: 400;
    color: var(--text-main);
    margin-bottom: 14px;
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

.editor-shell {
    max-width: 980px;
    margin: 0 auto;
    width: 100%;
}

.welcome-box,
.form-wrap {
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
}

.welcome-box {
    padding: 18px 20px;
    color: var(--text-sub);
    font-size: 0.90rem;
    line-height: 1.6;
    margin-bottom: 14px;
}

.welcome-box strong { color: var(--gold); }

.form-wrap {
    overflow: hidden;
    max-width: 720px;
    width: 100%;
    margin: 0 auto;
}

.form-head {
    padding: 14px 18px;
    border-bottom: 1px solid var(--glass-border);
    background: rgba(200, 169, 110, 0.10);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.10em;
    color: var(--gold);
    font-weight: 700;
}

.form-body {
    padding: 20px 18px;
    display: grid;
    gap: 14px;
}

.field label {
    display: block;
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 7px;
}

.field input,
.field select {
    width: 100%;
    border: 1px solid var(--glass-border);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.05);
    color: var(--text-main);
    padding: 11px 12px;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.90rem;
    transition: all var(--transition);
}

.field input:focus,
.field select:focus {
    outline: none;
    border-color: rgba(200, 169, 110, 0.50);
    box-shadow: 0 0 0 3px rgba(200, 169, 110, 0.16);
}

.field select option {
    background: #111820;
    color: var(--text-main);
}

.field-group-label {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.10em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin: 6px 0 4px;
}

.profile-fields {
    display: none;
    gap: 14px;
}

.profile-fields.active {
    display: grid;
}

.inline-note {
    margin-top: 6px;
    font-size: 0.78rem;
    color: var(--text-muted);
}

.password-row {
    position: relative;
}

.password-row input {
    padding-right: 92px;
}

.pw-toggle {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    border: 1px solid var(--glass-border);
    background: rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    color: var(--text-main);
    padding: 6px 9px;
    font-size: 0.75rem;
    cursor: pointer;
}

.pw-toggle:hover {
    background: rgba(255, 255, 255, 0.12);
}

.form-actions {
    border-top: 1px solid var(--glass-border);
    background: rgba(200, 169, 110, 0.08);
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 9px;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.btn-secondary,
.btn-primary {
    border-radius: 9px;
    border: 1px solid transparent;
    text-decoration: none;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.83rem;
    font-weight: 600;
    padding: 9px 13px;
    cursor: pointer;
    transition: all var(--transition);
}

.btn-secondary {
    background: var(--glass-bg);
    border-color: var(--glass-border);
    color: var(--text-sub);
}

.btn-secondary:hover {
    background: var(--glass-bg-md);
    color: var(--text-main);
}

.btn-primary {
    background: rgba(200, 169, 110, 0.15);
    border-color: rgba(200, 169, 110, 0.35);
    color: var(--gold-light);
}

.btn-primary:hover {
    background: rgba(200, 169, 110, 0.22);
    border-color: rgba(200, 169, 110, 0.50);
}

.form-error {
    margin: 0 auto 14px;
    background: rgba(239, 68, 68, 0.12);
    border: 1px solid rgba(239, 68, 68, 0.35);
    color: #fca5a5;
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 0.84rem;
    max-width: 720px;
    width: 100%;
}

@media (max-width: 900px) {
    .main-content { padding: 24px 18px 60px; }
}

@media (max-width: 768px) {
    .topbar { display: flex; }

    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        bottom: 0;
        transform: translateX(-100%);
        z-index: 201;
        height: 100%;
    }

    .sidebar.open { transform: translateX(0); }
}

@media (max-width: 600px) {
    .main-content { padding: 18px 14px 60px; }
    .form-actions { justify-content: stretch; }
    .btn-secondary,
    .btn-primary { width: 100%; text-align: center; }
}
</style>
</head>
<body>

<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div class="drawer-overlay" id="drawerOverlay"></div>

<div class="wrapper">
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo-ring">
                <img src="logo.png" alt="SCC Logo">
            </div>
            <div class="sidebar-brand-text">
                <div class="brand-name">SCC Library</div>
                <div class="brand-role"><?= ucfirst(htmlspecialchars((string) $_SESSION['role'])) ?> Panel</div>
            </div>
        </div>

        <div class="sidebar-nav">
            <div class="nav-section-label">Navigation</div>
            <a class="nav-link" href="admin_dashboard.php">
                <span class="nav-icon">🏠</span> Dashboard
            </a>
            <a class="nav-link active" href="admin_dashboard.php?tab=users">
                <span class="nav-icon">👥</span> Manage Users
            </a>
            <a class="nav-link" href="admin_dashboard.php?tab=seats">
                <span class="nav-icon">🪑</span> Seat Allocation
            </a>
            <a class="nav-link" href="admin_dashboard.php?tab=computers">
                <span class="nav-icon">🖥️</span> Manage Computers
            </a>
            <a class="nav-link" href="admin_dashboard.php?tab=rfid-portal">
                <span class="nav-icon">📡</span> RFID Portal
            </a>
            <?php if ($canViewLogs): ?>
            <a class="nav-link" href="admin_dashboard.php?tab=logs">
                <span class="nav-icon">📋</span> Logs
            </a>
            <a class="nav-link" href="admin_dashboard.php?tab=logs-archive">
                <span class="nav-icon">🗂️</span> Archived Logs
            </a>
            <?php endif; ?>
            <?php if ($isSuperadmin): ?>
            <a class="nav-link" href="admin_dashboard.php?tab=system-hours">
                <span class="nav-icon">⏰</span> System Hours
            </a>
            <?php endif; ?>
        </div>

        <div class="sidebar-footer">
            <a href="logout.php" class="btn-logout"><span>⎋</span> Logout</a>
        </div>
    </nav>

    <div class="main-shell">
        <div class="topbar">
            <button class="hamburger" id="hamburger" aria-label="Open menu">☰</button>
            <span class="topbar-brand">Edit User</span>
            <a href="logout.php" style="font-size:0.78rem; color:#f87171; text-decoration:none; font-weight:600;">Logout</a>
        </div>

        <main class="main-content">
            <div class="page-header">
                <div class="page-header-text">
                    <div class="greeting">Control Center</div>
                    <h1>Edit Account: <em><?= htmlspecialchars($usernameInput) ?></em></h1>
                    <div class="header-badge">📅 <?= date('F j, Y') ?></div>
                </div>
            </div>

            <div class="editor-shell">
                <div class="section-title">Manage Users</div>

                <div class="welcome-box">
                    Update the selected user account details below. Leave password blank if you do not want to change it.
                    <?php if ($current_role === 'admin'): ?>
                        <br><strong>Admin policy:</strong> You cannot assign admin or superadmin roles.
                    <?php endif; ?>
                </div>

                <?php if ($formError !== ''): ?>
                    <div class="form-error"><?= htmlspecialchars($formError) ?></div>
                <?php endif; ?>

                <div class="form-wrap">
                    <div class="form-head">Account Editor</div>

                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) $_SESSION['csrf_token']) ?>">

                        <div class="form-body">
                            <div class="field">
                                <label for="username">Username</label>
                                <input
                                    type="text"
                                    id="username"
                                    name="username"
                                    value="<?= htmlspecialchars($usernameInput) ?>"
                                    maxlength="100"
                                    required
                                >
                            </div>

                            <div class="field">
                                <label for="role">Role</label>
                                <select id="role" name="role" required>
                                    <?php foreach ($allowedRoles as $roleOption): ?>
                                        <?php
                                        $restricted = $current_role === 'admin' && in_array($roleOption, ['superadmin', 'admin'], true);
                                        $selected = $roleInput === $roleOption ? 'selected' : '';
                                        $disabled = $restricted ? 'disabled' : '';
                                        ?>
                                        <option value="<?= htmlspecialchars($roleOption) ?>" <?= $selected ?> <?= $disabled ?>>
                                            <?= ucfirst($roleOption) ?><?= $restricted ? ' (restricted)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="inline-note">Choose the access level this user should have.</div>
                            </div>

                            <!-- Student Profile Fields -->
                            <div id="studentFields" class="profile-fields">
                                <div class="field-group-label">Student Profile</div>

                                <div class="field">
                                    <label for="first_name">First Name</label>
                                    <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($firstNameInput) ?>" maxlength="100">
                                </div>
                                <div class="field">
                                    <label for="last_name">Last Name</label>
                                    <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($lastNameInput) ?>" maxlength="100">
                                </div>
                                <div class="field">
                                    <label for="student_id">Student ID</label>
                                    <input type="text" id="student_id" name="student_id" value="<?= htmlspecialchars($studentIdInput) ?>" maxlength="40">
                                </div>
                                <div class="field">
                                    <label for="course_or_department">Course / Department</label>
                                    <input type="text" id="course_or_department" name="course_or_department" value="<?= htmlspecialchars($courseInput) ?>" maxlength="120">
                                </div>
                                <div class="field">
                                    <label for="year_level">Year Level</label>
                                    <input type="text" id="year_level" name="year_level" value="<?= htmlspecialchars($yearLevelInput) ?>" maxlength="30">
                                </div>
                                <div class="field">
                                    <label for="section">Section</label>
                                    <input type="text" id="section" name="section" value="<?= htmlspecialchars($sectionInput) ?>" maxlength="30">
                                </div>
                                <div class="field">
                                    <label for="middle_initial">Middle Initial</label>
                                    <input type="text" id="middle_initial" name="middle_initial" value="<?= htmlspecialchars($middleInitialInput) ?>" maxlength="5">
                                </div>
                                <div class="field">
                                    <label for="address">Address</label>
                                    <input type="text" id="address" name="address" value="<?= htmlspecialchars($addressInput) ?>" maxlength="255">
                                </div>
                            </div>

                            <!-- Faculty Profile Fields -->
                            <div id="facultyFields" class="profile-fields">
                                <div class="field-group-label">Faculty Profile</div>

                                <div class="field">
                                    <label for="faculty_first_name">First Name</label>
                                    <input type="text" id="faculty_first_name" name="faculty_first_name" value="<?= htmlspecialchars($facultyFirstNameInput) ?>" maxlength="100">
                                </div>
                                <div class="field">
                                    <label for="faculty_last_name">Last Name</label>
                                    <input type="text" id="faculty_last_name" name="faculty_last_name" value="<?= htmlspecialchars($facultyLastNameInput) ?>" maxlength="100">
                                </div>
                                <div class="field">
                                    <label for="faculty_middle_initial">Middle Initial</label>
                                    <input type="text" id="faculty_middle_initial" name="faculty_middle_initial" value="<?= htmlspecialchars($facultyMiddleInitialInput) ?>" maxlength="5">
                                </div>
                                <div class="field">
                                    <label for="faculty_level">Faculty Level *</label>
                                    <select id="faculty_level" name="faculty_level">
                                        <option value="">-- Select Level --</option>
                                        <option value="Instructor" <?= $facultyLevelInput === 'Instructor' ? 'selected' : '' ?>>Instructor</option>
                                        <option value="Assistant Professor" <?= $facultyLevelInput === 'Assistant Professor' ? 'selected' : '' ?>>Assistant Professor</option>
                                        <option value="Associate Professor" <?= $facultyLevelInput === 'Associate Professor' ? 'selected' : '' ?>>Associate Professor</option>
                                        <option value="Professor" <?= $facultyLevelInput === 'Professor' ? 'selected' : '' ?>>Professor</option>
                                        <option value="Lecturer" <?= $facultyLevelInput === 'Lecturer' ? 'selected' : '' ?>>Lecturer</option>
                                        <option value="Professor Emeritus" <?= $facultyLevelInput === 'Professor Emeritus' ? 'selected' : '' ?>>Professor Emeritus</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="faculty_department">Department</label>
                                    <input type="text" id="faculty_department" name="faculty_department" value="<?= htmlspecialchars($facultyDepartmentInput) ?>" maxlength="120">
                                </div>
                            </div>

                            <div class="field">
                                <label for="password">New Password</label>
                                <div class="password-row">
                                    <input type="password" id="password" name="password" placeholder="Leave blank to keep current password" autocomplete="new-password">
                                    <button type="button" class="pw-toggle" id="togglePassword">Show</button>
                                </div>
                                <div class="inline-note">Only fill this in when changing the password.</div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="admin_dashboard.php?tab=users" class="btn-secondary">Cancel</a>
                            <button type="submit" name="update" class="btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
'use strict';

const sidebar = document.getElementById('sidebar');
const drawerOverlay = document.getElementById('drawerOverlay');
const hamburger = document.getElementById('hamburger');

function openSidebar() {
    if (!sidebar || !drawerOverlay) return;
    sidebar.classList.add('open');
    drawerOverlay.classList.add('open');
}

function closeSidebar() {
    if (!sidebar || !drawerOverlay) return;
    sidebar.classList.remove('open');
    drawerOverlay.classList.remove('open');
}

if (hamburger) {
    hamburger.addEventListener('click', openSidebar);
}
if (drawerOverlay) {
    drawerOverlay.addEventListener('click', closeSidebar);
}
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeSidebar();
});

const passwordInput = document.getElementById('password');
const togglePassword = document.getElementById('togglePassword');
const roleSelect = document.getElementById('role');
const studentFields = document.getElementById('studentFields');
const facultyFields = document.getElementById('facultyFields');

if (togglePassword && passwordInput) {
    togglePassword.addEventListener('click', () => {
        const hidden = passwordInput.type === 'password';
        passwordInput.type = hidden ? 'text' : 'password';
        togglePassword.textContent = hidden ? 'Hide' : 'Show';
    });
}

function toggleProfileFields() {
    if (!roleSelect) return;
    const selectedRole = roleSelect.value;

    // Hide both sections initially
    if (studentFields) studentFields.classList.remove('active');
    if (facultyFields) facultyFields.classList.remove('active');

    if (selectedRole === 'student' && studentFields) {
        studentFields.classList.add('active');
        // set required attributes for student fields
        const studentInputs = studentFields.querySelectorAll('input, select');
        studentInputs.forEach(input => { input.required = true; });
        if (facultyFields) {
            const facultyInputs = facultyFields.querySelectorAll('input, select');
            facultyInputs.forEach(input => { input.required = false; });
        }
    } else if (selectedRole === 'faculty' && facultyFields) {
        facultyFields.classList.add('active');
        const facultyInputs = facultyFields.querySelectorAll('input, select');
        facultyInputs.forEach(input => { input.required = true; });
        if (studentFields) {
            const studentInputs = studentFields.querySelectorAll('input, select');
            studentInputs.forEach(input => { input.required = false; });
        }
    } else {
        // neither student nor faculty – no profile fields required
        if (studentFields) {
            const studentInputs = studentFields.querySelectorAll('input, select');
            studentInputs.forEach(input => { input.required = false; });
        }
        if (facultyFields) {
            const facultyInputs = facultyFields.querySelectorAll('input, select');
            facultyInputs.forEach(input => { input.required = false; });
        }
    }
}

if (roleSelect) {
    roleSelect.addEventListener('change', toggleProfileFields);
}
toggleProfileFields();
</script>

</body>
</html>