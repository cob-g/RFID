<?php
include 'auth.php';
requireRole('admin', 'superadmin');
$current_role = $_SESSION['role'];
include 'db.php';

if (!isset($_GET['id'])) {
    exit('No user ID specified');
}

$id = intval($_GET['id']);
$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    exit('User not found');
}

if (isset($_POST['update'])) {
    $username = trim($_POST['username']);
    $role     = $_POST['role'];
    $password = $_POST['password'];

    if ($_SESSION['role'] === 'admin') {
        if ($role === 'admin' || $role === 'superadmin') {
            $_SESSION['error'] = "You cannot assign this role.";
            header("Location: admin_dashboard.php");
            exit;
        }
    }

    if ($password) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET username=?, role=?, password=? WHERE id=?");
        $stmt->bind_param("sssi", $username, $role, $password_hash, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET username=?, role=? WHERE id=?");
        $stmt->bind_param("ssi", $username, $role, $id);
    }

    if ($stmt->execute()) {
        $_SESSION['success'] = "User updated successfully!";
        $_SESSION['redirect_to_users'] = true;
        header("Location: admin_dashboard.php");
        exit;
    } else {
        $_SESSION['error'] = "Error updating user.";
        $_SESSION['redirect_to_users'] = true;
        header("Location: admin_dashboard.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User — Library System</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">

    <style>
        /* ── Design Tokens (identical to librarian_dashboard) ───────────────── */
        :root {
            --gold-light:     #e2c99a;
            --gold-main:      #c8a96e;
            --gold-dark:      #a07840;
            --gold-muted:     rgba(200, 169, 110, 0.18);
            --gold-border:    rgba(200, 169, 110, 0.30);
            --gold-glow:      rgba(200, 169, 110, 0.15);

            --bg-base:        #0d1117;
            --bg-surface:     #111820;
            --bg-panel:       rgba(255, 255, 255, 0.04);
            --bg-panel-hover: rgba(255, 255, 255, 0.07);

            --text-primary:   #f0ece4;
            --text-muted:     #8a8070;
            --text-soft:      #b8af9e;

            --green:          #3ecf8e;
            --green-bg:       rgba(62, 207, 142, 0.12);
            --red:            #e05c5c;
            --red-bg:         rgba(224, 92, 92, 0.12);
            --amber:          #e2a84a;
            --amber-bg:       rgba(226, 168, 74, 0.12);

            --sidebar-w:      260px;
            --radius-sm:      6px;
            --radius-md:      12px;
            --radius-lg:      18px;

            --transition:     all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow-gold:    0 0 30px rgba(200, 169, 110, 0.12);
            --shadow-panel:   0 4px 24px rgba(0, 0, 0, 0.4);
        }

        /* ── Reset & Base ───────────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { height: 100%; scroll-behavior: smooth; }

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--bg-base);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
        }

        /* ── Background Texture ─────────────────────────────────────────────── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 15% 10%, rgba(200,169,110,0.06) 0%, transparent 55%),
                radial-gradient(ellipse 60% 70% at 90% 85%, rgba(200,169,110,0.04) 0%, transparent 55%),
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='30' cy='30' r='0.6' fill='rgba(200,169,110,0.08)'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 0;
        }

        /* ── Sidebar ────────────────────────────────────────────────────────── */
        .sidebar {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            width: var(--sidebar-w);
            background: linear-gradient(180deg, #0f161f 0%, #0a0f14 100%);
            border-right: 1px solid var(--gold-border);
            display: flex;
            flex-direction: column;
            z-index: 100;
            backdrop-filter: blur(20px);
            box-shadow: 4px 0 40px rgba(0,0,0,0.5);
            animation: slideInLeft 0.5s cubic-bezier(0.4,0,0.2,1) both;
        }

        @keyframes slideInLeft {
            from { transform: translateX(-20px); opacity: 0; }
            to   { transform: translateX(0);     opacity: 1; }
        }

        .sidebar-brand {
            padding: 32px 28px 24px;
            border-bottom: 1px solid var(--gold-border);
        }

        .sidebar-brand .brand-icon {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--gold-main), var(--gold-dark));
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 14px;
            box-shadow: 0 0 16px rgba(200,169,110,0.3);
        }

        .sidebar-brand .brand-icon svg { width: 20px; height: 20px; }

        .brand-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 20px;
            font-weight: 600;
            color: var(--gold-light);
            letter-spacing: 0.03em;
            line-height: 1.2;
        }

        .brand-subtitle {
            font-size: 11px;
            color: var(--text-muted);
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-top: 3px;
        }

        .sidebar-nav {
            flex: 1;
            padding: 20px 16px;
            overflow-y: auto;
        }

        .nav-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--text-muted);
            padding: 6px 12px 10px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: var(--radius-sm);
            color: var(--text-soft);
            text-decoration: none;
            font-size: 14px;
            font-weight: 400;
            transition: var(--transition);
            margin-bottom: 2px;
            border: 1px solid transparent;
        }

        .nav-item svg {
            width: 16px; height: 16px;
            flex-shrink: 0;
            opacity: 0.7;
            transition: var(--transition);
        }

        .nav-item:hover, .nav-item.active {
            background: var(--gold-muted);
            border-color: var(--gold-border);
            color: var(--gold-light);
        }

        .nav-item:hover svg, .nav-item.active svg { opacity: 1; }
        .nav-item.active { box-shadow: inset 2px 0 0 var(--gold-main); }

        .sidebar-user {
            padding: 20px 20px 24px;
            border-top: 1px solid var(--gold-border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--gold-dark), var(--gold-main));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Cormorant Garamond', serif;
            font-size: 16px;
            font-weight: 600;
            color: var(--bg-base);
            flex-shrink: 0;
            border: 1.5px solid var(--gold-border);
        }

        .user-info { flex: 1; min-width: 0; }

        .user-name {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 11px;
            color: var(--gold-main);
            letter-spacing: 0.06em;
            text-transform: capitalize;
            margin-top: 1px;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px; height: 30px;
            border-radius: var(--radius-sm);
            background: var(--red-bg);
            border: 1px solid rgba(224,92,92,0.2);
            color: var(--red);
            text-decoration: none;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .logout-btn:hover {
            background: rgba(224,92,92,0.25);
            box-shadow: 0 0 12px rgba(224,92,92,0.15);
        }

        .logout-btn svg { width: 14px; height: 14px; }

        /* ── Main Layout ────────────────────────────────────────────────────── */
        .main-wrap {
            margin-left: var(--sidebar-w);
            flex: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 1;
        }

        /* ── Top Bar ────────────────────────────────────────────────────────── */
        .topbar {
            position: sticky;
            top: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 36px;
            background: rgba(13,17,23,0.85);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--gold-border);
            z-index: 50;
            animation: fadeDown 0.4s ease both;
        }

        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .topbar-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 22px;
            font-weight: 600;
            color: var(--text-primary);
            letter-spacing: 0.02em;
        }

        .topbar-title span { color: var(--gold-main); }

        .topbar-meta {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .date-chip {
            font-size: 12px;
            color: var(--text-muted);
            letter-spacing: 0.05em;
        }

        .role-badge {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            background: var(--gold-muted);
            border: 1px solid var(--gold-border);
            color: var(--gold-light);
        }

        /* ── Page Content ───────────────────────────────────────────────────── */
        .page-content {
            flex: 1;
            padding: 36px 36px 60px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* ── Section Header ─────────────────────────────────────────────────── */
        .section-header {
            display: flex;
            align-items: baseline;
            gap: 14px;
            margin-bottom: 28px;
            width: 100%;
            max-width: 560px;
        }

        .section-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 26px;
            font-weight: 600;
            color: var(--text-primary);
            letter-spacing: 0.01em;
        }

        .section-line {
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, var(--gold-border), transparent);
        }

        /* ── Form Card ──────────────────────────────────────────────────────── */
        .form-card {
            width: 100%;
            max-width: 560px;
            background: var(--bg-panel);
            border: 1px solid var(--gold-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            backdrop-filter: blur(12px);
            box-shadow: var(--shadow-panel);
            animation: fadeUp 0.5s cubic-bezier(0.4,0,0.2,1) 0.1s both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Card header strip */
        .form-card-header {
            padding: 20px 28px 18px;
            border-bottom: 1px solid var(--gold-border);
            background: rgba(200,169,110,0.05);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .form-card-icon {
            width: 36px; height: 36px;
            border-radius: var(--radius-sm);
            background: var(--gold-muted);
            border: 1px solid var(--gold-border);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .form-card-icon svg { width: 16px; height: 16px; stroke: var(--gold-main); }

        .form-card-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            letter-spacing: 0.02em;
        }

        .form-card-subtitle {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
            letter-spacing: 0.03em;
        }

        /* Target user badge in header */
        .target-badge {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 30px;
            background: var(--gold-muted);
            border: 1px solid var(--gold-border);
        }

        .target-badge .t-avatar {
            width: 22px; height: 22px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--gold-dark), var(--gold-main));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Cormorant Garamond', serif;
            font-size: 11px;
            font-weight: 700;
            color: var(--bg-base);
        }

        .target-badge span {
            font-size: 12px;
            color: var(--gold-light);
            font-weight: 500;
        }

        /* Form body */
        .form-body {
            padding: 28px 28px 24px;
        }

        /* Field group */
        .field-group {
            margin-bottom: 22px;
        }

        .field-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--gold-main);
            margin-bottom: 8px;
        }

        .field-label svg {
            width: 12px; height: 12px;
            stroke: var(--gold-main);
            opacity: 0.8;
        }

        .field-hint {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 6px;
            letter-spacing: 0.02em;
        }

        /* Inputs & selects */
        .field-input {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--gold-border);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            outline: none;
            transition: var(--transition);
            appearance: none;
            -webkit-appearance: none;
        }

        .field-input::placeholder { color: var(--text-muted); }

        .field-input:focus {
            border-color: var(--gold-main);
            background: rgba(200,169,110,0.06);
            box-shadow: 0 0 0 3px rgba(200,169,110,0.1);
        }

        /* Password wrapper with toggle */
        .password-wrap {
            position: relative;
        }

        .password-wrap .field-input {
            padding-right: 46px;
        }

        .pw-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            display: flex;
            align-items: center;
            color: var(--text-muted);
            transition: var(--transition);
        }

        .pw-toggle:hover { color: var(--gold-main); }
        .pw-toggle svg { width: 16px; height: 16px; }

        /* Select arrow */
        .select-wrap {
            position: relative;
        }

        .select-wrap::after {
            content: '';
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 0; height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 5px solid var(--gold-main);
            pointer-events: none;
        }

        .field-input option {
            background: #111820;
            color: var(--text-primary);
        }

        .field-input option:disabled {
            color: var(--red);
            opacity: 0.6;
        }

        /* Divider */
        .field-divider {
            height: 1px;
            background: linear-gradient(90deg, var(--gold-border), transparent);
            margin: 28px 0;
        }

        /* ── Action Row ─────────────────────────────────────────────────────── */
        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 28px 24px;
            border-top: 1px solid var(--gold-border);
            background: rgba(200,169,110,0.03);
        }

        /* Primary CTA — matches dashboard action-btn */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 22px;
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.04em;
            background: linear-gradient(135deg, var(--gold-dark), var(--gold-main));
            color: var(--bg-base);
            border: none;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 2px 12px rgba(200,169,110,0.2);
            text-decoration: none;
        }

        .btn-primary svg { width: 14px; height: 14px; stroke: var(--bg-base); }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--gold-main), var(--gold-light));
            box-shadow: 0 4px 20px rgba(200,169,110,0.35);
            transform: translateY(-1px);
        }

        .btn-primary:active { transform: translateY(0); }

        /* Secondary / cancel */
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 18px;
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.1);
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn-secondary svg { width: 14px; height: 14px; }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.08);
            color: var(--text-soft);
            border-color: rgba(255,255,255,0.18);
        }

        /* ── Flash / Alert ──────────────────────────────────────────────────── */
        .flash {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: var(--radius-md);
            font-size: 14px;
            margin-bottom: 28px;
            width: 100%;
            max-width: 560px;
            animation: flashIn 0.4s ease both;
        }

        .flash svg { width: 16px; height: 16px; flex-shrink: 0; }

        .flash.success {
            background: var(--green-bg);
            border: 1px solid rgba(62,207,142,0.25);
            color: var(--green);
        }

        .flash.error {
            background: var(--red-bg);
            border: 1px solid rgba(224,92,92,0.25);
            color: var(--red);
        }

        .flash.warning {
            background: var(--amber-bg);
            border: 1px solid rgba(226,168,74,0.25);
            color: var(--amber);
        }

        @keyframes flashIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Admin restriction notice */
        .restriction-notice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            background: var(--amber-bg);
            border: 1px solid rgba(226,168,74,0.25);
            margin-bottom: 18px;
            font-size: 12px;
            color: var(--amber);
            line-height: 1.5;
        }

        .restriction-notice svg { width: 14px; height: 14px; flex-shrink: 0; margin-top: 1px; }

        /* ── Mobile Toggle ──────────────────────────────────────────────────── */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 16px; left: 16px;
            width: 38px; height: 38px;
            border-radius: var(--radius-sm);
            background: var(--bg-surface);
            border: 1px solid var(--gold-border);
            z-index: 200;
            cursor: pointer;
            align-items: center; justify-content: center;
        }

        .mobile-toggle svg { width: 18px; height: 18px; stroke: var(--gold-main); }

        /* ── Responsive ─────────────────────────────────────────────────────── */
        @media (max-width: 900px) {
            :root { --sidebar-w: 0px; }

            .sidebar {
                transform: translateX(-260px);
                width: 260px;
                transition: transform 0.3s cubic-bezier(0.4,0,0.2,1);
            }

            .sidebar.open { transform: translateX(0); }

            .main-wrap { margin-left: 0; }

            .mobile-toggle { display: flex; }

            .topbar { padding: 14px 20px 14px 64px; }

            .page-content { padding: 24px 20px 60px; }

            .target-badge { display: none; }
        }

        @media (max-width: 600px) {
            .topbar-meta .date-chip { display: none; }

            .form-actions {
                flex-direction: column-reverse;
            }

            .btn-primary,
            .btn-secondary {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<!-- Mobile toggle -->
<button class="mobile-toggle" id="menuToggle" aria-label="Toggle sidebar">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round">
        <line x1="3" y1="7"  x2="21" y2="7"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="17" x2="21" y2="17"/>
    </svg>
</button>

<!-- ════════ SIDEBAR ════════ -->
<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">
        <div class="brand-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="#0d1117" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"
                      fill="#0d1117" stroke="#0d1117"/>
            </svg>
        </div>
        <div class="brand-title">Librarian</div>
        <div class="brand-subtitle">Management System</div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-label">Navigation</div>

        <a href="admin_dashboard.php" class="nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <rect x="3"  y="3"  width="7" height="7"/>
                <rect x="14" y="3"  width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/>
                <rect x="3"  y="14" width="7" height="7"/>
            </svg>
            Dashboard
        </a>

        <a href="admin_dashboard.php" class="nav-item active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            Manage Users
        </a>

    </nav>

    <div class="sidebar-user">
        <div class="user-avatar">
            <?= strtoupper(substr(htmlspecialchars($_SESSION['username']), 0, 1)) ?>
        </div>
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($_SESSION['username']) ?></div>
            <div class="user-role"><?= htmlspecialchars($_SESSION['role']) ?></div>
        </div>
        <a href="logout.php" class="logout-btn" title="Logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
        </a>
    </div>

</aside>

<!-- ════════ MAIN ════════ -->
<div class="main-wrap">

    <header class="topbar">
        <div class="topbar-title">
            Edit <span>User</span>
        </div>
        <div class="topbar-meta">
            <span class="date-chip" id="live-date"></span>
            <span class="role-badge"><?= htmlspecialchars($_SESSION['role']) ?></span>
        </div>
    </header>

    <main class="page-content">

        <!-- Flash messages -->
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="flash error">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <h2 class="section-title">Edit User</h2>
            <div class="section-line"></div>
        </div>

        <div class="form-card">

            <!-- Card header -->
            <div class="form-card-header">
                <div class="form-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </div>
                <div>
                    <div class="form-card-title">Account Details</div>
                    <div class="form-card-subtitle">Modify username, role, or password</div>
                </div>
                <div class="target-badge">
                    <div class="t-avatar">
                        <?= strtoupper(substr(htmlspecialchars($user['username']), 0, 1)) ?>
                    </div>
                    <span><?= htmlspecialchars($user['username']) ?></span>
                </div>
            </div>

            <!-- Form body -->
            <div class="form-body">

                <?php if ($current_role === 'admin'): ?>
                <div class="restriction-notice">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    As an <strong>Admin</strong>, you cannot assign the <em>Admin</em> or <em>Superadmin</em> roles.
                </div>
                <?php endif; ?>

                <form method="POST" id="editForm">

                    <!-- Username -->
                    <div class="field-group">
                        <label class="field-label" for="username">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                            Username
                        </label>
                        <input
                            class="field-input"
                            type="text"
                            id="username"
                            name="username"
                            value="<?= htmlspecialchars($user['username']) ?>"
                            required
                            autocomplete="off"
                            spellcheck="false"
                        >
                    </div>

                    <!-- Role -->
                    <div class="field-group">
                        <label class="field-label" for="roleSelect">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/>
                            </svg>
                            Role
                        </label>
                        <div class="select-wrap">
                            <select class="field-input" name="role" id="roleSelect" required>
                                <?php
                                $roles = ['superadmin', 'admin', 'librarian', 'assistant', 'faculty', 'student'];
                                foreach ($roles as $roleOption):
                                    $isRestricted = ($current_role === 'admin' && in_array($roleOption, ['superadmin', 'admin']));
                                    $selected     = ($user['role'] === $roleOption) ? 'selected' : '';
                                    $disabled     = $isRestricted ? 'disabled' : '';
                                ?>
                                    <option value="<?= $roleOption ?>" <?= $selected ?> <?= $disabled ?>>
                                        <?= ucfirst($roleOption) ?><?= $isRestricted ? ' (restricted)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="field-divider"></div>

                    <!-- Password -->
                    <div class="field-group">
                        <label class="field-label" for="password">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            New Password
                        </label>
                        <div class="password-wrap">
                            <input
                                class="field-input"
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Leave blank to keep current password"
                                autocomplete="new-password"
                            >
                            <button type="button" class="pw-toggle" id="pwToggle" aria-label="Show password">
                                <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <div class="field-hint">Only fill this in if you want to change the password.</div>
                    </div>

                </form>
            </div>

            <!-- Actions -->
            <div class="form-actions">
                <a href="admin_dashboard.php" class="btn-secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"/>
                        <polyline points="12 19 5 12 12 5"/>
                    </svg>
                    Cancel
                </a>
                <button type="submit" form="editForm" name="update" class="btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/>
                        <polyline points="7 3 7 8 15 8"/>
                    </svg>
                    Save Changes
                </button>
            </div>

        </div><!-- /.form-card -->

    </main>
</div>

<script>
    // Live date
    (function () {
        const el = document.getElementById('live-date');
        if (el) {
            el.textContent = new Date().toLocaleDateString('en-US', {
                weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'
            });
        }
    })();

    // Mobile sidebar toggle
    const toggle  = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');

    toggle.addEventListener('click', () => sidebar.classList.toggle('open'));

    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 900 &&
            !sidebar.contains(e.target) &&
            !toggle.contains(e.target)) {
            sidebar.classList.remove('open');
        }
    });

    // Password show/hide toggle
    const pwField  = document.getElementById('password');
    const pwToggle = document.getElementById('pwToggle');
    const eyeIcon  = document.getElementById('eyeIcon');

    const eyeOpen   = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
    const eyeClosed = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>`;

    pwToggle.addEventListener('click', () => {
        const isHidden = pwField.type === 'password';
        pwField.type   = isHidden ? 'text' : 'password';
        eyeIcon.innerHTML = isHidden ? eyeClosed : eyeOpen;
    });
</script>

</body>
</html>