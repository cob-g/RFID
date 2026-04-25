<?php
session_start();
require_once 'auth.php';
requireLogin();
requireRole('librarian', 'assistant');
require_once 'db.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Fetch seat data ───────────────────────────────────────────────────────────
$seatsQuery = "
    SELECT seats.id, seats.seat_number, seats.status, users.username
    FROM seats
    LEFT JOIN users ON seats.reserved_by = users.id
";
$seatsResult    = mysqli_query($conn, $seatsQuery);
$seatsData      = [];
$totalSeats     = 0;
$allocatedSeats = 0;
while ($row = mysqli_fetch_assoc($seatsResult)) {
    $seatsData[] = $row;
    $totalSeats++;
    if ($row['status'] === 'reserved') $allocatedSeats++;
}

// ── Fetch computer data ───────────────────────────────────────────────────────
$computersQuery = "
    SELECT computers.id, computers.computer_number, computers.status, users.username
    FROM computers
    LEFT JOIN users ON computers.reserved_by = users.id
";
$computersResult    = mysqli_query($conn, $computersQuery);
$computersData      = [];
$totalComputers     = 0;
$allocatedComputers = 0;
while ($row = mysqli_fetch_assoc($computersResult)) {
    $computersData[] = $row;
    $totalComputers++;
    if ($row['status'] === 'reserved') $allocatedComputers++;
}

