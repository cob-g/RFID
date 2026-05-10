<?php
declare(strict_types=1);

const LIBRARY_HOURS_DEFAULT_OPEN = '08:00:00';
const LIBRARY_HOURS_DEFAULT_CLOSE = '17:00:00';
const LIBRARY_HOURS_TIMEZONE = 'Asia/Manila';

function libraryHoursTimezone(): DateTimeZone
{
    static $tz = null;
    if ($tz instanceof DateTimeZone) {
        return $tz;
    }

    $tz = new DateTimeZone(LIBRARY_HOURS_TIMEZONE);
    return $tz;
}

function libraryHoursNormalizeTimeInput(string $raw): ?string
{
    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    foreach (['H:i:s', 'H:i'] as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $value, libraryHoursTimezone());
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors)
            ? (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)
            : false;

        if ($dt instanceof DateTimeImmutable && !$hasErrors) {
            return $dt->format('H:i:s');
        }
    }

    return null;
}

function libraryHoursTimeToSeconds(string $time): ?int
{
    $normalized = libraryHoursNormalizeTimeInput($time);
    if ($normalized === null) {
        return null;
    }

    [$hour, $minute, $second] = array_map('intval', explode(':', $normalized));
    return ($hour * 3600) + ($minute * 60) + $second;
}

function libraryHoursCrossesMidnight(array $config): bool
{
    $openSeconds = libraryHoursTimeToSeconds((string) ($config['open_time'] ?? LIBRARY_HOURS_DEFAULT_OPEN));
    $closeSeconds = libraryHoursTimeToSeconds((string) ($config['close_time'] ?? LIBRARY_HOURS_DEFAULT_CLOSE));

    if ($openSeconds === null || $closeSeconds === null) {
        return false;
    }

    return $openSeconds > $closeSeconds;
}

function libraryHoursIsOperationalWeekday(DateTimeInterface $date): bool
{
    // Monday(1) to Saturday(6) are open days; Sunday(0) is closed by policy.
    // return ((int) $date->format('w')) !== 0;

    // For testing purposes, you can uncomment the following line to make Sunday an operational day.
    return true;
}

function libraryHoursFindNextOpenAt(DateTimeImmutable $nowAtTz, array $config, DateTimeZone $tz): DateTimeImmutable
{
    $openTime = libraryHoursNormalizeTimeInput((string) ($config['open_time'] ?? LIBRARY_HOURS_DEFAULT_OPEN));
    if ($openTime === null) {
        $openTime = LIBRARY_HOURS_DEFAULT_OPEN;
    }

    $baseDate = $nowAtTz->setTime(0, 0, 0);
    for ($offset = 0; $offset <= 8; $offset += 1) {
        $candidateDate = $baseDate->modify('+' . $offset . ' day');
        if (!libraryHoursIsOperationalWeekday($candidateDate)) {
            continue;
        }

        $candidateOpen = new DateTimeImmutable($candidateDate->format('Y-m-d') . ' ' . $openTime, $tz);
        if ($candidateOpen > $nowAtTz) {
            return $candidateOpen;
        }
    }

    return new DateTimeImmutable($baseDate->modify('+1 day')->format('Y-m-d') . ' ' . $openTime, $tz);
}

function libraryHoursTableExists(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $table = 'system_hours';
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->bind_result($one);
    $exists = $stmt->fetch();
    $stmt->close();

    $cache = (bool) $exists;
    return $cache;
}

