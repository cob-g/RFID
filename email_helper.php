<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);
define('MAIL_USERNAME',   'djohncedric6@gmail.com');
define('MAIL_PASSWORD',   'reuqdmvtgpboosdn');
define('MAIL_FROM',       'djohncedric6@gmail.com');
define('MAIL_FROM_NAME',  'SCC Seat Allocation');
define('MAIL_ENCRYPTION', PHPMailer::ENCRYPTION_STARTTLS);

define('SCHOOL_NAME',     'St. Clare College of Caloocan');
define('SCHOOL_WEBSITE',  'https://stclarecollege.com/');
define('SCHOOL_ADDRESS',  'Zabarte road, Caloocan, Philippines, 1400');
define('SUPPORT_EMAIL',   'stclarecollege.ph@gmail.com');
define('SYSTEM_NAME',     'Seat & Computer Allocation System');


// ══════════════════════════════════════════════════════════════════════════════
//  PUBLIC API
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Send a verification code email directly via PHPMailer.
 * Called immediately from verify.php — do NOT queue this one.
 */
function sendVerificationEmail(string $toEmail, string $studentName, string $code): bool
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $studentName);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = '🔐 Your Email Verification Code — ' . SCHOOL_NAME;
        $mail->Body    = "
            <div style='font-family:Arial,sans-serif;max-width:480px;margin:auto;padding:24px;
                        background:#ffffff;border-radius:12px;'>
                <h2 style='color:#c8a96e;margin-bottom:4px;'>Email Verification</h2>
                <p style='color:#555;'>Hello <strong>{$studentName}</strong>,</p>
                <p style='color:#555;'>Use the code below to verify your account:</p>
                <div style='font-size:40px;font-weight:bold;letter-spacing:14px;
                            padding:20px;background:#f5f0e8;border-radius:10px;
                            text-align:center;color:#1a1f27;margin:20px 0;'>
                    {$code}
                </div>
                <p style='color:#888;font-size:12px;'>
                    This code expires once used. If you did not request this, ignore this email.
                </p>
                <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
                <p style='color:#aaa;font-size:11px;text-align:center;'>
                    " . SCHOOL_NAME . " &mdash; Library Management System
                </p>
            </div>
        ";
        $mail->AltBody = "Hello {$studentName},\n\nYour verification code is: {$code}\n\n"
                       . "If you did not request this, ignore this email.\n\n"
                       . "— " . SCHOOL_NAME . " Library";

        $mail->send();
        error_log("[sendVerificationEmail] Sent OK → {$toEmail}");
        return true;

    } catch (Exception $e) {
        error_log('[sendVerificationEmail] Failed → ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send a seat-reservation pending-confirmation email.
 */
function sendSeatEmail(
    string $toEmail,
    string $studentName,
    string $seatNumber,
    string $location = 'Study Area',
    string $datetime = ''
): bool {
    $data = _buildPayload('seat', $studentName, $seatNumber, null, $location, $datetime);
    return _dispatch($toEmail, $studentName, $data);
}

/**
 * Send a computer-reservation pending-confirmation email.
 */
function sendComputerEmail(
    string $toEmail,
    string $studentName,
    string $computerNumber,
    string $location = 'Computer Area',
    string $datetime = ''
): bool {
    $data = _buildPayload('computer', $studentName, null, $computerNumber, $location, $datetime);
    return _dispatch($toEmail, $studentName, $data);
}

/**
 * Send a seat-reservation confirmed email after RFID TIME_IN.
 */
function sendSeatConfirmedEmail(
  string $toEmail,
  string $studentName,
  array $seatNumbers,
  string $location = 'Study Area',
  string $datetime = ''
): bool {
  $seatNumbers = array_values(array_unique(array_filter(array_map(
    static fn($v): string => trim((string) $v),
    $seatNumbers
  ), static fn(string $v): bool => $v !== '')));

  if (empty($seatNumbers)) {
    return false;
  }

  date_default_timezone_set('Asia/Manila');
  if ($datetime === '') {
    $datetime = date('F j, Y \\a\\t g:i A');
  }

  $seatList = implode(', ', $seatNumbers);
  $subjectItem = count($seatNumbers) === 1
    ? ('Seat ' . $seatNumbers[0])
    : ('Seats ' . $seatList);

  $subject = '✅ Reservation Confirmed – ' . $subjectItem . ' | ' . SCHOOL_NAME;
  $html = _buildSeatConfirmedHtml($studentName, $seatList, $location, $datetime);
  $text = _buildSeatConfirmedPlainText($studentName, $seatList, $location, $datetime);

  return _send($toEmail, $studentName, $subject, $html, $text);
}

  /**
   * Send a reservation released email after TIME_OUT or pending-expired cleanup.
   */
  function sendReservationTimeoutEmail(
    string $toEmail,
    string $studentName,
    array $seatLabels,
    array $computerLabels,
    string $reason,
    string $datetime = ''
  ): bool {
    $seatLabels = _normalizeLabelList($seatLabels);
    $computerLabels = _normalizeLabelList($computerLabels);

    if (empty($seatLabels) && empty($computerLabels)) {
      return false;
    }

    $reason = trim($reason);
    if ($reason === '') {
      $reason = 'TIME_OUT';
    }

    date_default_timezone_set('Asia/Manila');
    if ($datetime === '') {
      $datetime = date('F j, Y \\a\\t g:i A');
    }

    $subjectItem = _buildTimeoutSubjectLabels($seatLabels, $computerLabels);
    $subject = '⌛ Reservation Released – ' . $subjectItem . ' | ' . SCHOOL_NAME;
    $html = _buildTimeoutHtml($studentName, $seatLabels, $computerLabels, $reason, $datetime);
    $text = _buildTimeoutPlainText($studentName, $seatLabels, $computerLabels, $reason, $datetime);

    return _send($toEmail, $studentName, $subject, $html, $text);
  }

function queueSeatEmail(string $toEmail, string $studentName, string $seatNumber, string $location = 'Study Area') {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO email_queue (to_email, subject, body, status) VALUES (?, ?, ?, 'pending')");
    $data = _buildPayload('seat', $studentName, $seatNumber, null, $location, date('F j, Y \a\t g:i A'));
    $subject = "⏳ Reservation Pending Confirmation – Seat {$seatNumber} | " . SCHOOL_NAME;
    $body = _buildHtml($data);
    $stmt->bind_param("sss", $toEmail, $subject, $body);
    return $stmt->execute();
}

function queueComputerEmail(string $toEmail, string $studentName, string $computerNumber, string $location = 'Computer Area') {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO email_queue (to_email, subject, body, status) VALUES (?, ?, ?, 'pending')");
    $data = _buildPayload('computer', $studentName, null, $computerNumber, $location, date('F j, Y \a\t g:i A'));
    $subject = "⏳ Reservation Pending Confirmation – Computer {$computerNumber} | " . SCHOOL_NAME;
    $body = _buildHtml($data);
    $stmt->bind_param("sss", $toEmail, $subject, $body);
    return $stmt->execute();
}

// ══════════════════════════════════════════════════════════════════════════════
//  PRIVATE HELPERS
// ══════════════════════════════════════════════════════════════════════════════

function _buildPayload(
    string $type,
    string $studentName,
    ?string $seatNumber,
    ?string $computerNumber,
    string $location,
    string $datetime
): array {
    date_default_timezone_set('Asia/Manila');
    if (empty($datetime)) {
        $datetime = date('F j, Y  \a\t  g:i A');
    }
    $isComputer    = ($type === 'computer');
    $resourceLabel = $isComputer
        ? "Computer <strong>{$computerNumber}</strong>"
        : "Seat <strong>{$seatNumber}</strong>";
    $icon        = $isComputer ? '🖥️' : '💺';
    $subjectItem = $isComputer ? "Computer {$computerNumber}" : "Seat {$seatNumber}";

    return [
        'student_name'     => htmlspecialchars($studentName),
        'seat_number'      => $seatNumber     ? htmlspecialchars($seatNumber)     : null,
        'computer_number'  => $computerNumber ? htmlspecialchars($computerNumber) : null,
        'resource_label'   => $resourceLabel,
        'location'         => htmlspecialchars($location),
        'datetime'         => htmlspecialchars($datetime),
        'icon'             => $icon,
        'subject_item'     => $subjectItem,
        'type'             => $type,
        'school_name'      => SCHOOL_NAME,
        'school_website'   => SCHOOL_WEBSITE,
        'school_address'   => SCHOOL_ADDRESS,
        'support_email'    => SUPPORT_EMAIL,
        'system_name'      => SYSTEM_NAME,
    ];
}

function _dispatch(string $toEmail, string $studentName, array $d): bool
{
    $subject = "⏳ Reservation Pending Confirmation – {$d['subject_item']} | " . SCHOOL_NAME;
    return _send($toEmail, $studentName, $subject, _buildHtml($d), _buildPlainText($d));
}

function _send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->SMTPDebug  = 0;
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(SUPPORT_EMAIL, SCHOOL_NAME . ' Support');

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        error_log("[EmailHelper] Mail sent OK → {$toEmail}");
        return true;
    } catch (Exception $e) {
        error_log("[EmailHelper] Mailer Error → {$toEmail}: " . $mail->ErrorInfo);
        return false;
    }
}

