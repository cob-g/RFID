<?php
// ── Dependencies ──────────────────────────────────────────────────────────────
session_start();
include 'db.php';


// ── PHPMailer ─────────────────────────────────────────────────────────────────
require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── SMTP Configuration ────────────────────────────────────────────────────────
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USERNAME',  'djohncedric6@gmail.com');
define('SMTP_PASSWORD',  'reuqdmvtgpboosdn');
define('SMTP_FROM',      'djohncedric6@gmail.com');
define('SMTP_FROM_NAME', 'SCC Seat Allocation');

// ── School / System Branding ──────────────────────────────────────────────────
define('SCHOOL_NAME',    'St. Clare College of Caloocan');
define('SCHOOL_ADDRESS', '164 A. Mabini St., Caloocan City, Metro Manila');
define('SUPPORT_EMAIL',  'library@stclare.edu.ph');
define('SCHOOL_WEBSITE', 'https://www.stclare.edu.ph');
define('VERIFY_URL',     'https://scc-library.free.nf/verify.php');


// ════════════════════════════════════════════════════════════════════════════
//  EMAIL BUILDER
// ════════════════════════════════════════════════════════════════════════════
function sendRegistrationEmail(string $toEmail, string $username, string $code): bool
{
    $year        = date('Y');
    $safeUser    = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safeCode    = htmlspecialchars($code,     ENT_QUOTES, 'UTF-8');
    $verifyLink  = VERIFY_URL . '?email=' . urlencode($toEmail);

    $accentBlue  = '#2563eb';
    $darkBlue    = '#1e40af';
    $bgColor     = '#f0f2f5';
    $bodyText    = '#1f2937';
    $mutedText   = '#6b7280';
    $borderColor = '#e5e7eb';

    $subject = '📬 Verify Your Email – ' . SCHOOL_NAME . ' Student Portal';

    $htmlBody = <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Verify Your Email</title>
    </head>
    <body style="margin:0;padding:0;background-color:{$bgColor};
                 font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0"
             style="background-color:{$bgColor};min-height:100vh;">
        <tr>
          <td align="center" style="padding:40px 16px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:560px;background:#ffffff;border-radius:18px;
                          overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
              <tr>
                <td style="background:linear-gradient(135deg,{$accentBlue} 0%,{$darkBlue} 100%);
                           padding:36px 40px 30px;text-align:center;">
                  <div style="font-size:52px;margin-bottom:12px;line-height:1;">✉️</div>
                  <h1 style="margin:0 0 8px;color:#ffffff;font-size:22px;
                              font-weight:700;letter-spacing:-0.3px;">
                    Confirm Your Email Address
                  </h1>
                  <p style="margin:0;color:rgba(255,255,255,0.80);font-size:13.5px;">
                    Seat &amp; Computer Allocation System
                  </p>
                </td>
              </tr>
              <tr>
                <td style="padding:32px 40px 28px;">
                  <p style="margin:0 0 16px;font-size:15.5px;color:{$bodyText};line-height:1.6;">
                    Hi <strong>{$safeUser}</strong> 👋,
                  </p>
                  <p style="margin:0 0 26px;font-size:15px;color:{$bodyText};line-height:1.75;">
                    Thank you for registering with the
                    <strong>St. Clare College of Caloocan</strong>
                    Student Portal. To activate your account, please use
                    the verification code below:
                  </p>
                  <table width="100%" cellpadding="0" cellspacing="0" border="0"
                         style="margin:0 0 26px;">
                    <tr>
                      <td align="center">
                        <table cellpadding="0" cellspacing="0" border="0">
                          <tr>
                            <td style="background:#eff6ff;border:2px dashed {$accentBlue};
                                       border-radius:14px;padding:22px 48px;text-align:center;">
                              <p style="margin:0 0 6px;font-size:12px;font-weight:600;
                                         color:#6b7280;letter-spacing:0.08em;
                                         text-transform:uppercase;">
                                Your Verification Code
                              </p>
                              <p style="margin:0;font-size:42px;font-weight:800;
                                         color:{$accentBlue};letter-spacing:10px;
                                         line-height:1.2;">
                                {$safeCode}
                              </p>
                              <p style="margin:10px 0 0;font-size:12px;color:#9ca3af;">
                                Valid for <strong>24 hours</strong>
                              </p>
                            </td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                  </table>
                  <p style="margin:0 0 26px;font-size:14.5px;color:{$bodyText};line-height:1.75;">
                    Enter this code on the
                    <a href="{$verifyLink}" style="color:{$accentBlue};text-decoration:none;font-weight:600;">
                      verification page
                    </a>
                    to complete your registration and activate your account.
                  </p>
                  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 28px;">
                    <tr>
                      <td align="center">
                        <a href="{$verifyLink}"
                           style="display:inline-block;background:{$accentBlue};
                                  color:#ffffff;text-decoration:none;
                                  padding:14px 36px;border-radius:10px;
                                  font-size:14.5px;font-weight:700;letter-spacing:0.02em;">
                          ✅&nbsp; Go to Verification Page &rarr;
                        </a>
                      </td>
                    </tr>
                  </table>
                  <table width="100%" cellpadding="0" cellspacing="0" border="0"
                         style="background:#eff6ff;border-radius:10px;margin:0 0 28px;">
                    <tr>
                      <td style="padding:18px 22px;">
                        <p style="margin:0 0 10px;font-size:12.5px;font-weight:700;
                                   color:#1d4ed8;letter-spacing:0.05em;text-transform:uppercase;">
                          📋&nbsp; Important Reminders
                        </p>
                        <ul style="margin:0;padding-left:18px;color:#374151;font-size:13.5px;line-height:2.0;">
                          <li>This code expires in <strong>24 hours</strong>.</li>
                          <li>Do <strong>not</strong> share this code with anyone.</li>
                          <li>If you did not register, you can safely ignore this email.</li>
                          <li>For help, contact us at
                            <a href="mailto:library@stclare.edu.ph" style="color:{$accentBlue};text-decoration:none;">
                              library@stclare.edu.ph
                            </a>.
                          </li>
                        </ul>
                      </td>
                    </tr>
                  </table>
                  <p style="margin:0;font-size:14px;color:{$mutedText};line-height:1.75;">
                    Warm regards,<br>
                    <strong style="color:{$bodyText};">Library Services Team</strong><br>
                    <span style="color:{$mutedText};">St. Clare College of Caloocan</span>
                  </p>
                </td>
              </tr>
              <tr>
                <td style="background:#f0f2f5;border-top:1px solid {$borderColor};
                           padding:20px 40px;text-align:center;">
                  <p style="margin:0 0 4px;font-size:11.5px;color:{$mutedText};">
                    &copy; {$year} St. Clare College of Caloocan. All rights reserved.
                  </p>
                  <p style="margin:0 0 4px;font-size:11.5px;color:{$mutedText};">
                    164 A. Mabini St., Caloocan City, Metro Manila
                  </p>
                  <p style="margin:0 0 8px;font-size:11.5px;">
                    <a href="mailto:library@stclare.edu.ph" style="color:{$accentBlue};text-decoration:none;">
                      library@stclare.edu.ph
                    </a>
                  </p>
                  <p style="margin:0;font-size:11px;color:#9ca3af;">
                    This is an automated message — please do not reply directly to this email.
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </body>
    </html>
    HTML;

    $plainText = <<<TXT
    📬 VERIFY YOUR EMAIL — St. Clare College of Caloocan

    Hi {$safeUser},

    Thank you for registering. Enter the code below on the verification page.

    YOUR VERIFICATION CODE: {$safeCode}

    Verify here: {$verifyLink}

    This code expires in 24 hours. Do NOT share it.
    For help: library@stclare.edu.ph

    — Library Services Team, St. Clare College of Caloocan
    TXT;

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->SMTPDebug  = 0;
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $username);
        $mail->addReplyTo(SUPPORT_EMAIL, SCHOOL_NAME . ' Support');
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainText;
        $mail->send();
        error_log("[register.php] Verification email sent → {$toEmail}");
        return true;
    } catch (Exception $e) {
        error_log("[register.php] Email failed for {$toEmail}: " . $mail->ErrorInfo);
        return false;
    }
}

