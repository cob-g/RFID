<?php
declare(strict_types=1);
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();      // ← Must be here: protects header() from any accidental output

session_start(); // ← Always first real statement after declare + ob_start

include __DIR__ . '/db.php';

function rfidLinkTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->bind_result($one);
    $exists = $stmt->fetch();
    $stmt->close();

    return (bool) $exists;
}

function rfidLinkColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->bind_result($one);
    $exists = $stmt->fetch();
    $stmt->close();

    return (bool) $exists;
}

// ── Session guard ─────────────────────────────────────────────────────────────
if (empty($_SESSION['rfid_pending_user_id'])) {
    session_write_close();
    header('Location: register.php');
    exit;
}

$userId   = (int)    $_SESSION['rfid_pending_user_id'];
$username = (string) ($_SESSION['rfid_pending_username'] ?? 'Student');

// ── Load current user status ──────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT status FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // User was deleted between verify and link — start over
    unset($_SESSION['rfid_pending_user_id'], $_SESSION['rfid_pending_username']);
    session_write_close();
    header('Location: register.php');
    exit;
}

// ── Status checks: 'active' BEFORE 'verified' ─────────────────────────────────
// Checking 'verified' first would also catch 'active', making the active branch
// unreachable. Always check the most specific condition first.
if ($user['status'] === 'active') {
    unset($_SESSION['rfid_pending_user_id'], $_SESSION['rfid_pending_username']);
    session_write_close();
    header('Location: index.php?registered=1');
    exit;
}

if ($user['status'] !== 'verified') {
    // Still 'pending' — email not confirmed yet
    session_write_close();
    header('Location: verify.php');
    exit;
}

// ── Status is 'verified': generate enrollment token ──────────────────────────
$hasEnrollmentTokens = rfidLinkTableExists($conn, 'enrollment_tokens');
$hasPendingAssignments = rfidLinkTableExists($conn, 'pending_rfid_assignments');

if (!$hasEnrollmentTokens && !$hasPendingAssignments) {
    error_log('[rfid_link] No enrollment token table found');
    http_response_code(503);
    echo '<!DOCTYPE html><html><body>'
       . '<h2 style="font-family:sans-serif;color:#c00">Service Unavailable</h2>'
       . '<p style="font-family:sans-serif">RFID enrollment is temporarily unavailable. '
       . 'Please try again shortly.</p>'
       . '</body></html>';
    exit;
}

// 120-second window — more reliable on slow shared hosting than 60 s
define('ENROLL_TTL', 120);

$token     = bin2hex(random_bytes(32));  // 64 hex chars, cryptographically random
$expiresAt = date('Y-m-d H:i:s', time() + ENROLL_TTL);