$availableSeats     = $totalSeats - $allocatedSeats;
$availableComputers = $totalComputers - $allocatedComputers;
$isLibrarian        = ($_SESSION['role'] === 'librarian');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard — Library System</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">

    <style>
        /* ── Design Tokens ──────────────────────────────────────────────────── */
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

        /* Sidebar brand */
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

        /* Sidebar nav */
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

        .nav-item:hover,
        .nav-item.active {
            background: var(--gold-muted);
            border-color: var(--gold-border);
            color: var(--gold-light);
        }

        .nav-item:hover svg,
        .nav-item.active svg { opacity: 1; }

        .nav-item.active {
            box-shadow: inset 2px 0 0 var(--gold-main);
        }

        /* Sidebar user card */
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
        }

        /* ── Section Header ─────────────────────────────────────────────────── */
        .section-header {
            display: flex;
            align-items: baseline;
            gap: 14px;
            margin-bottom: 24px;
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

        .section-count {
            font-size: 12px;
            color: var(--text-muted);
            letter-spacing: 0.06em;
        }

        /* ── Stat Cards ─────────────────────────────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--bg-panel);
            border: 1px solid var(--gold-border);
            border-radius: var(--radius-md);
            padding: 24px 22px;
            backdrop-filter: blur(12px);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            animation: fadeUp 0.5s cubic-bezier(0.4,0,0.2,1) both;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 2px;
            background: linear-gradient(90deg, var(--gold-dark), var(--gold-light), var(--gold-dark));
            opacity: 0;
            transition: var(--transition);
        }

        .stat-card:hover {
            background: var(--bg-panel-hover);
            box-shadow: var(--shadow-gold);
            transform: translateY(-2px);
        }

        .stat-card:hover::before { opacity: 1; }

        .stat-card:nth-child(1) { animation-delay: 0.05s; }
        .stat-card:nth-child(2) { animation-delay: 0.10s; }
        .stat-card:nth-child(3) { animation-delay: 0.15s; }
        .stat-card:nth-child(4) { animation-delay: 0.20s; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .stat-icon {
            width: 38px; height: 38px;
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 18px;
        }

        .stat-icon svg { width: 18px; height: 18px; }

        .stat-icon.gold  { background: var(--gold-muted); border: 1px solid var(--gold-border); }
        .stat-icon.gold svg  { stroke: var(--gold-main); }
        .stat-icon.green { background: var(--green-bg);  border: 1px solid rgba(62,207,142,0.2); }
        .stat-icon.green svg { stroke: var(--green); }
        .stat-icon.red   { background: var(--red-bg);   border: 1px solid rgba(224,92,92,0.2); }
        .stat-icon.red svg   { stroke: var(--red); }
        .stat-icon.amber { background: var(--amber-bg); border: 1px solid rgba(226,168,74,0.2); }
        .stat-icon.amber svg { stroke: var(--amber); }

        .stat-value {
            font-family: 'Cormorant Garamond', serif;
            font-size: 36px;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 6px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .stat-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255,255,255,0.05);
        }

        .stat-sub strong { color: var(--text-soft); }

        /* ── Tables ─────────────────────────────────────────────────────────── */
        .table-wrap {
            background: var(--bg-panel);
            border: 1px solid var(--gold-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            backdrop-filter: blur(12px);
            box-shadow: var(--shadow-panel);
            margin-bottom: 40px;
            animation: fadeUp 0.5s cubic-bezier(0.4,0,0.2,1) 0.25s both;
        }

        table { width: 100%; border-collapse: collapse; }

        thead tr {
            background: rgba(200,169,110,0.07);
            border-bottom: 1px solid var(--gold-border);
        }

        thead th {
            padding: 14px 20px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--gold-main);
            text-align: left;
        }

        tbody tr {
            border-bottom: 1px solid rgba(255,255,255,0.04);
            transition: var(--transition);
        }

        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: var(--bg-panel-hover); }

        td {
            padding: 14px 20px;
            font-size: 14px;
            color: var(--text-soft);
            vertical-align: middle;
        }

        td .cell-num {
            font-family: 'Cormorant Garamond', serif;
            font-size: 16px;
            font-weight: 500;
            color: var(--text-primary);
        }

        td .cell-user {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        td .cell-user .mini-avatar {
            width: 24px; height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--gold-dark), var(--gold-main));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Cormorant Garamond', serif;
            font-size: 11px;
            font-weight: 600;
            color: var(--bg-base);
            flex-shrink: 0;
        }

        /* ── Status Badges ───────────────────────────────────────────────────── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .badge::before {
            content: '';
            width: 5px; height: 5px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .badge-available {
            background: var(--green-bg);
            border: 1px solid rgba(62,207,142,0.25);
            color: var(--green);
        }

        .badge-available::before { background: var(--green); }

        .badge-allocated {
            background: var(--red-bg);
            border: 1px solid rgba(224,92,92,0.25);
            color: var(--red);
        }

        .badge-allocated::before { background: var(--red); }

        /* ── Action Button ───────────────────────────────────────────────────── */
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-decoration: none;
            background: linear-gradient(135deg, var(--gold-dark), var(--gold-main));
            color: var(--bg-base);
            border: none;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 2px 12px rgba(200,169,110,0.2);
        }

        .action-btn svg { width: 12px; height: 12px; stroke: var(--bg-base); }

        .action-btn:hover {
            background: linear-gradient(135deg, var(--gold-main), var(--gold-light));
            box-shadow: 0 4px 20px rgba(200,169,110,0.35);
            transform: translateY(-1px);
        }

        .action-btn:active { transform: translateY(0); }

        /* ── Flash Message ───────────────────────────────────────────────────── */
        .flash {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border-radius: var(--radius-md);
            font-size: 14px;
            margin-bottom: 28px;
            background: rgba(62,207,142,0.1);
            border: 1px solid rgba(62,207,142,0.25);
            color: var(--green);
            animation: flashIn 0.4s ease both, flashOut 0.5s ease 3s both;
        }

        .flash svg { width: 16px; height: 16px; flex-shrink: 0; }

        @keyframes flashIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes flashOut {
            from { opacity: 1; transform: translateY(0); }
            to   { opacity: 0; transform: translateY(-8px); height: 0; margin: 0; padding: 0; overflow: hidden; }
        }

        /* ── Mobile Sidebar Toggle ───────────────────────────────────────────── */
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
        }

        @media (max-width: 600px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .topbar-meta .date-chip { display: none; }
        }

        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        /* ── Modal ───────────────────────────────────── */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10,14,20,0.75);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999;
            opacity: 0;
            pointer-events: none;
            transition: all 0.25s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .modal {
            width: 100%;
            max-width: 420px;
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--gold-border);
            border-radius: 18px;
            padding: 28px 26px;
            backdrop-filter: blur(18px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            transform: scale(0.92);
            opacity: 0;
            transition: all 0.25s cubic-bezier(0.34,1.56,0.64,1);
        }

        .modal-overlay.active .modal {
            transform: scale(1);
            opacity: 1;
        }

        .modal h3 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 22px;
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .modal p {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .modal-btn {
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            border: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-btn.cancel {
            background: rgba(255,255,255,0.08);
            color: var(--text-muted);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .modal-btn.cancel:hover {
            background: rgba(255,255,255,0.15);
        }

        .modal-btn.confirm {
            background: linear-gradient(135deg, var(--gold-dark), var(--gold-main));
            color: var(--bg-base);
            box-shadow: 0 4px 18px rgba(200,169,110,0.3);
        }

        .modal-btn.confirm:hover {
            background: linear-gradient(135deg, var(--gold-main), var(--gold-light));
        }
    </style>
</head>
<body>

<!-- ── Mobile toggle ─────────────────────────────────────────────────────────── -->
<button class="mobile-toggle" id="menuToggle" aria-label="Toggle sidebar">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round">
        <line x1="3" y1="7"  x2="21" y2="7"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="17" x2="21" y2="17"/>
    </svg>
</button>

<!-- ════════════════════════════════════════════════════════════════════════════ -->
<!-- SIDEBAR                                                                      -->
<!-- ════════════════════════════════════════════════════════════════════════════ -->
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

        <a href="#overview" class="nav-item active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <rect x="3"  y="3"  width="7" height="7"/>
                <rect x="14" y="3"  width="7" height="7"/>
                <rect x="14" y="14" width="7" height="7"/>
                <rect x="3"  y="14" width="7" height="7"/>
            </svg>
            Overview
        </a>

        <a href="#seats" class="nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 9V5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v4"/>
                <path d="M4 9h16a1 1 0 0 1 1 1v2H3v-2a1 1 0 0 1 1-1z"/>
                <path d="M5 12v4"/><path d="M19 12v4"/>
                <path d="M4 16h16"/>
            </svg>
            Seat Allocation
        </a>

        <a href="#computers" class="nav-item">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="3" width="20" height="14" rx="2"/>
                <path d="M8 21h8"/><path d="M12 17v4"/>
            </svg>
            Computer Allocation
        </a>

    </nav>

    <!-- User card -->
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

<!-- ════════════════════════════════════════════════════════════════════════════ -->
<!-- MAIN                                                                         -->
<!-- ════════════════════════════════════════════════════════════════════════════ -->
<div class="main-wrap">

    <!-- Top bar -->
    <header class="topbar">
        <div class="topbar-title">
            Staff <span>Dashboard</span>
        </div>
        <div class="topbar-meta">
            <span class="date-chip" id="live-date"></span>
            <span class="role-badge"><?= htmlspecialchars($_SESSION['role']) ?></span>
        </div>
    </header>

    <!-- Page content -->
    <main class="page-content">

        <!-- Flash message -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="flash" id="flash-msg">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <?= htmlspecialchars($_SESSION['message']) ?>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <!-- ── Overview ──────────────────────────────────────────────────────── -->
        <section id="overview">
            <div class="section-header">
                <h2 class="section-title">Overview</h2>
                <div class="section-line"></div>
                <span class="section-count">Live snapshot</span>
            </div>

            <div class="stats-grid">

                <!-- Total Seats -->
                <div class="stat-card">
                    <div class="stat-icon gold">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 9V5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v4"/>
                            <path d="M4 9h16a1 1 0 0 1 1 1v2H3v-2a1 1 0 0 1 1-1z"/>
                            <path d="M5 12v4"/><path d="M19 12v4"/><path d="M4 16h16"/>
                        </svg>
                    </div>
                    <div class="stat-value"><?= $totalSeats ?></div>
                    <div class="stat-label">Total Seats</div>
                    <div class="stat-sub">
                        <strong><?= $availableSeats ?></strong> available ·
                        <strong><?= $allocatedSeats ?></strong> allocated
                    </div>
                </div>

                <!-- Available Seats -->
                <div class="stat-card">
                    <div class="stat-icon green">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                    </div>
                    <div class="stat-value"><?= $availableSeats ?></div>
                    <div class="stat-label">Available Seats</div>
                    <div class="stat-sub">
                        <strong><?= ($totalSeats > 0 ? round(($availableSeats / $totalSeats) * 100) : 0) ?>%</strong>
                        seat availability
                    </div>
                </div>

                <!-- Total Computers -->
                <div class="stat-card">
                    <div class="stat-icon amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="3" width="20" height="14" rx="2"/>
                            <path d="M8 21h8"/><path d="M12 17v4"/>
                        </svg>
                    </div>
                    <div class="stat-value"><?= $totalComputers ?></div>
                    <div class="stat-label">Total Computers</div>
                    <div class="stat-sub">
                        <strong><?= $availableComputers ?></strong> available ·
                        <strong><?= $allocatedComputers ?></strong> allocated
                    </div>
                </div>

                <!-- Total Allocated -->
                <div class="stat-card">
                    <div class="stat-icon red">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8"
                             stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                        </svg>
                    </div>
                    <div class="stat-value"><?= $allocatedSeats + $allocatedComputers ?></div>
                    <div class="stat-label">Total Allocated</div>
                    <div class="stat-sub">
                        <strong><?= $allocatedSeats ?></strong> seats ·
                        <strong><?= $allocatedComputers ?></strong> computers
                    </div>
                </div>

            </div>
        </section>

        <!-- ── Seat Allocation Table ──────────────────────────────────────────── -->
        <section id="seats">
            <div class="section-header">
                <h2 class="section-title">Seat Allocation</h2>
                <div class="section-line"></div>
                <span class="section-count"><?= count($seatsData) ?> records</span>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Seat #</th>
                            <th>Status</th>
                            <th>Allocated To</th>
                            <?php if ($isLibrarian): ?><th>Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($seatsData as $seat):
                            $isAllocated = ($seat['status'] === 'reserved');
                            $badgeClass  = $isAllocated ? 'badge-allocated' : 'badge-available';
                            $badgeLabel  = $isAllocated ? 'Allocated' : 'Available';
                        ?>
                        <tr>
                            <td>
                                <span class="cell-num"><?= htmlspecialchars($seat['seat_number']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                            </td>
                            <td>
                                <?php if ($seat['username']): ?>
                                    <div class="cell-user">
                                        <div class="mini-avatar">
                                            <?= strtoupper(substr(htmlspecialchars($seat['username']), 0, 1)) ?>
                                        </div>
                                        <?= htmlspecialchars($seat['username']) ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--text-muted)">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($isLibrarian): ?>
                            <td>
                                <?php if ($isAllocated): ?>
                                    <a href="#"
                                        class="action-btn open-modal"
                                        data-url="free_item.php?type=seat&id=<?= (int)$seat['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="3 6 5 6 21 6"/>
                                            <path d="M19 6l-1 14H6L5 6"/>
                                            <path d="M10 11v6"/><path d="M14 11v6"/>
                                            <path d="M9 6V4h6v2"/>
                                        </svg>
                                        Remove Allocation
                                    </a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($seatsData)): ?>
                        <tr>
                            <td colspan="<?= $isLibrarian ? 4 : 3 ?>"
                                style="text-align:center; padding:32px; color:var(--text-muted); font-style:italic;">
                                No seat records found.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ── Computer Allocation Table ─────────────────────────────────────── -->
        <section id="computers">
            <div class="section-header">
                <h2 class="section-title">Computer Allocation</h2>
                <div class="section-line"></div>
                <span class="section-count"><?= count($computersData) ?> records</span>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Computer #</th>
                            <th>Status</th>
                            <th>Allocated To</th>
                            <?php if ($isLibrarian): ?><th>Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($computersData as $comp):
                            $isAllocated = ($comp['status'] === 'reserved');
                            $badgeClass  = $isAllocated ? 'badge-allocated' : 'badge-available';
                            $badgeLabel  = $isAllocated ? 'Allocated' : 'Available';
                        ?>
                        <tr>
                            <td>
                                <span class="cell-num"><?= htmlspecialchars($comp['computer_number']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                            </td>
                            <td>
                                <?php if ($comp['username']): ?>
                                    <div class="cell-user">
                                        <div class="mini-avatar">
                                            <?= strtoupper(substr(htmlspecialchars($comp['username']), 0, 1)) ?>
                                        </div>
                                        <?= htmlspecialchars($comp['username']) ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--text-muted)">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($isLibrarian): ?>
                            <td>
                                <?php if ($isAllocated): ?>
                                    <a href="#"
                                     class="action-btn open-modal"
                                        data-url="free_item.php?type=computer&id=<?= (int)$comp['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="3 6 5 6 21 6"/>
                                            <path d="M19 6l-1 14H6L5 6"/>
                                            <path d="M10 11v6"/><path d="M14 11v6"/>
                                            <path d="M9 6V4h6v2"/>
                                        </svg>
                                        Remove Allocation
                                    </a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($computersData)): ?>
                        <tr>
                            <td colspan="<?= $isLibrarian ? 4 : 3 ?>"
                                style="text-align:center; padding:32px; color:var(--text-muted); font-style:italic;">
                                No computer records found.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<!-- Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <h3>Remove Allocation</h3>
        <p>
            Are you sure you want to remove this allocation?<br>
            This action cannot be undone.
        </p>

        <div class="modal-actions">
            <button class="modal-btn cancel" id="cancelModal">Cancel</button>
            <button class="modal-btn confirm" id="confirmModalBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ── Scripts ──────────────────────────────────────────────────────────────── -->
<script>
    // Live date in top bar
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

    // Smooth scroll on nav click + active state
    const navItems = document.querySelectorAll('.nav-item');

    navItems.forEach(item => {
        item.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (href && href.startsWith('#')) {
                e.preventDefault();
                const target = document.getElementById(href.slice(1));
                if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                navItems.forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                if (window.innerWidth <= 900) sidebar.classList.remove('open');
            }
        });
    });

    // Update active nav on scroll
    const sections = ['overview', 'seats', 'computers'];

    function setActiveNav() {
        let current = 'overview';
        sections.forEach(id => {
            const el = document.getElementById(id);
            if (el && window.scrollY >= el.offsetTop - 120) current = id;
        });
        navItems.forEach(item => {
            item.classList.toggle('active', item.getAttribute('href') === '#' + current);
        });
    }

    window.addEventListener('scroll', setActiveNav, { passive: true });
    setActiveNav();

    // ── Modal Logic ───────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        const modal      = document.getElementById('confirmModal');
        const cancelBtn  = document.getElementById('cancelModal');
        const confirmBtn = document.getElementById('confirmModalBtn');

        let actionUrl = null;

        document.querySelectorAll('.open-modal').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                actionUrl = this.dataset.url;
                modal.classList.add('active');
            });
        });

        function closeModal() {
            modal.classList.remove('active');
            actionUrl = null;
        }

        cancelBtn.addEventListener('click', closeModal);

        confirmBtn.addEventListener('click', () => {
            if (actionUrl) {
                window.location.href = actionUrl;
            }
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });
    });
</script>

</body>
</html>