function generateVerificationCode(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function findUserByEmail(mysqli $conn, string $email): ?array
{
    $normalizedEmail = strtolower(trim($email));

    // First pass: exact match after normalization.
    $stmt = $conn->prepare("\n        SELECT id, username, status, email\n        FROM users\n        WHERE LOWER(TRIM(email)) = ?\n        LIMIT 1\n    ");
    $stmt->bind_param('s', $normalizedEmail);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

function usernameExists(mysqli $conn, string $username): bool
{
    $stmt = $conn->prepare("\n        SELECT id\n        FROM users\n        WHERE username = ?\n        LIMIT 1\n    ");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (bool) $row;
}

function resendVerificationForExistingUser(mysqli $conn, array $user, string $email): void
{
    $userId = (int) ($user['id'] ?? 0);
    $username = (string) ($user['username'] ?? 'Student');

    if ($userId <= 0) {
        error_log('[register.php] resendVerificationForExistingUser called with invalid user id');
        return;
    }

    $newCode = generateVerificationCode();

    $upd = $conn->prepare("\n        UPDATE users\n        SET verification_code = ?, status = 'pending'\n        WHERE id = ?\n    ");
    $upd->bind_param('si', $newCode, $userId);
    $upd->execute();
    $upd->close();

    $sent = sendRegistrationEmail($email, $username, $newCode);
    if (!$sent) {
        error_log("[register.php] Resend verification email failed for: {$email}");
    } else {
        error_log("[register.php] Resent verification code -> user_id={$userId} email={$email}");
    }

    session_write_close();
    header('Location: verify.php?email=' . urlencode($email) . '&resent=1');
    exit;
}


// ════════════════════════════════════════════════════════════════════════════
//  PAGE LOGIC
// ════════════════════════════════════════════════════════════════════════════
$message = '';

if (isset($_POST['register'])) {

    $username = trim($_POST['username'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');
    $role     = (string) ($_POST['role'] ?? '');

    if ($username === '' || $email === '' || $password === '') {
        $message = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {

        // ── Pre-insert lookup to avoid confusing 1062 false positives ───────
        $existingEmailUser = findUserByEmail($conn, $email);
        if ($existingEmailUser) {
            $existingStatus = (string) ($existingEmailUser['status'] ?? '');
            if ($existingStatus === 'active') {
                $message = 'Email already registered. Please sign in.';
            } else {
                resendVerificationForExistingUser($conn, $existingEmailUser, $email);
            }
        } elseif (usernameExists($conn, $username)) {
            $message = 'Username already taken.';
        } else {

            $hashed            = password_hash($password, PASSWORD_DEFAULT);
            $verification_code = generateVerificationCode();
            $status            = 'pending';

            try {
                $stmt = $conn->prepare("\n                    INSERT INTO users (username, email, password, role, verification_code, status)\n                    VALUES (?, ?, ?, ?, ?, ?)\n                ");
                $stmt->bind_param('ssssss', $username, $email, $hashed, $role, $verification_code, $status);
                $stmt->execute();
                $stmt->close();

                $emailSent = sendRegistrationEmail($email, $username, $verification_code);
                if (!$emailSent) {
                    error_log("[register.php] Verification email failed for: {$email}");
                }

                session_write_close();
                header('Location: verify.php?email=' . urlencode($email));
                exit();

            } catch (mysqli_sql_exception $e) {
                if ((int) $e->getCode() === 1062) {

                    // Race-condition fallback: log which unique key failed.
                    error_log('[register.php] Duplicate key on INSERT: ' . $e->getMessage());

                    $existingEmailUser = findUserByEmail($conn, $email);
                    if ($existingEmailUser) {
                        $existingStatus = (string) ($existingEmailUser['status'] ?? '');
                        if ($existingStatus === 'active') {
                            $message = 'Email already registered. Please sign in.';
                        } else {
                            resendVerificationForExistingUser($conn, $existingEmailUser, $email);
                        }
                    } elseif (usernameExists($conn, $username)) {
                        $message = 'Username already taken.';
                    } else {
                        $message = 'A system error occurred while creating your account. Please try again.';
                        error_log('[register.php] 1062 but no matching user found after recheck (possible index/schema issue).');
                    }

                } else {
                    $message = 'A database error occurred. Please try again.';
                    error_log("[register.php] DB error: " . $e->getMessage());
                }
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
    <title>Register — St. Clare College of Caloocan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --gold:         #c8a96e;
            --gold-light:   #e2c99a;
            --dark:         #1a1f27;
            --panel-bg:     rgba(255, 255, 255, 0.07);
            --input-bg:     rgba(255, 255, 255, 0.09);
            --input-border: rgba(200, 169, 110, 0.35);
            --text-main:    #f0ece4;
            --text-muted:   rgba(240, 236, 228, 0.55);
            --error:        #f28b8b;
            --success:      #7ec8a0;
            --radius:       14px;
            --transition:   0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html, body {
            height: 100%;
            width: 100%;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--dark);
            overflow: hidden;
        }

        /* ── Background & Overlay ──────────────────────────────────────────── */
        .bg-layer {
            position: fixed;
            inset: 0;
            background-image: url('bg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            z-index: 0;
            filter: brightness(1.1);
        }

        .overlay {
            position: fixed;
            inset: 0;
            background: linear-gradient(
                to right,
                rgba(10, 14, 20, 0.97) 0%,
                rgba(10, 14, 20, 0.88) 30%,
                rgba(10, 14, 20, 0.55) 58%,
                rgba(10, 14, 20, 0.18) 80%,
                rgba(10, 14, 20, 0.05) 100%
            );
            z-index: 1;
        }

        /* ── Page Layout ───────────────────────────────────────────────────── */
        .page {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: stretch;
            height: 100vh;
            width: 100%;
        }

        /* ── Brand Panel ───────────────────────────────────────────────────── */
        .brand-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 64px;
            max-width: 560px;
        }

        .brand-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 11px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 36px;
            opacity: 0;
            animation: fadeUp 0.7s 0.1s ease forwards;
        }

        .brand-tag::before {
            content: '';
            display: block;
            width: 28px;
            height: 1px;
            background: var(--gold);
        }

        .brand-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(38px, 5vw, 62px);
            font-weight: 300;
            color: var(--text-main);
            line-height: 1.12;
            opacity: 0;
            animation: fadeUp 0.7s 0.25s ease forwards;
        }

        .brand-title em {
            font-style: italic;
            color: var(--gold-light);
        }

        .brand-sub {
            margin-top: 20px;
            font-size: 14px;
            line-height: 1.75;
            color: var(--text-muted);
            max-width: 340px;
            opacity: 0;
            animation: fadeUp 0.7s 0.4s ease forwards;
        }

        .divider {
            width: 48px;
            height: 1px;
            background: linear-gradient(to right, var(--gold), transparent);
            margin: 32px 0;
            opacity: 0;
            animation: fadeUp 0.7s 0.55s ease forwards;
        }

        .brand-meta {
            font-size: 12px;
            letter-spacing: 1px;
            color: var(--text-muted);
            text-transform: uppercase;
            opacity: 0;
            animation: fadeUp 0.7s 0.65s ease forwards;
        }

        /* ── Form Panel ────────────────────────────────────────────────────── */
        .form-panel {
            width: 440px;
            min-width: 340px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 24px;
            margin-right: 5vw;
        }

        .form-card {
            width: 100%;
            max-width: 400px;
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(24px) saturate(160%);
            -webkit-backdrop-filter: blur(24px) saturate(160%);
            border: 1px solid rgba(200, 169, 110, 0.18);
            border-radius: 22px;
            padding: 40px 40px 36px;
            box-shadow:
                0 8px 40px rgba(0, 0, 0, 0.45),
                0 1px 0 rgba(255,255,255,0.06) inset;
            opacity: 0;
            transform: translateY(24px);
            animation: cardIn 0.75s 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }

        /* ── Logo Section ──────────────────────────────────────────────────── */
        .logo-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 22px;
        }

        .logo-ring {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(200, 169, 110, 0.1);
            border: 1.5px solid rgba(200, 169, 110, 0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            box-shadow: 0 0 0 6px rgba(200, 169, 110, 0.06);
        }

        .logo-ring img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            filter: drop-shadow(0 2px 6px rgba(200,169,110,0.3));
        }

        .school-name {
            font-family: 'Cormorant Garamond', serif;
            font-size: 15px;
            font-weight: 600;
            color: var(--gold-light);
            letter-spacing: 0.5px;
            text-align: center;
        }

        /* ── Form Headings ─────────────────────────────────────────────────── */
        .form-heading {
            font-family: 'Cormorant Garamond', serif;
            font-size: 26px;
            font-weight: 300;
            color: var(--text-main);
            text-align: center;
            margin-bottom: 4px;
        }

        .form-subheading {
            font-size: 12.5px;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 22px;
            letter-spacing: 0.3px;
        }

        /* ── Message / Alert ───────────────────────────────────────────────── */
        .message {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            padding: 11px 14px;
            border-radius: 9px;
            margin-bottom: 18px;
            background: rgba(242, 139, 139, 0.12);
            border: 1px solid rgba(242, 139, 139, 0.28);
            color: var(--error);
        }

        .message.success {
            background: rgba(126, 200, 160, 0.12);
            border-color: rgba(126, 200, 160, 0.28);
            color: var(--success);
        }

        .message::before {
            content: '⚠';
            font-size: 14px;
            flex-shrink: 0;
        }

        .message.success::before {
            content: '✓';
        }

        /* ── Form Fields ───────────────────────────────────────────────────── */
        .field {
            position: relative;
            margin-bottom: 14px;
        }

        .field label {
            display: block;
            font-size: 11.5px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 7px;
            font-weight: 500;
        }

        .field input,
        .field select {
            width: 100%;
            padding: 13px 16px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 10px;
            color: var(--text-main);
            font-family: 'DM Sans', sans-serif;
            font-size: 14.5px;
            outline: none;
            transition: border-color var(--transition), box-shadow var(--transition), background var(--transition);
            appearance: none;
            -webkit-appearance: none;
        }

        .field input::placeholder {
            color: rgba(240, 236, 228, 0.3);
        }

        /* Style the select placeholder option */
        .field select option {
            background: #1e2530;
            color: var(--text-main);
        }

        .field select option[value=""] {
            color: rgba(240, 236, 228, 0.3);
        }

        /* Custom dropdown arrow */
        .select-wrapper {
            position: relative;
        }

        .select-wrapper::after {
            content: '▾';
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gold);
            font-size: 14px;
            pointer-events: none;
            line-height: 1;
        }

        .field input:focus,
        .field select:focus {
            border-color: var(--gold);
            background: rgba(255, 255, 255, 0.12);
            box-shadow: 0 0 0 3px rgba(200, 169, 110, 0.15), 0 2px 8px rgba(0,0,0,0.2);
        }

        /* ── Password Toggle ───────────────────────────────────────────────── */
        .field .toggle-pw {
            position: absolute;
            right: 14px;
            bottom: 14px;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 16px;
            line-height: 1;
            transition: color var(--transition);
            user-select: none;
        }

        .field .toggle-pw:hover {
            color: var(--gold);
        }

        /* ── Submit Button ─────────────────────────────────────────────────── */
        .btn-register {
            width: 100%;
            padding: 14px;
            margin-top: 6px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #c8a96e 0%, #a07840 100%);
            color: #1a1208;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            font-weight: 500;
            letter-spacing: 1px;
            text-transform: uppercase;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform var(--transition), box-shadow var(--transition), filter var(--transition);
            box-shadow: 0 4px 20px rgba(200, 169, 110, 0.3);
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(200, 169, 110, 0.45);
            filter: brightness(1.08);
        }

        .btn-register:active {
            transform: translateY(0);
            box-shadow: 0 3px 12px rgba(200, 169, 110, 0.25);
        }

        /* ── Login Link ────────────────────────────────────────────────────── */
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .login-link a {
            color: var(--gold-light);
            text-decoration: none;
            font-weight: 500;
            border-bottom: 1px solid transparent;
            transition: border-color var(--transition), color var(--transition);
        }

        .login-link a:hover {
            color: var(--gold);
            border-bottom-color: var(--gold);
        }

        /* ── Animations ────────────────────────────────────────────────────── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes cardIn {
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Responsive ────────────────────────────────────────────────────── */
        @media (max-width: 860px) {
            body { overflow: auto; }

            .overlay {
                background: linear-gradient(
                    to bottom,
                    rgba(10, 14, 20, 0.85) 0%,
                    rgba(10, 14, 20, 0.60) 100%
                );
            }

            .page {
                flex-direction: column;
                justify-content: flex-start;
                align-items: center;
                height: auto;
                min-height: 100vh;
                padding: 40px 20px 48px;
            }

            .brand-panel {
                max-width: 100%;
                padding: 0;
                text-align: center;
                margin-bottom: 32px;
            }

            .brand-tag {
                justify-content: center;
            }

            .brand-tag::before { display: none; }

            .brand-sub {
                max-width: 100%;
            }

            .form-panel {
                width: 100%;
                max-width: 440px;
                margin-right: 0;
                padding: 0;
            }

            .form-card {
                padding: 34px 28px 30px;
            }
        }

        @media (max-width: 400px) {
            .form-card {
                padding: 28px 20px 24px;
                border-radius: 16px;
            }
        }
    </style>
</head>

<body>

    <div class="bg-layer"></div>
    <div class="overlay"></div>

    <div class="page">

        <!-- ── Brand Panel ──────────────────────────────────────────────────── -->
        <div class="brand-panel">
            <span class="brand-tag">Library Management System</span>
            <h1 class="brand-title">Begin Your<br><em>Journey Here</em></h1>
            <p class="brand-sub">
                Join the community of learners. Create your account to
                reserve study spaces, access curated resources, and stay
                connected with the library system.
            </p>
            <div class="divider"></div>
            <p class="brand-meta">St. Clare College of Caloocan &nbsp;·&nbsp; Est. 1969</p>
        </div>

        <!-- ── Form Panel ───────────────────────────────────────────────────── -->
        <div class="form-panel">
            <div class="form-card">

                <!-- Logo -->
                <div class="logo-section">
                    <div class="logo-ring">
                        <img src="logo.png" alt="St. Clare College of Caloocan Logo">
                    </div>
                    <p class="school-name">St. Clare College of Caloocan</p>
                </div>

                <h2 class="form-heading">Create Account</h2>
                <p class="form-subheading">Fill in the details below to get started</p>

                <!-- Error / Success Message -->
                <?php if ($message !== '') : ?>
                    <div class="message"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <form method="POST" autocomplete="on">

                    <!-- Email -->
                    <div class="field">
                        <label for="email">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="Enter your email"
                            autocomplete="email"
                            required>
                    </div>

                    <!-- Username -->
                    <div class="field">
                        <label for="username">Username</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Choose a username"
                            autocomplete="username"
                            required>
                    </div>

                    <!-- Password -->
                    <div class="field">
                        <label for="password">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Create a password"
                            autocomplete="new-password"
                            required>
                        <span class="toggle-pw" id="togglePw" title="Show / hide password">👁</span>
                    </div>

                    <!-- Role -->
                    <div class="field">
                        <label for="role">Role</label>
                        <div class="select-wrapper">
                            <select id="role" name="role" required>
                                <option value="" disabled selected>Select your role</option>
                                <option value="student">Student</option>
                                <option value="faculty">Faculty</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" name="register" class="btn-register">Create Account</button>
                </form>

                <p class="login-link">
                    Already have an account? <a href="index.php">Sign in here</a>
                </p>

            </div>
        </div>

    </div>

    <script>
        const togglePw = document.getElementById('togglePw');
        const pwInput  = document.getElementById('password');

        togglePw.addEventListener('click', () => {
            const isText = pwInput.type === 'text';
            pwInput.type = isText ? 'password' : 'text';
            togglePw.textContent = isText ? '👁' : '🙈';
        });
    </script>

</body>
</html>