function _buildHtml(array $d): string
{
    $accentColor = '#2563eb';
    $greenColor  = '#16a34a';
    $bgColor     = '#f0f2f5';
    $cardBg      = '#ffffff';
    $mutedText   = '#6b7280';
    $bodyText    = '#1f2937';
    $borderColor = '#e5e7eb';

    $detailRows = '';
    if ($d['type'] === 'seat') {
        $detailRows .= _detailRow('Seat Number', $d['seat_number'], '#1d4ed8');
    } else {
        $detailRows .= _detailRow('Computer', $d['computer_number'], '#1d4ed8');
    }
    $detailRows .= _detailRow('Location',    $d['location'], $bodyText);
    $detailRows .= _detailRow('Date & Time', $d['datetime'], $bodyText);

    $reminders = <<<HTML
    <table width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background:#eff6ff;border-radius:10px;margin:24px 0 0;">
      <tr>
        <td style="padding:18px 20px;">
          <p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#1d4ed8;
                     letter-spacing:0.04em;text-transform:uppercase;">📋 Reminders</p>
          <ul style="margin:0;padding-left:18px;color:#374151;font-size:13.5px;line-height:1.8;">
            <li>This reservation is released automatically if you do not <strong>TIME_IN within 15 minutes</strong>.</li>
            <li>To <strong>cancel</strong>, log in and click your reserved seat/computer before your session.</li>
            <li>Only <strong>one allocation</strong> per session is permitted per student.</li>
            <li>Report any equipment issues to library staff immediately.</li>
            <li>Support: <a href="mailto:{$d['support_email']}" style="color:{$accentColor};">{$d['support_email']}</a></li>
          </ul>
        </td>
      </tr>
    </table>
    HTML;

    return <<<HTML
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reservation Pending Confirmation</title></head>
    <body style="margin:0;padding:0;background:{$bgColor};font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{$bgColor};min-height:100vh;">
      <tr><td align="center" style="padding:40px 16px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0"
               style="max-width:560px;background:{$cardBg};border-radius:18px;overflow:hidden;
                      box-shadow:0 4px 24px rgba(0,0,0,0.08);">
          <tr>
            <td style="background:linear-gradient(135deg,{$accentColor} 0%,#1e40af 100%);
                       padding:36px 40px 32px;text-align:center;">
              <div style="font-size:48px;margin-bottom:10px;">{$d['icon']}</div>
              <h1 style="margin:0 0 6px;color:#fff;font-size:22px;font-weight:700;">Reservation Pending Confirmation</h1>
              <p style="margin:0;color:rgba(255,255,255,0.80);font-size:13.5px;">{$d['system_name']}</p>
            </td>
          </tr>
          <tr>
            <td style="padding:32px 40px 24px;">
              <p style="margin:0 0 20px;font-size:15.5px;color:{$bodyText};line-height:1.6;">
                Hi <strong>{$d['student_name']}</strong>,</p>
              <p style="margin:0 0 24px;font-size:15px;color:{$bodyText};line-height:1.7;">
                Your reservation is <span style="color:{$greenColor};font-weight:700;">pending confirmation</span>.
                Tap your RFID card to TIME_IN within <strong>15 minutes</strong> to keep this reservation active.</p>
              <table width="100%" cellpadding="0" cellspacing="0" border="0"
                     style="background:#f8f9fb;border:1px solid {$borderColor};border-radius:12px;
                            overflow:hidden;margin-bottom:8px;">
                <tr><td style="padding:20px 24px;">{$detailRows}</td></tr>
              </table>
              {$reminders}
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 4px;">
                <tr><td align="center">
                  <a href="{$d['school_website']}"
                     style="display:inline-block;background:{$accentColor};color:#fff;
                            text-decoration:none;padding:13px 34px;border-radius:10px;
                            font-size:14px;font-weight:700;">View Allocation System →</a>
                </td></tr>
              </table>
              <p style="margin:28px 0 0;font-size:14px;color:{$mutedText};line-height:1.7;">
                Thank you for using the {$d['system_name']}.<br>
                Warm regards,<br>
                <strong style="color:{$bodyText};">Library Services Team</strong><br>
                <span style="color:{$mutedText};">{$d['school_name']}</span>
              </p>
            </td>
          </tr>
          <tr>
            <td style="background:#f0f2f5;border-top:1px solid {$borderColor};
                       padding:20px 40px;text-align:center;">
              <p style="margin:0 0 4px;font-size:11.5px;color:{$mutedText};">
                This is an automated message. Please do not reply directly.</p>
              <p style="margin:0;font-size:11.5px;color:{$mutedText};">
                {$d['school_address']}<br>
                <a href="mailto:{$d['support_email']}" style="color:{$accentColor};">{$d['support_email']}</a>
              </p>
            </td>
          </tr>
        </table>
      </td></tr>
    </table>
    </body></html>
    HTML;
}

