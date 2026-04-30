<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_helper.php';

const EMAIL_WORKER_BATCH_SIZE = 10;
const EMAIL_WORKER_MAX_ATTEMPTS = 6;
const EMAIL_WORKER_IDLE_SLEEP_SECONDS = 2;
// If the worker is interrupted mid-batch, don't leave jobs stuck in 'processing' for long.
const EMAIL_WORKER_STALE_PROCESSING_MINUTES = 2;

// Track claimed job IDs so we can safely requeue them if the worker dies mid-batch.
$GLOBALS['EMAIL_WORKER_CLAIMED_IDS'] = [];

function emailWorkerTrackClaimedIds(array $ids): void
{
    if (!isset($GLOBALS['EMAIL_WORKER_CLAIMED_IDS']) || !is_array($GLOBALS['EMAIL_WORKER_CLAIMED_IDS'])) {
        $GLOBALS['EMAIL_WORKER_CLAIMED_IDS'] = [];
    }

    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $GLOBALS['EMAIL_WORKER_CLAIMED_IDS'][(string) $id] = true;
        }
    }
}

function emailWorkerUntrackClaimedId(int $id): void
{
    $key = (string) $id;
    if (isset($GLOBALS['EMAIL_WORKER_CLAIMED_IDS'][$key])) {
        unset($GLOBALS['EMAIL_WORKER_CLAIMED_IDS'][$key]);
    }
}

function emailWorkerBackoffSeconds(int $attemptNumber): int
{
    $attemptNumber = max(1, $attemptNumber);
    // 5s, 10s, 20s, 40s, 80s, 160s, then cap
    $seconds = 5 * (2 ** min($attemptNumber - 1, 6));
    return (int) min(300, $seconds);
}

function emailWorkerResetStaleProcessing(mysqli $conn): int
{
    $minutes = EMAIL_WORKER_STALE_PROCESSING_MINUTES;
    $conn->query(
        "UPDATE email_queue
         SET status='pending',
             available_at=NOW(),
             last_error=CONCAT('[worker] Reset stale processing at ', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'), '. ', COALESCE(last_error, '')),
             updated_at=CURRENT_TIMESTAMP
         WHERE status='processing'
           AND updated_at < (NOW() - INTERVAL {$minutes} MINUTE)"
    );

    return max(0, (int) $conn->affected_rows);
}

/**
 * Claim a batch of pending jobs safely.
 *
 * Uses a short transaction + SELECT ... FOR UPDATE to lock rows,
 * then flips them to status=processing and commits quickly.
 */
function emailWorkerClaimBatch(mysqli $conn, int $limit): array
{
    $jobs = [];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT id, type, to_email, to_name, payload_json, attempts
             FROM email_queue
             WHERE status='pending' AND available_at <= NOW()
             ORDER BY available_at ASC, id ASC
             LIMIT ?
             FOR UPDATE"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['attempts'] = (int) ($row['attempts'] ?? 0);
            $jobs[] = $row;
        }
        $stmt->close();

        if (empty($jobs)) {
            $conn->commit();
            return [];
        }

        $ids = array_map(static fn(array $j): int => (int) ($j['id'] ?? 0), $jobs);
        $ids = array_values(array_filter($ids, static fn(int $v): bool => $v > 0));
        if (empty($ids)) {
            $conn->commit();
            return [];
        }

        $idList = implode(',', $ids);
        $conn->query(
            "UPDATE email_queue
             SET status='processing', updated_at=CURRENT_TIMESTAMP
             WHERE id IN ({$idList})"
        );

        $conn->commit();

        emailWorkerTrackClaimedIds($ids);
        return $jobs;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function emailWorkerMarkSent(mysqli $conn, int $jobId): void
{
    $stmt = $conn->prepare(
        "UPDATE email_queue
         SET status='sent', sent_at=NOW(), last_error=NULL, updated_at=CURRENT_TIMESTAMP
         WHERE id=?"
    );
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $stmt->close();

    emailWorkerUntrackClaimedId($jobId);
}

function emailWorkerMarkFailure(mysqli $conn, int $jobId, int $currentAttempts, string $error): void
{
    $newAttempts = max(0, $currentAttempts) + 1;
    $error = trim($error);
    if ($error === '') {
        $error = 'Unknown error';
    }
    if (strlen($error) > 2000) {
        $error = substr($error, 0, 2000);
    }

    $status = $newAttempts >= EMAIL_WORKER_MAX_ATTEMPTS ? 'failed' : 'pending';
    $delaySeconds = emailWorkerBackoffSeconds($newAttempts);

    // Use MySQL time for available_at so it stays comparable to NOW() in the claim query.
    $stmt = $conn->prepare(
        "UPDATE email_queue
         SET status=?,
             attempts=?,
             available_at=DATE_ADD(NOW(), INTERVAL ? SECOND),
             last_error=?,
             updated_at=CURRENT_TIMESTAMP
         WHERE id=?"
    );
    $stmt->bind_param('siisi', $status, $newAttempts, $delaySeconds, $error, $jobId);
    $stmt->execute();
    $stmt->close();

    emailWorkerUntrackClaimedId($jobId);
}

function emailWorkerSendJob(array $job): bool
{
    $type = trim((string) ($job['type'] ?? ''));
    $toEmail = trim((string) ($job['to_email'] ?? ''));
    $toName = trim((string) ($job['to_name'] ?? ''));
    if ($toName === '') {
        $toName = 'Student';
    }

    $payloadRaw = (string) ($job['payload_json'] ?? '');
    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid payload_json (not valid JSON).');
    }

    if ($type === 'seat_confirmed') {
        $seatLabels = $payload['seat_labels'] ?? [];
        if (!is_array($seatLabels)) {
            $seatLabels = [];
        }
        $location = (string) ($payload['location'] ?? 'Study Area - Library');
        $datetime = (string) ($payload['datetime'] ?? '');

        return sendSeatConfirmedEmail($toEmail, $toName, $seatLabels, $location, $datetime);
    }

    if ($type === 'reservation_timeout') {
        $seatLabels = $payload['seat_labels'] ?? [];
        $computerLabels = $payload['computer_labels'] ?? [];
        if (!is_array($seatLabels)) {
            $seatLabels = [];
        }
        if (!is_array($computerLabels)) {
            $computerLabels = [];
        }
        $reason = (string) ($payload['reason'] ?? 'TIME_OUT');
        $datetime = (string) ($payload['datetime'] ?? '');

        return sendReservationTimeoutEmail($toEmail, $toName, $seatLabels, $computerLabels, $reason, $datetime);
    }

    throw new RuntimeException('Unknown email_queue type: ' . $type);
}