if ($hasEnrollmentTokens) {
    // New schema flow: tokens keyed by user_id when supported.
    $hasTokenUserId = rfidLinkColumnExists($conn, 'enrollment_tokens', 'user_id');
    if ($hasTokenUserId) {
        $del = $conn->prepare("DELETE FROM enrollment_tokens WHERE user_id = ?");
        $del->bind_param('i', $userId);
    } else {
        $del = $conn->prepare("DELETE FROM enrollment_tokens WHERE user_name = ?");
        $del->bind_param('s', $username);
    }
    $del->execute();
    $del->close();

    $hasStatus = rfidLinkColumnExists($conn, 'enrollment_tokens', 'status');
    if ($hasTokenUserId && $hasStatus) {
        $ins = $conn->prepare(
            "INSERT INTO enrollment_tokens (user_id, user_name, token, expires_at, status) VALUES (?, ?, ?, ?, 'pending')"
        );
        $ins->bind_param('isss', $userId, $username, $token, $expiresAt);
    } elseif ($hasTokenUserId) {
        $ins = $conn->prepare(
            "INSERT INTO enrollment_tokens (user_id, user_name, token, expires_at) VALUES (?, ?, ?, ?)"
        );
        $ins->bind_param('isss', $userId, $username, $token, $expiresAt);
    } elseif ($hasStatus) {
        $ins = $conn->prepare(
            "INSERT INTO enrollment_tokens (user_name, token, expires_at, status) VALUES (?, ?, ?, 'pending')"
        );
        $ins->bind_param('sss', $username, $token, $expiresAt);
    } else {
        $ins = $conn->prepare(
            "INSERT INTO enrollment_tokens (user_name, token, expires_at) VALUES (?, ?, ?)"
        );
        $ins->bind_param('sss', $username, $token, $expiresAt);
    }
    $ins->execute();
    $ins->close();
} else {
    // Legacy schema flow: tokens keyed by user_id.
    $del = $conn->prepare("DELETE FROM pending_rfid_assignments WHERE user_id = ?");
    $del->bind_param('i', $userId);
    $del->execute();
    $del->close();

    $hasStatus = rfidLinkColumnExists($conn, 'pending_rfid_assignments', 'status');
    $hasFulfilled = rfidLinkColumnExists($conn, 'pending_rfid_assignments', 'fulfilled');

    if ($hasStatus && $hasFulfilled) {
        $ins = $conn->prepare(
            "INSERT INTO pending_rfid_assignments (user_id, token, expires_at, status, fulfilled) VALUES (?, ?, ?, 'pending', 0)"
        );
    } elseif ($hasStatus) {
        $ins = $conn->prepare(
            "INSERT INTO pending_rfid_assignments (user_id, token, expires_at, status) VALUES (?, ?, ?, 'pending')"
        );
    } elseif ($hasFulfilled) {
        $ins = $conn->prepare(
            "INSERT INTO pending_rfid_assignments (user_id, token, expires_at, fulfilled) VALUES (?, ?, ?, 0)"
        );
    } else {
        $ins = $conn->prepare(
            "INSERT INTO pending_rfid_assignments (user_id, token, expires_at) VALUES (?, ?, ?)"
        );
    }
    $ins->bind_param('iss', $userId, $token, $expiresAt);
    $ins->execute();
    $ins->close();
}