function _detailRow(string $label, string $value, string $valueColor = '#1f2937'): string
{
    return <<<HTML
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:12px;">
      <tr>
        <td width="38%" style="font-size:12px;font-weight:600;color:#9ca3af;
                                text-transform:uppercase;letter-spacing:0.07em;vertical-align:top;padding-top:2px;">
          {$label}
        </td>
        <td style="font-size:14.5px;font-weight:600;color:{$valueColor};vertical-align:top;">
          {$value}
        </td>
      </tr>
    </table>
    HTML;
}

function _buildPlainText(array $d): string
{
    $item = $d['type'] === 'seat'
          ? "Seat Number : {$d['seat_number']}"
          : "Computer    : {$d['computer_number']}";

      return "⏳ RESERVATION PENDING CONFIRMATION — {$d['school_name']}\n\n"
         . "Hi {$d['student_name']},\n\n"
         . "Your reservation is pending confirmation. Tap your RFID card to TIME_IN within 15 minutes.\n\n"
         . "ALLOCATION DETAILS\n------------------\n"
         . "{$item}\nLocation    : {$d['location']}\nDate & Time : {$d['datetime']}\n\n"
         . "REMINDERS\n---------\n"
         . "• This reservation is released automatically if you do not TIME_IN within 15 minutes.\n"
         . "• To cancel, log in and click your reserved seat/computer before your session.\n"
         . "• Only one allocation per session is permitted per student.\n"
         . "• Support: {$d['support_email']}\n\n"
         . "Library Services Team\n{$d['school_name']}\n{$d['school_address']}\n\n"
         . "──\nThis is an automated message. Please do not reply directly.";
}