function emailWorkerProcessBatch(mysqli $conn, int $batchSize): int
{
    $jobs = emailWorkerClaimBatch($conn, $batchSize);
    if (empty($jobs)) {
        return 0;
    }

    $processed = 0;
    foreach ($jobs as $job) {
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            continue;
        }

        $attempts = (int) ($job['attempts'] ?? 0);
        try {
            $sent = emailWorkerSendJob($job);
            if ($sent) {
                emailWorkerMarkSent($conn, $jobId);
            } else {
                throw new RuntimeException('Sender returned false.');
            }
        } catch (Throwable $e) {
            try {
                emailWorkerMarkFailure($conn, $jobId, $attempts, $e->getMessage());
            } catch (Throwable $markErr) {
                // If we can't update the row, leave it tracked so shutdown/stale-reset can recover it.
                error_log('[email_worker] Failed to mark job failure id=' . $jobId . ': ' . $markErr->getMessage());
                throw $markErr;
            }
        }

        $processed += 1;
    }

    return $processed;
}

$argv = $_SERVER['argv'] ?? [];
$daemonMode = in_array('--daemon', $argv, true);

emailQueueEnsureTable($conn);

register_shutdown_function(static function () use ($conn): void {
    $tracked = [];
    if (isset($GLOBALS['EMAIL_WORKER_CLAIMED_IDS']) && is_array($GLOBALS['EMAIL_WORKER_CLAIMED_IDS'])) {
        $tracked = array_keys($GLOBALS['EMAIL_WORKER_CLAIMED_IDS']);
    }
    if (empty($tracked)) {
        return;
    }

    $ids = array_values(array_filter(array_map('intval', $tracked), static fn(int $v): bool => $v > 0));
    if (empty($ids)) {
        return;
    }

    // Always requeue tracked jobs on shutdown to avoid leaving them stuck in 'processing'.
    // Note: If the worker is terminated mid-send, a retry could send a duplicate email.
    $last = error_get_last();
    $msg = '[worker] Requeued on shutdown';
    if (is_array($last) && isset($last['message'], $last['file'], $last['line'])) {
        $msg .= ' | last_error=' . (string) $last['message'] . ' @ ' . (string) $last['file'] . ':' . (string) $last['line'];
    }
    if (strlen($msg) > 1500) {
        $msg = substr($msg, 0, 1500);
    }

    $idList = implode(',', $ids);
    $msgEsc = $conn->real_escape_string($msg);

    try {
        $conn->query(
            "UPDATE email_queue
             SET status='pending',
                 available_at=NOW(),
                 last_error=CONCAT('{$msgEsc}', ' | ', COALESCE(last_error, '')),
                 updated_at=CURRENT_TIMESTAMP
             WHERE status='processing' AND id IN ({$idList})"
        );
    } catch (Throwable $e) {
        // Last-ditch: just log.
        error_log('[email_worker] Shutdown requeue failed: ' . $e->getMessage());
    }
});

if ($daemonMode) {
    set_time_limit(0);
    ignore_user_abort(true);

    error_log('[email_worker] Daemon mode started.');
    while (true) {
        try {
            emailWorkerResetStaleProcessing($conn);
        } catch (Throwable $e) {
            error_log('[email_worker] Stale reset failed: ' . $e->getMessage());
        }

        do {
            try {
                $count = emailWorkerProcessBatch($conn, EMAIL_WORKER_BATCH_SIZE);
            } catch (Throwable $e) {
                error_log('[email_worker] Batch failed: ' . $e->getMessage());
                $count = 0;
            }
        } while ($count > 0);

        sleep(EMAIL_WORKER_IDLE_SLEEP_SECONDS);
    }
}

// Default: process queue once (useful for manual runs / cron-style scheduling)
try {
    emailWorkerResetStaleProcessing($conn);
} catch (Throwable $e) {
    error_log('[email_worker] Stale reset failed: ' . $e->getMessage());
}

$total = 0;
do {
    try {
        $count = emailWorkerProcessBatch($conn, EMAIL_WORKER_BATCH_SIZE);
    } catch (Throwable $e) {
        error_log('[email_worker] Batch failed: ' . $e->getMessage());
        break;
    }
    $total += $count;
} while ($count > 0);

error_log('[email_worker] Done. Processed jobs: ' . $total);