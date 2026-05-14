<?php
declare(strict_types=1);
ob_start();
session_start();

include __DIR__ . '/db.php';
require __DIR__ . '/email_helper.php';

define('VERIFY_DEBUG', false); // set true temporarily if you need to diagnose

$message      = '';
$messageType  = 'error';

// Read email from GET (normal load or post-resend redirect) OR POST (failed verify attempt)
$prefillEmail = htmlspecialchars(
    trim($_GET['email'] ?? $_POST['email'] ?? ''),
    ENT_QUOTES, 'UTF-8'
);

// Show resent success banner when redirected back from a successful resend
if (isset($_GET['resent'])) {
    $message     = 'A new verification code has been sent to your email.';
    $messageType = 'success';
}

// ── RESEND VERIFICATION CODE (checked first to avoid conflict with verify POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {

    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email to resend the code.';
    } else {

        $stmt = $conn->prepare("
            SELECT id, username, status
            FROM users
            WHERE LOWER(TRIM(email)) = ?
            LIMIT 1
        ");
        $emailNorm = strtolower(trim($email));
        $stmt->bind_param('s', $emailNorm);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $message = 'No account found with that email.';
        } elseif ($user['status'] === 'active') {
            session_write_close();
            header('Location: index.php?verified=1');
            exit;
        } else {

            // Generate new 6-digit code
            $newCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $upd = $conn->prepare("
                UPDATE users
                SET verification_code = ?, status = 'pending'
                WHERE id = ?
            ");
            $upd->bind_param('si', $newCode, $user['id']);
            $upd->execute();
            $upd->close();

            // Send verification email immediately via PHPMailer
            // (do NOT queue — user needs this code right now)
            sendVerificationEmail($email, $user['username'], $newCode);

            // PRG: redirect back so page refresh won't re-POST and re-send the code.
            // The ?resent=1 flag triggers the success banner; email stays pre-filled.
            session_write_close();
            header('Location: verify.php?email=' . urlencode($email) . '&resent=1');
            exit;
        }
    }

// ── VERIFY CODE ───────────────────────────────────────────────────────────────
// Check POST method only — not the button name.
// form.submit() called from JavaScript never sends the button's name/value pair.
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $code  = trim($_POST['code']  ?? '');

    if (VERIFY_DEBUG) {
        error_log('[verify.php] POST — email=' . $email . ' code=' . $code);
    }

    if ($email === '' || $code === '') {
        $message = 'Email and verification code are required.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';

    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $message = 'Code must be exactly 6 digits.';

    } else {

        $stmt = $conn->prepare("
            SELECT id, username, role, status, verification_code
            FROM   users
            WHERE  LOWER(TRIM(email)) = ?
            LIMIT  1
        ");
        $emailNorm = strtolower(trim($email));
        $stmt->bind_param('s', $emailNorm);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (VERIFY_DEBUG) {
            error_log('[verify.php] DB row: ' . print_r($user, true));
        }

        if (!$user) {
            $message = 'No account found with that email address.';

        } elseif ($user['status'] === 'active') {
            session_write_close();
            header('Location: index.php?verified=1');
            exit;

        } else {

            $storedCode = $user['verification_code'];

            if (VERIFY_DEBUG) {
                error_log('[verify.php] storedCode=' . var_export($storedCode, true)
                          . ' type=' . gettype($storedCode)
                          . ' | input=' . $code);
            }

            // ── Code is NULL: already used, or was never stored ───────────────
            if ($storedCode === null || $storedCode === '') {

                $message = 'Verification code has already been used or was never sent. '
                         . 'Please use the Resend Code button below.';

            // ── Wrong code ────────────────────────────────────────────────────
            } elseif ((string) $storedCode !== (string) $code) {
                $message = 'Incorrect verification code. Please check your email.';

            // ── Correct code ──────────────────────────────────────────────────
            } else {
                // WHERE status = 'pending' prevents a race condition where two
                // simultaneous POST requests both try to verify the same account.
                $upd = $conn->prepare("
                        UPDATE users
                        SET    status            = 'active',
                               verification_code = NULL
                        WHERE  id     = ?
                          AND  status = 'pending'
                ");
                $upd->bind_param('i', $user['id']);
                $upd->execute();
                    $updatedRows = $upd->affected_rows;
                    $upd->close();

                    if ($updatedRows > 0) {
                        auditLogWrite($conn, [
                            'actor_user_id' => (int) $user['id'],
                            'actor_username' => (string) ($user['username'] ?? ''),
                            'actor_role' => (string) ($user['role'] ?? ''),
                            'action' => 'email_verified',
                            'target_type' => 'user',
                            'target_id' => (int) $user['id'],
                            'target_label' => (string) ($user['username'] ?? ''),
                            'details' => [
                                'email' => $email,
                            ],
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                        ]);
                    }

                    if (VERIFY_DEBUG) {
                        error_log('[verify.php] UPDATE affected_rows=' . $updatedRows);
                    }

                if (VERIFY_DEBUG) {
                    error_log('[verify.php] UPDATE affected_rows=' . $upd->affected_rows);
                }

                session_write_close();
                header('Location: index.php?verified=1');
                exit;
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
    <title>Verify Email — St. Clare College of Caloocan</title>
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
        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--dark);
            overflow-y: auto;
            overflow-x: hidden;
        }
        .bg-layer {
            position: fixed; inset: 0; background-image: url('bg.jpg');
            background-size: cover; background-position: center;
            z-index: 0; filter: brightness(1.1);
        }
        .overlay {
            position: fixed; inset: 0;
            background: linear-gradient(
                to right, rgba(10,14,20,0.97) 0%, rgba(10,14,20,0.88) 30%,
                rgba(10,14,20,0.55) 58%, rgba(10,14,20,0.18) 80%,
                rgba(10,14,20,0.05) 100%
            );
            z-index: 1;
        }
        .page {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            width: 100%;
            padding: 48px 24px;
        }

        .page-inner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 48px;
            width: min(1120px, 100%);
        }

        .brand-panel {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 64px;
            max-width: 520px;
            align-self: center;
        }
        .brand-tag {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 11px; letter-spacing: 3px; text-transform: uppercase;
            color: var(--gold); margin-bottom: 36px;
            opacity: 0; animation: fadeUp 0.7s 0.1s ease forwards;
        }
        .brand-tag::before { content: ''; display: block; width: 28px; height: 1px; background: var(--gold); }
        .brand-title {
            font-family: 'Cormorant Garamond', serif; font-size: clamp(38px,5vw,62px);
            font-weight: 300; color: var(--text-main); line-height: 1.12;
            opacity: 0; animation: fadeUp 0.7s 0.25s ease forwards;
        }
        .brand-title em { font-style: italic; color: var(--gold-light); }
        .brand-sub {
            margin-top: 20px; font-size: 14px; line-height: 1.75; color: var(--text-muted);
            max-width: 340px; opacity: 0; animation: fadeUp 0.7s 0.4s ease forwards;
        }
        .divider {
            width: 48px; height: 1px; background: linear-gradient(to right, var(--gold), transparent);
            margin: 32px 0; opacity: 0; animation: fadeUp 0.7s 0.55s ease forwards;
        }
        .brand-meta {
            font-size: 12px; letter-spacing: 1px; color: var(--text-muted); text-transform: uppercase;
            opacity: 0; animation: fadeUp 0.7s 0.65s ease forwards;
        }
        .form-panel {
            width: 480px;
            min-width: 340px;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 0;
            margin-right: 0;
        }
        .form-card {
            width: 100%; max-width: 420px; background: rgba(255,255,255,0.06);
            backdrop-filter: blur(24px) saturate(160%); -webkit-backdrop-filter: blur(24px) saturate(160%);
            border: 1px solid rgba(200,169,110,0.18); border-radius: 22px; padding: 44px 44px 40px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.45), 0 1px 0 rgba(255,255,255,0.06) inset;
            opacity: 0; transform: translateY(24px);
            animation: cardIn 0.75s 0.3s cubic-bezier(0.34,1.56,0.64,1) forwards;
        }
        .logo-section { display: flex; flex-direction: column; align-items: center; margin-bottom: 22px; }
        .logo-ring {
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(200,169,110,0.1); border: 1.5px solid rgba(200,169,110,0.35);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px; box-shadow: 0 0 0 6px rgba(200,169,110,0.06);
        }
        .logo-ring img { width: 50px; height: 50px; object-fit: contain; filter: drop-shadow(0 2px 6px rgba(200,169,110,0.3)); }
        .school-name { font-family: 'Cormorant Garamond', serif; font-size: 15px; font-weight: 600; color: var(--gold-light); letter-spacing: 0.5px; text-align: center; }
        .envelope-icon { display: flex; align-items: center; justify-content: center; margin-bottom: 14px; }
        .envelope-ring {
            width: 56px; height: 56px; border-radius: 50%; background: rgba(200,169,110,0.1);
            border: 1px solid rgba(200,169,110,0.3); display: flex; align-items: center;
            justify-content: center; font-size: 24px;
        }
        .form-heading { font-family: 'Cormorant Garamond', serif; font-size: 26px; font-weight: 300; color: var(--text-main); text-align: center; margin-bottom: 4px; }
        .form-subheading { font-size: 12.5px; color: var(--text-muted); text-align: center; margin-bottom: 22px; letter-spacing: 0.3px; }
        .message {
            display: flex; align-items: flex-start; gap: 8px; font-size: 13px;
            padding: 11px 14px; border-radius: 9px; margin-bottom: 18px;
            background: rgba(242,139,139,0.12); border: 1px solid rgba(242,139,139,0.28);
            color: var(--error); line-height: 1.5;
        }
        .message.success { background: rgba(126,200,160,0.12); border-color: rgba(126,200,160,0.28); color: var(--success); }
        .message-icon { flex-shrink: 0; font-size: 14px; padding-top: 1px; }
        .debug-box {
            font-family: monospace; font-size: 11px; padding: 10px 12px;
            background: rgba(0,0,0,0.5); border: 1px solid rgba(255,200,0,0.4);
            border-radius: 8px; color: #ffd700; margin-bottom: 14px; white-space: pre-wrap;
        }
        .field { position: relative; margin-bottom: 14px; }
        .field label { display: block; font-size: 11.5px; letter-spacing: 1.2px; text-transform: uppercase; color: var(--gold); margin-bottom: 7px; font-weight: 500; }
        .field input {
            width: 100%; padding: 13px 16px; background: var(--input-bg);
            border: 1px solid var(--input-border); border-radius: 10px; color: var(--text-main);
            font-family: 'DM Sans', sans-serif; font-size: 14.5px; outline: none;
            transition: border-color var(--transition), box-shadow var(--transition), background var(--transition);
        }
        .field input::placeholder { color: rgba(240,236,228,0.3); }
        .field input:focus {
            border-color: var(--gold); background: rgba(255,255,255,0.12);
            box-shadow: 0 0 0 3px rgba(200,169,110,0.15), 0 2px 8px rgba(0,0,0,0.2);
        }
        .code-input { text-align: center !important; letter-spacing: 14px !important; font-size: 22px !important; font-weight: 500 !important; padding-left: 22px !important; }
        .btn-verify {
            width: 100%; padding: 14px; margin-top: 6px; border: none; border-radius: 10px;
            background: linear-gradient(135deg, #c8a96e 0%, #a07840 100%);
            color: #1a1208; font-family: 'DM Sans', sans-serif; font-size: 14px;
            font-weight: 500; letter-spacing: 1px; text-transform: uppercase; cursor: pointer;
            transition: transform var(--transition), box-shadow var(--transition), filter var(--transition);
            box-shadow: 0 4px 20px rgba(200,169,110,0.3);
        }
        .btn-verify:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(200,169,110,0.45); filter: brightness(1.08); }
        .btn-verify:active { transform: translateY(0); box-shadow: 0 3px 12px rgba(200,169,110,0.25); }
        .btn-verify:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* Resend button */
        .btn-resend {
            width: 100%; padding: 11px; margin-top: 10px; border-radius: 10px;
            background: transparent; border: 1px solid rgba(200,169,110,0.4);
            color: var(--gold-light); font-family: 'DM Sans', sans-serif; font-size: 13px;
            font-weight: 500; letter-spacing: 0.8px; text-transform: uppercase; cursor: pointer;
            transition: background var(--transition), border-color var(--transition), color var(--transition);
        }
        .btn-resend:hover { background: rgba(200,169,110,0.1); border-color: var(--gold); color: var(--gold); }
        .btn-resend:active { background: rgba(200,169,110,0.18); }
        .btn-resend:disabled { opacity: 0.5; cursor: not-allowed; }

        .bottom-link { text-align: center; margin-top: 20px; font-size: 13px; color: var(--text-muted); }
        .bottom-link a { color: var(--gold-light); text-decoration: none; font-weight: 500; border-bottom: 1px solid transparent; transition: border-color var(--transition), color var(--transition); }
        .bottom-link a:hover { color: var(--gold); border-bottom-color: var(--gold); }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes cardIn { to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 860px) {
            .overlay { background: linear-gradient(to bottom, rgba(10,14,20,0.85) 0%, rgba(10,14,20,0.60) 100%); }
            .page { padding: 40px 20px 48px; }
            .page-inner { flex-direction: column; align-items: center; gap: 32px; }
            .brand-panel { max-width: 100%; padding: 0; text-align: center; margin-bottom: 0; align-items: center; }
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
        <div class="page-inner">
            <div class="brand-panel">
                <span class="brand-tag">Library Management System</span>
                <h1 class="brand-title">Check Your<br><em>Inbox</em></h1>
                <p class="brand-sub">
                    We sent a 6-digit verification code to your email address.
                    Enter it below to confirm your identity and activate your account.
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

                <div class="envelope-icon">
                    <div class="envelope-ring">✉</div>
                </div>

                <h2 class="form-heading">Verify Your Email</h2>
                <p class="form-subheading">Enter the 6-digit code from your inbox</p>

                <?php if (VERIFY_DEBUG && $_SERVER['REQUEST_METHOD'] === 'POST') : ?>
                    <div class="debug-box">⚠ DEBUG ON — disable before going live
POST email : <?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>

POST code  : <?= htmlspecialchars($_POST['code']  ?? '', ENT_QUOTES) ?>
                    </div>
                <?php endif; ?>

                <?php if ($message !== '') : ?>
                    <div class="message <?= $messageType === 'success' ? 'success' : '' ?>">
                        <span class="message-icon"><?= $messageType === 'success' ? '✓' : '⚠' ?></span>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- Verify Form -->
                <form method="POST" autocomplete="off" id="verifyForm">
                    <input type="hidden" name="verify" value="1">

                    <div class="field">
                        <label for="email">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="your@email.com"
                            value="<?= $prefillEmail ?>"
                            required>
                    </div>

                    <div class="field">
                        <label for="code">Verification Code</label>
                        <input
                            type="text"
                            id="code"
                            name="code"
                            class="code-input"
                            placeholder="000000"
                            maxlength="6"
                            inputmode="numeric"
                            pattern="\d{6}"
                            autocomplete="one-time-code"
                            required>
                    </div>

                    <button type="submit" class="btn-verify" id="submitBtn">
                        Confirm Email
                    </button>
                </form>

                <!-- Resend Form (separate form so it never triggers the verify logic) -->
                <form method="POST" autocomplete="off" id="resendForm">
                    <input type="hidden" name="resend" value="1">
                    <input type="hidden" name="email" id="resendEmail" value="<?= $prefillEmail ?>">
                    <button type="submit" class="btn-resend" id="resendBtn">
                        ↺ &nbsp;Resend Code
                    </button>
                </form>

                <p class="bottom-link">
                    Still having trouble? <a href="register.php">Re-register</a>
                    &nbsp;·&nbsp; <a href="index.php">Back to login</a>
                </p>

                </div>
            </div>
        </div>
    </div>

    <script>
        const codeInput  = document.getElementById('code');
        const emailInput = document.getElementById('email');
        const submitBtn  = document.getElementById('submitBtn');
        const verifyForm = document.getElementById('verifyForm');
        const resendBtn  = document.getElementById('resendBtn');
        const resendEmail = document.getElementById('resendEmail');
        let submitted = false;

        // Keep resend hidden email in sync with what the user types
        emailInput.addEventListener('input', function () {
            resendEmail.value = this.value.trim();
        });

        // Auto-submit when 6 digits entered; guard prevents double-fire
        codeInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').substring(0, 6);
            if (this.value.length === 6 && !submitted) {
                submitted             = true;
                submitBtn.disabled    = true;
                submitBtn.textContent = 'Verifying…';
                verifyForm.submit();
            }
        });

        // Guard manual button click too
        verifyForm.addEventListener('submit', function () {
            if (submitted) { return false; }
            submitted             = true;
            submitBtn.disabled    = true;
            submitBtn.textContent = 'Verifying…';
        });

        // Resend cooldown: disable button for 60s after click
        document.getElementById('resendForm').addEventListener('submit', function () {
            resendBtn.disabled    = true;
            resendBtn.textContent = '↺  Resend Code (60s)';
            let secs = 60;
            const interval = setInterval(function () {
                secs--;
                if (secs <= 0) {
                    clearInterval(interval);
                    resendBtn.disabled    = false;
                    resendBtn.textContent = '↺  Resend Code';
                } else {
                    resendBtn.textContent = '↺  Resend Code (' + secs + 's)';
                }
            }, 1000);
        });
    </script>
</body>
</html>