function _normalizeLabelList(array $labels): array
{
    $labels = array_values(array_unique(array_filter(array_map(
        static fn($v): string => trim((string) $v),
        $labels
    ), static fn(string $v): bool => $v !== '')));

    sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
    return $labels;
}

function _buildTimeoutSubjectLabels(array $seatLabels, array $computerLabels): string
{
    $parts = [];
    if (!empty($seatLabels)) {
        $seatList = implode(', ', $seatLabels);
        $parts[] = count($seatLabels) === 1 ? ('Seat ' . $seatLabels[0]) : ('Seats ' . $seatList);
    }
    if (!empty($computerLabels)) {
        $compList = implode(', ', $computerLabels);
        $parts[] = count($computerLabels) === 1
            ? ('Computer ' . $computerLabels[0])
            : ('Computers ' . $compList);
    }
    if (empty($parts)) {
        return 'Reservation';
    }

    return implode(' + ', $parts);
}

function _buildTimeoutHtml(
    string $studentName,
    array $seatLabels,
    array $computerLabels,
    string $reason,
    string $datetime
): string {
    $safeName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');
    $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    $safeDatetime = htmlspecialchars($datetime, ENT_QUOTES, 'UTF-8');
    $seatList = empty($seatLabels)
        ? 'None'
        : htmlspecialchars(implode(', ', $seatLabels), ENT_QUOTES, 'UTF-8');
    $computerList = empty($computerLabels)
        ? 'None'
        : htmlspecialchars(implode(', ', $computerLabels), ENT_QUOTES, 'UTF-8');
    $support = htmlspecialchars(SUPPORT_EMAIL, ENT_QUOTES, 'UTF-8');
    $school = htmlspecialchars(SCHOOL_NAME, ENT_QUOTES, 'UTF-8');
    $system = htmlspecialchars(SYSTEM_NAME, ENT_QUOTES, 'UTF-8');
    $address = htmlspecialchars(SCHOOL_ADDRESS, ENT_QUOTES, 'UTF-8');
    $website = htmlspecialchars(SCHOOL_WEBSITE, ENT_QUOTES, 'UTF-8');

    $accentColor = '#b45309';
    $detailRows = '';
    $detailRows .= _detailRow('Seats', $seatList, $accentColor);
    $detailRows .= _detailRow('Computers', $computerList, $accentColor);
    $detailRows .= _detailRow('Released At', $safeDatetime, '#111827');
    $detailRows .= _detailRow('Reason', $safeReason, $accentColor);

    return <<<HTML
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reservation Released</title></head>
    <body style="margin:0;padding:0;background:#fff7ed;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fff7ed;min-height:100vh;">
      <tr><td align="center" style="padding:36px 16px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.08);">
          <tr>
            <td style="background:linear-gradient(135deg,#f59e0b 0%,#b45309 100%);padding:30px 34px 24px;text-align:center;">
              <div style="font-size:40px;line-height:1;margin-bottom:10px;">⌛</div>
              <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Reservation Released</h1>
              <p style="margin:8px 0 0;color:rgba(255,255,255,0.9);font-size:13px;">{$system}</p>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 34px 18px;">
              <p style="margin:0 0 14px;font-size:15px;color:#111827;line-height:1.6;">Hi <strong>{$safeName}</strong>,</p>
              <p style="margin:0 0 18px;font-size:14.5px;color:#1f2937;line-height:1.7;">
                Your reservation has been released.</p>
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;overflow:hidden;">
                <tr><td style="padding:16px 18px;">{$detailRows}</td></tr>
              </table>
              <p style="margin:18px 0 0;font-size:13.5px;color:#4b5563;line-height:1.7;">
                If this was unexpected, please contact support at <a href="mailto:{$support}" style="color:#b45309;text-decoration:none;">{$support}</a>.</p>
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 0;">
                <tr><td align="center">
                  <a href="{$website}" style="display:inline-block;background:#b45309;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:700;">Open Allocation System</a>
                </td></tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="background:#fef3c7;border-top:1px solid #fde68a;padding:18px 30px;text-align:center;">
              <p style="margin:0 0 4px;font-size:11.5px;color:#92400e;">Library Services Team</p>
              <p style="margin:0;font-size:11.5px;color:#92400e;">{$school}<br>{$address}</p>
            </td>
          </tr>
        </table>
      </td></tr>
    </table>
    </body></html>
    HTML;
}