function libraryHoursEnsureTable(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS system_hours (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            open_time TIME NOT NULL DEFAULT '08:00:00',
            close_time TIME NOT NULL DEFAULT '17:00:00',
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_system_hours_updated_by (updated_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function libraryHoursValidateRange(string $openTime, string $closeTime): void
{
    $openNorm = libraryHoursNormalizeTimeInput($openTime);
    $closeNorm = libraryHoursNormalizeTimeInput($closeTime);

    if ($openNorm === null || $closeNorm === null) {
        throw new InvalidArgumentException('Please provide valid opening and closing times.');
    }

    [$openHour, $openMinute, $openSecond] = array_map('intval', explode(':', $openNorm));
    [$closeHour, $closeMinute, $closeSecond] = array_map('intval', explode(':', $closeNorm));

    $openTotal = ($openHour * 3600) + ($openMinute * 60) + $openSecond;
    $closeTotal = ($closeHour * 3600) + ($closeMinute * 60) + $closeSecond;

    if ($openTotal === $closeTotal) {
        throw new InvalidArgumentException('Opening and closing times cannot be the same.');
    }
}

function libraryHoursGetConfig(mysqli $conn): array
{
    $config = [
        'open_time' => LIBRARY_HOURS_DEFAULT_OPEN,
        'close_time' => LIBRARY_HOURS_DEFAULT_CLOSE,
    ];

    if (!libraryHoursTableExists($conn)) {
        return $config;
    }

    $stmt = $conn->prepare('SELECT open_time, close_time FROM system_hours WHERE id = 1 LIMIT 1');
    $stmt->execute();
    $stmt->bind_result($openTime, $closeTime);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        return $config;
    }

    $normalizedOpen = libraryHoursNormalizeTimeInput((string) $openTime);
    $normalizedClose = libraryHoursNormalizeTimeInput((string) $closeTime);

    if ($normalizedOpen !== null) {
        $config['open_time'] = $normalizedOpen;
    }
    if ($normalizedClose !== null) {
        $config['close_time'] = $normalizedClose;
    }

    return $config;
}

function libraryHoursSetConfig(mysqli $conn, string $openTime, string $closeTime, ?int $updatedBy = null): void
{
    $normalizedOpen = libraryHoursNormalizeTimeInput($openTime);
    $normalizedClose = libraryHoursNormalizeTimeInput($closeTime);

    if ($normalizedOpen === null || $normalizedClose === null) {
        throw new InvalidArgumentException('Please provide valid opening and closing times.');
    }

    libraryHoursValidateRange($normalizedOpen, $normalizedClose);
    libraryHoursEnsureTable($conn);

    if ($updatedBy === null || $updatedBy <= 0) {
        $stmt = $conn->prepare(
            "INSERT INTO system_hours (id, open_time, close_time, updated_by)
             VALUES (1, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
                open_time = VALUES(open_time),
                close_time = VALUES(close_time),
                updated_by = NULL,
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->bind_param('ss', $normalizedOpen, $normalizedClose);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO system_hours (id, open_time, close_time, updated_by)
             VALUES (1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                open_time = VALUES(open_time),
                close_time = VALUES(close_time),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->bind_param('ssi', $normalizedOpen, $normalizedClose, $updatedBy);
    }

    $stmt->execute();
    $stmt->close();
}

function libraryHoursFormatTime(string $time): string
{
    $normalized = libraryHoursNormalizeTimeInput($time);
    if ($normalized === null) {
        return $time;
    }

    $dt = DateTimeImmutable::createFromFormat('!H:i:s', $normalized, libraryHoursTimezone());
    if (!$dt instanceof DateTimeImmutable) {
        return $time;
    }

    return $dt->format('g:i A');
}

function libraryHoursBuildDailyWindowLabel(array $config): string
{
    $openLabel = libraryHoursFormatTime((string) ($config['open_time'] ?? LIBRARY_HOURS_DEFAULT_OPEN));
    $closeLabel = libraryHoursFormatTime((string) ($config['close_time'] ?? LIBRARY_HOURS_DEFAULT_CLOSE));
    $overnightSuffix = libraryHoursCrossesMidnight($config) ? ' (overnight)' : '';

    return 'Monday to Saturday, ' . $openLabel . ' to ' . $closeLabel . $overnightSuffix . '; Sunday closed.';
}

function libraryHoursEvaluate(mysqli $conn, ?DateTimeImmutable $now = null): array
{
    $config = libraryHoursGetConfig($conn);
    $tz = libraryHoursTimezone();
    $nowAtTz = $now instanceof DateTimeImmutable
        ? $now->setTimezone($tz)
        : new DateTimeImmutable('now', $tz);

    $openTime = (string) ($config['open_time'] ?? LIBRARY_HOURS_DEFAULT_OPEN);
    $closeTime = (string) ($config['close_time'] ?? LIBRARY_HOURS_DEFAULT_CLOSE);

    $todayDate = $nowAtTz->format('Y-m-d');
    $todayOpen = new DateTimeImmutable($todayDate . ' ' . $openTime, $tz);
    $todayClose = new DateTimeImmutable($todayDate . ' ' . $closeTime, $tz);

    $isSundayClosed = !libraryHoursIsOperationalWeekday($nowAtTz);
    $crossesMidnight = libraryHoursCrossesMidnight($config);

    $windowStart = $todayOpen;
    $windowEnd = $todayClose;
    if ($crossesMidnight && $windowEnd <= $windowStart) {
        $windowEnd = $windowEnd->modify('+1 day');
    }

    $isOpen = false;
    if (!$isSundayClosed) {
        if (!$crossesMidnight) {
            $isOpen = $nowAtTz >= $todayOpen && $nowAtTz < $todayClose;
            $windowStart = $todayOpen;
            $windowEnd = $todayClose;
        } else {
            $openSeconds = libraryHoursTimeToSeconds($openTime);
            $nowSeconds = libraryHoursTimeToSeconds($nowAtTz->format('H:i:s'));

            $windowStartDate = $nowAtTz;
            if ($openSeconds === null || $nowSeconds === null || $nowSeconds < $openSeconds) {
                $windowStartDate = $nowAtTz->modify('-1 day');
            }

            $windowStart = new DateTimeImmutable($windowStartDate->format('Y-m-d') . ' ' . $openTime, $tz);
            $windowEnd = new DateTimeImmutable($windowStartDate->modify('+1 day')->format('Y-m-d') . ' ' . $closeTime, $tz);

            $isOpen = libraryHoursIsOperationalWeekday($windowStartDate)
                && $nowAtTz >= $windowStart
                && $nowAtTz < $windowEnd;
        }
    }

    $reason = 'open';
    if (!$isOpen) {
        if ($isSundayClosed) {
            $reason = 'sunday';
        } elseif ($nowAtTz < $todayOpen) {
            $reason = 'before_open';
        } else {
            $reason = 'after_close';
        }
    }

    $nextOpenAt = libraryHoursFindNextOpenAt($nowAtTz, $config, $tz);

    return [
        'is_open' => $isOpen,
        'reason' => $reason,
        'config' => $config,
        'now' => $nowAtTz,
        'today_open_at' => $windowStart,
        'today_close_at' => $windowEnd,
        'next_open_at' => $nextOpenAt,
        'hours_label' => libraryHoursBuildDailyWindowLabel($config),
        'crosses_midnight' => $crossesMidnight,
    ];
}

function libraryHoursBuildNotice(array $state): array
{
    $reason = (string) ($state['reason'] ?? 'after_close');
    $hoursLabel = (string) ($state['hours_label'] ?? 'Monday to Saturday schedule applies.');
    $nextOpenAt = $state['next_open_at'] ?? null;

    $reopenDisplay = '';
    $reopenIso = '';
    if ($nextOpenAt instanceof DateTimeInterface) {
        $reopenDisplay = $nextOpenAt->format('l, F j, Y \a\t g:i A');
        $reopenIso = $nextOpenAt->format(DateTimeInterface::ATOM);
    }

    $reasonLine = 'The current time is outside operating hours.';
    if ($reason === 'sunday') {
        $reasonLine = 'Sunday operations are closed by policy.';
    } elseif ($reason === 'before_open') {
        $reasonLine = 'The system has not opened for today yet.';
    } elseif ($reason === 'after_close') {
        $reasonLine = 'The system has already closed for today.';
    }

    return [
        'reason_line' => $reasonLine,
        'hours_line' => $hoursLabel,
        'reopen_line' => $reopenDisplay !== '' ? ('Reopens on ' . $reopenDisplay . '.') : 'Reopening time is currently unavailable.',
        'reopen_display' => $reopenDisplay,
        'reopen_iso' => $reopenIso,
    ];
}

function libraryHoursApiClosedPayload(
    mysqli $conn,
    string $prefix = 'Library access is currently closed.',
    ?array $state = null
): array {
    $evaluated = is_array($state) ? $state : libraryHoursEvaluate($conn);
    $notice = libraryHoursBuildNotice($evaluated);

    $message = trim($prefix);
    if ($message !== '' && substr($message, -1) !== '.') {
        $message .= '.';
    }
    $message = trim($message . ' ' . $notice['reopen_line']);

    return [
        'status' => 'closed',
        'msg' => $message,
        'retryable' => false,
        'reopens_at' => $notice['reopen_iso'],
        'hours' => $notice['hours_line'],
    ];
}

function libraryHoursRenderClosedPage(
    mysqli $conn,
    string $title = 'Library Access Temporarily Closed',
    string $subtitle = 'This section is unavailable outside operating hours.',
    int $statusCode = 403,
    ?array $state = null,
    ?array $options = null
): never {
    $evaluated = is_array($state) ? $state : libraryHoursEvaluate($conn);
    $notice = libraryHoursBuildNotice($evaluated);

    http_response_code($statusCode);

    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $subtitleEsc = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
    $reasonEsc = htmlspecialchars((string) $notice['reason_line'], ENT_QUOTES, 'UTF-8');
    $hoursEsc = htmlspecialchars((string) $notice['hours_line'], ENT_QUOTES, 'UTF-8');
    $reopenEsc = htmlspecialchars((string) $notice['reopen_line'], ENT_QUOTES, 'UTF-8');

    $options = is_array($options) ? $options : [];
    $autoReopen = (bool) ($options['auto_reopen'] ?? false);
    $pollUrl = (string) ($options['poll_url'] ?? '');
    $redirectUrl = (string) ($options['redirect_url'] ?? '');
    $pollIntervalMs = (int) ($options['poll_interval_ms'] ?? 3000);
    if ($pollIntervalMs <= 0) {
        $pollIntervalMs = 3000;
    }

    $shouldAutoReopen = $autoReopen
        && $pollUrl !== ''
        && $redirectUrl !== ''
        && ((string) ($evaluated['reason'] ?? '')) !== 'sunday';

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Library Closed</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">';
    echo '<style>';
    echo '*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}';
    echo 'body{min-height:100vh;font-family:"DM Sans",sans-serif;background:#0a0e14;color:#f0ece4;display:flex;align-items:center;justify-content:center;padding:24px;line-height:1.6;}';
    echo '.bg{position:fixed;inset:0;background:radial-gradient(circle at 12% 18%,rgba(200,169,110,0.18),transparent 46%),radial-gradient(circle at 88% 82%,rgba(59,130,246,0.16),transparent 44%),linear-gradient(140deg,#090d13 0%,#0d131d 55%,#111927 100%);z-index:-1;}';
    echo '.panel{width:min(760px,100%);background:rgba(255,255,255,0.06);border:1px solid rgba(200,169,110,0.30);border-radius:20px;padding:30px 26px;backdrop-filter:blur(16px);box-shadow:0 18px 56px rgba(0,0,0,0.45);}';
    echo '.eyebrow{font-size:0.72rem;font-weight:600;letter-spacing:0.14em;text-transform:uppercase;color:#c8a96e;margin-bottom:8px;}';
    echo 'h1{font-family:"Cormorant Garamond",serif;font-size:2rem;font-weight:600;color:#f7efe0;line-height:1.2;margin-bottom:8px;}';
    echo '.subtitle{color:rgba(240,236,228,0.78);font-size:0.96rem;margin-bottom:20px;}';
    echo '.meta{background:rgba(10,14,20,0.52);border:1px solid rgba(200,169,110,0.20);border-radius:14px;padding:12px 14px;margin-bottom:10px;font-size:0.9rem;color:rgba(240,236,228,0.90);}';
    echo '.meta strong{color:#e7d2ad;font-weight:600;}';
    echo '.footer{margin-top:14px;font-size:0.8rem;color:rgba(240,236,228,0.56);}';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="bg"></div>';
    echo '<main class="panel">';
    echo '<div class="eyebrow">Library Schedule Notice</div>';
    echo '<h1>' . $titleEsc . '</h1>';
    echo '<p class="subtitle">' . $subtitleEsc . '</p>';
    echo '<div class="meta"><strong>Status:</strong> ' . $reasonEsc . '</div>';
    echo '<div class="meta"><strong>Operating Hours:</strong> ' . $hoursEsc . '</div>';
    echo '<div class="meta"><strong>Next Opening:</strong> ' . $reopenEsc . '</div>';
    echo '<div class="footer">Only superadmin can update opening and closing times. Sunday remains permanently closed.</div>';
    echo '</main>';
    if ($shouldAutoReopen) {
        $pollUrlJson = json_encode($pollUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $redirectUrlJson = json_encode($redirectUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo '<script>';
        echo '(function(){';
        echo 'const pollUrl=' . $pollUrlJson . ';';
        echo 'const redirectUrl=' . $redirectUrlJson . ';';
        echo 'const intervalMs=' . $pollIntervalMs . ';';
        echo 'setInterval(function(){';
        echo 'fetch(pollUrl,{cache:"no-store"}).then(function(res){return res.ok?res.json():null;}).then(function(data){';
        echo 'if(!data) return;';
        echo 'if(!("closed" in data) || data.closed === false){ window.location.href = redirectUrl; }';
        echo '}).catch(function(){});';
        echo '}, intervalMs);';
        echo '})();';
        echo '</script>';
    }
    echo '</body>';
    echo '</html>';
    exit;
}