// Session is no longer needed for this page — release the lock so other
// requests from the same browser (e.g. the poll) are not blocked.
session_write_close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link RFID Card — St. Clare College of Caloocan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --gold:         #c8a96e;
            --gold-light:   #e2c99a;
            --dark:         #1a1f27;
            --input-bg:     rgba(255,255,255,0.09);
            --input-border: rgba(200,169,110,0.35);
            --text-main:    #f0ece4;
            --text-muted:   rgba(240,236,228,0.55);
            --error:        #f28b8b;
            --success:      #7ec8a0;
            --transition:   0.3s cubic-bezier(0.4,0,0.2,1);
        }
        html, body { height: 100%; width: 100%; }
        body { font-family: 'DM Sans', sans-serif; background-color: var(--dark); overflow: hidden; }
        .bg-layer {
            position: fixed; inset: 0; background-image: url('bg.jpg');
            background-size: cover; background-position: center;
            z-index: 0; filter: brightness(1.1);
        }
        .overlay {
            position: fixed; inset: 0;
            background: linear-gradient(
                to right, rgba(10,14,20,0.97) 0%, rgba(10,14,20,0.88) 30%,
                rgba(10,14,20,0.55) 58%, rgba(10,14,20,0.18) 80%, rgba(10,14,20,0.05) 100%
            );
            z-index: 1;
        }
        .page { position: relative; z-index: 2; display: flex; align-items: stretch; height: 100vh; width: 100%; }
        .brand-panel { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 60px 64px; max-width: 560px; }
        .brand-tag {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 11px; letter-spacing: 3px; text-transform: uppercase;
            color: var(--gold); margin-bottom: 36px;
            opacity: 0; animation: fadeUp 0.7s 0.1s ease forwards;
        }
        .brand-tag::before { content: ''; display: block; width: 28px; height: 1px; background: var(--gold); }
        .brand-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(38px,5vw,62px); font-weight: 300;
            color: var(--text-main); line-height: 1.12;
            opacity: 0; animation: fadeUp 0.7s 0.25s ease forwards;
        }
        .brand-title em { font-style: italic; color: var(--gold-light); }
        .brand-sub {
            margin-top: 20px; font-size: 14px; line-height: 1.75;
            color: var(--text-muted); max-width: 340px;
            opacity: 0; animation: fadeUp 0.7s 0.4s ease forwards;
        }
        .divider {
            width: 48px; height: 1px; background: linear-gradient(to right, var(--gold), transparent);
            margin: 32px 0; opacity: 0; animation: fadeUp 0.7s 0.55s ease forwards;
        }
        .brand-meta {
            font-size: 12px; letter-spacing: 1px; color: var(--text-muted);
            text-transform: uppercase; opacity: 0; animation: fadeUp 0.7s 0.65s ease forwards;
        }
        .form-panel {
            width: 440px; min-width: 340px;
            display: flex; align-items: center; justify-content: center;
            padding: 48px 24px; margin-right: 5vw;
        }
        .form-card {
            width: 100%; max-width: 400px; background: rgba(255,255,255,0.06);
            backdrop-filter: blur(24px) saturate(160%); -webkit-backdrop-filter: blur(24px) saturate(160%);
            border: 1px solid rgba(200,169,110,0.18); border-radius: 22px; padding: 40px 40px 36px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.45), 0 1px 0 rgba(255,255,255,0.06) inset;
            opacity: 0; transform: translateY(24px);
            animation: cardIn 0.75s 0.3s cubic-bezier(0.34,1.56,0.64,1) forwards;
        }
        .logo-section { display: flex; flex-direction: column; align-items: center; margin-bottom: 20px; }
        .logo-ring {
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(200,169,110,0.1); border: 1.5px solid rgba(200,169,110,0.35);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px; box-shadow: 0 0 0 6px rgba(200,169,110,0.06);
        }
        .logo-ring img { width: 50px; height: 50px; object-fit: contain; }
        .school-name {
            font-family: 'Cormorant Garamond', serif; font-size: 15px;
            font-weight: 600; color: var(--gold-light); letter-spacing: 0.5px; text-align: center;
        }
        .tap-zone { display: flex; flex-direction: column; align-items: center; margin: 20px 0; }
        .tap-ring-outer {
            width: 96px; height: 96px; border-radius: 50%;
            border: 2px solid rgba(200,169,110,0.25);
            display: flex; align-items: center; justify-content: center;
            animation: pulseRing 2s ease-in-out infinite;
        }
        .tap-ring-inner {
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(200,169,110,0.1); border: 1.5px solid rgba(200,169,110,0.4);
            display: flex; align-items: center; justify-content: center; font-size: 34px;
        }
        .tap-ring-outer.success-ring { animation: none; border-color: rgba(126,200,160,0.6); }
        .tap-ring-inner.success-inner { background: rgba(126,200,160,0.15); border-color: rgba(126,200,160,0.5); }
        .tap-ring-outer.error-ring   { animation: none; border-color: rgba(242,139,139,0.5); }
        @keyframes pulseRing {
            0%, 100% { transform: scale(1);    opacity: 0.7; }
            50%      { transform: scale(1.08); opacity: 1; }
        }
        .form-heading {
            font-family: 'Cormorant Garamond', serif; font-size: 26px;
            font-weight: 300; color: var(--text-main); text-align: center; margin-bottom: 4px;
        }
        .form-subheading { font-size: 12.5px; color: var(--text-muted); text-align: center; margin-bottom: 8px; letter-spacing: 0.3px; }
        .countdown-wrap { margin: 16px 0 0; text-align: center; }
        .countdown-bar-bg { width: 100%; height: 4px; border-radius: 2px; background: rgba(200,169,110,0.15); margin-bottom: 8px; }
        .countdown-bar { height: 4px; border-radius: 2px; background: linear-gradient(to right, #c8a96e, #a07840); transition: width 1s linear; }
        .countdown-text { font-size: 12px; color: var(--text-muted); }
        .countdown-text span { color: var(--gold); font-weight: 500; }
        .status-box {
            display: flex; align-items: center; gap: 10px;
            font-size: 13px; padding: 12px 16px; border-radius: 10px; margin-top: 16px;
            background: rgba(200,169,110,0.08); border: 1px solid rgba(200,169,110,0.2); color: var(--text-muted);
        }
        .status-box.success { background: rgba(126,200,160,0.12); border-color: rgba(126,200,160,0.3); color: var(--success); }
        .status-box.error   { background: rgba(242,139,139,0.12); border-color: rgba(242,139,139,0.28); color: var(--error); }
        .spinner { display: inline-flex; gap: 4px; align-items: center; }
        .spinner span {
            width: 5px; height: 5px; border-radius: 50%;
            background: var(--gold); opacity: 0.4; animation: bounce 1.2s ease-in-out infinite;
        }
        .spinner span:nth-child(2) { animation-delay: 0.2s; }
        .spinner span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes bounce {
            0%,80%,100% { transform: scale(1);   opacity: 0.4; }
            40%         { transform: scale(1.3); opacity: 1; }
        }
        .btn-retry {
            width: 100%; padding: 13px; margin-top: 14px; border: none; border-radius: 10px;
            background: linear-gradient(135deg,#c8a96e 0%,#a07840 100%);
            color: #1a1208; font-family: 'DM Sans', sans-serif;
            font-size: 14px; font-weight: 500; letter-spacing: 1px; text-transform: uppercase; cursor: pointer;
            box-shadow: 0 4px 20px rgba(200,169,110,0.3); display: none;
            transition: transform var(--transition), filter var(--transition);
        }
        .btn-retry:hover { transform: translateY(-2px); filter: brightness(1.08); }
        .bottom-link { text-align: center; margin-top: 20px; font-size: 13px; color: var(--text-muted); }
        .bottom-link a {
            color: var(--gold-light); text-decoration: none; font-weight: 500;
            border-bottom: 1px solid transparent;
            transition: border-color var(--transition), color var(--transition);
        }
        .bottom-link a:hover { color: var(--gold); border-bottom-color: var(--gold); }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes cardIn { to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 860px) {
            body { overflow: auto; }
            .overlay { background: linear-gradient(to bottom, rgba(10,14,20,0.85) 0%, rgba(10,14,20,0.60) 100%); }
            .page { flex-direction: column; justify-content: flex-start; align-items: center; height: auto; min-height: 100vh; padding: 40px 20px 48px; }
            .brand-panel { max-width: 100%; padding: 0; text-align: center; margin-bottom: 32px; }
            .brand-tag { justify-content: center; }
            .brand-tag::before { display: none; }
            .brand-sub { max-width: 100%; }
            .form-panel { width: 100%; max-width: 440px; margin-right: 0; padding: 0; }
            .form-card { padding: 34px 28px 30px; }
        }
    </style>
</head>

<body>
    <div class="bg-layer"></div>
    <div class="overlay"></div>

    <div class="page">

        <div class="brand-panel">
            <span class="brand-tag">Library Management System</span>
            <h1 class="brand-title">One Last<br><em>Step</em></h1>
            <p class="brand-sub">
                Your email has been confirmed. Now link your RFID card
                to complete registration — simply tap it on the reader.
            </p>
            <div class="divider"></div>
            <p class="brand-meta">St. Clare College of Caloocan &nbsp;·&nbsp; Est. 1969</p>
        </div>

        <div class="form-panel">
            <div class="form-card">

                <div class="logo-section">
                    <div class="logo-ring">
                        <img src="logo.png" alt="St. Clare College of Caloocan Logo">
                    </div>
                    <p class="school-name">St. Clare College of Caloocan</p>
                </div>

                <h2 class="form-heading">Link Your RFID Card</h2>
                <p class="form-subheading">
                    Hello, <strong style="color:var(--gold-light)"><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></strong>
                </p>

                <div class="tap-zone">
                    <div class="tap-ring-outer" id="tapRingOuter">
                        <div class="tap-ring-inner" id="tapRingInner">📡</div>
                    </div>
                </div>

                <div class="countdown-wrap" id="countdownWrap">
                    <div class="countdown-bar-bg">
                        <div class="countdown-bar" id="countdownBar" style="width:100%"></div>
                    </div>
                    <p class="countdown-text">
                        Tap your card within <span id="countdownSec"><?= ENROLL_TTL ?></span> seconds
                    </p>
                </div>

                <div class="status-box" id="statusBox">
                    <span class="spinner"><span></span><span></span><span></span></span>
                    Waiting for card tap on the library reader…
                </div>

                <button class="btn-retry" id="retryBtn" onclick="location.reload()">
                    Try Again
                </button>

                <p class="bottom-link">
                    Having trouble? <a href="index.php">Skip for now</a>
                </p>

            </div>
        </div>

    </div>

    <script>
        const TOKEN      = '<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>';
        const EXPIRES_IN = <?= ENROLL_TTL ?>;

        let secondsLeft       = EXPIRES_IN;
        let pollInterval      = null;
        let countdownInterval = null;
        let done              = false;

        const bar       = document.getElementById('countdownBar');
        const secLabel  = document.getElementById('countdownSec');
        const statusBox = document.getElementById('statusBox');
        const retryBtn  = document.getElementById('retryBtn');
        const outerRing = document.getElementById('tapRingOuter');
        const innerRing = document.getElementById('tapRingInner');

        // ── Countdown ─────────────────────────────────────────────────────
        countdownInterval = setInterval(() => {
            if (done) return;
            secondsLeft--;
            secLabel.textContent = Math.max(0, secondsLeft);
            bar.style.width      = Math.max(0, (secondsLeft / EXPIRES_IN) * 100) + '%';
            if (secondsLeft <= 0) {
                clearInterval(countdownInterval);
                clearInterval(pollInterval);
                setError('Time expired. Click "Try Again" to get a new window.');
            }
        }, 1000);

        // ── Poll for enrollment status ────────────────────────────────────
        pollInterval = setInterval(async () => {
            if (done || secondsLeft <= 0) return;
            try {
                const res = await fetch(
                    `enroll_api.php?action=check&token=${encodeURIComponent(TOKEN)}`
                );
                if (!res.ok) return;   // transient error — retry next tick
                const data = await res.json();

                if (data.status === 'fulfilled') {
                    done = true;
                    clearInterval(pollInterval);
                    clearInterval(countdownInterval);
                    setSuccess('Card linked! Redirecting to login…');
                    setTimeout(() => { window.location.href = 'index.php?registered=1'; }, 2500);

                } else if (data.status === 'duplicate') {
                    done = true;
                    clearInterval(pollInterval);
                    clearInterval(countdownInterval);
                    setError('This RFID card is already assigned to another account.');

                } else if (data.status === 'expired') {
                    done = true;
                    clearInterval(pollInterval);
                    clearInterval(countdownInterval);
                    setError('Session expired. Click "Try Again" to start a new window.');
                }
                // 'pending' → keep polling

            } catch (_) { /* network error — next tick retries */ }
        }, 2000);

        function setSuccess(msg) {
            statusBox.className   = 'status-box success';
            statusBox.textContent = '✓  ' + msg;
            outerRing.classList.add('success-ring');
            innerRing.classList.add('success-inner');
            innerRing.textContent = '✓';
            document.getElementById('countdownWrap').style.display = 'none';
        }

        function setError(msg) {
            statusBox.className  = 'status-box error';
            statusBox.textContent = '⚠  ' + msg;
            outerRing.classList.add('error-ring');
            retryBtn.style.display = 'block';
            document.getElementById('countdownWrap').style.display = 'none';
        }
    </script>

</body>
</html>