function _buildTimeoutPlainText(
    string $studentName,
    array $seatLabels,
    array $computerLabels,
    string $reason,
    string $datetime
): string {
    $seatList = empty($seatLabels) ? 'None' : implode(', ', $seatLabels);
    $computerList = empty($computerLabels) ? 'None' : implode(', ', $computerLabels);

    return "⌛ RESERVATION RELEASED — " . SCHOOL_NAME . "\n\n"
        . "Hi {$studentName},\n\n"
        . "Your reservation has been released.\n\n"
        . "DETAILS\n-------\n"
        . "Seats      : {$seatList}\n"
        . "Computers  : {$computerList}\n"
        . "Released At: {$datetime}\n"
        . "Reason     : {$reason}\n\n"
        . "If this was unexpected, contact support at " . SUPPORT_EMAIL . ".\n\n"
        . "Library Services Team\n" . SCHOOL_NAME . "\n" . SCHOOL_ADDRESS . "\n\n"
        . "--\nThis is an automated message. Please do not reply directly.";
}

function _buildSeatConfirmedHtml(
    string $studentName,
    string $seatList,
    string $location,
    string $datetime
): string {
    $safeName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');
    $safeSeats = htmlspecialchars($seatList, ENT_QUOTES, 'UTF-8');
    $safeLocation = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');
    $safeDatetime = htmlspecialchars($datetime, ENT_QUOTES, 'UTF-8');
    $support = htmlspecialchars(SUPPORT_EMAIL, ENT_QUOTES, 'UTF-8');
    $school = htmlspecialchars(SCHOOL_NAME, ENT_QUOTES, 'UTF-8');
    $system = htmlspecialchars(SYSTEM_NAME, ENT_QUOTES, 'UTF-8');
    $address = htmlspecialchars(SCHOOL_ADDRESS, ENT_QUOTES, 'UTF-8');
    $website = htmlspecialchars(SCHOOL_WEBSITE, ENT_QUOTES, 'UTF-8');

    return <<<HTML
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reservation Confirmed</title></head>
    <body style="margin:0;padding:0;background:#f3f4f6;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;min-height:100vh;">
      <tr><td align="center" style="padding:34px 16px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(15,23,42,0.10);">
          <tr>
            <td style="background:linear-gradient(135deg,#16a34a 0%,#15803d 100%);padding:30px 34px 26px;text-align:center;">
              <div style="font-size:40px;line-height:1;margin-bottom:10px;">✅</div>
              <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Reservation Confirmed</h1>
              <p style="margin:8px 0 0;color:rgba(255,255,255,0.88);font-size:13px;">{$system}</p>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 34px 18px;">
              <p style="margin:0 0 14px;font-size:15px;color:#111827;line-height:1.6;">Hi <strong>{$safeName}</strong>,</p>
              <p style="margin:0 0 18px;font-size:14.5px;color:#1f2937;line-height:1.7;">
                Your pending reservation has been confirmed after your RFID TIME_IN.</p>
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
                <tr><td style="padding:16px 18px;">
                  <p style="margin:0 0 8px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Seat</p>
                  <p style="margin:0 0 12px;font-size:15px;color:#065f46;font-weight:700;">{$safeSeats}</p>
                  <p style="margin:0 0 8px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Location</p>
                  <p style="margin:0 0 12px;font-size:14px;color:#1f2937;font-weight:600;">{$safeLocation}</p>
                  <p style="margin:0 0 8px;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">Confirmed At</p>
                  <p style="margin:0;font-size:14px;color:#1f2937;font-weight:600;">{$safeDatetime}</p>
                </td></tr>
              </table>
              <p style="margin:18px 0 0;font-size:13.5px;color:#4b5563;line-height:1.7;">
                If you no longer need this allocation, you can release it from the portal.</p>
              <p style="margin:16px 0 0;font-size:13.5px;color:#4b5563;line-height:1.7;">
                Need help? Contact <a href="mailto:{$support}" style="color:#16a34a;text-decoration:none;">{$support}</a>.</p>
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 0;">
                <tr><td align="center">
                  <a href="{$website}" style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:700;">Open Allocation System</a>
                </td></tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:18px 30px;text-align:center;">
              <p style="margin:0 0 4px;font-size:11.5px;color:#6b7280;">Library Services Team</p>
              <p style="margin:0;font-size:11.5px;color:#6b7280;">{$school}<br>{$address}</p>
            </td>
          </tr>
        </table>
      </td></tr>
    </table>
    </body></html>
    HTML;
}

function _buildSeatConfirmedPlainText(
    string $studentName,
    string $seatList,
    string $location,
    string $datetime
): string {
    return "✅ RESERVATION CONFIRMED — " . SCHOOL_NAME . "\n\n"
        . "Hi {$studentName},\n\n"
        . "Your pending reservation has been confirmed after your RFID TIME_IN.\n\n"
        . "DETAILS\n-------\n"
        . "Seat       : {$seatList}\n"
        . "Location   : {$location}\n"
        . "Confirmed  : {$datetime}\n\n"
        . "If you no longer need this allocation, you can release it from the portal.\n"
        . "Support: " . SUPPORT_EMAIL . "\n\n"
        . "Library Services Team\n" . SCHOOL_NAME . "\n" . SCHOOL_ADDRESS . "\n\n"
        . "--\nThis is an automated message. Please do not reply directly.";
}