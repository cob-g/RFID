<?php
require_once 'auth.php';
requireLogin();
requireRole('admin', 'superadmin', 'librarian', 'assistant');
require_once 'db.php';

enforceOperatingHoursPageGate(
    $conn,
    ['admin', 'librarian', 'assistant'],
    'Staff Access Temporarily Closed',
    'Reservation release actions are available only during operating hours.'
);


if (!isset($_GET['type'], $_GET['id'], $_GET['csrf'])) {
    http_response_code(400);
    exit('Invalid request.');
}


if (!isset($_SESSION['csrf_token']) || $_GET['csrf'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$type = $_GET['type'];
$id = (int) $_GET['id'];

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid ID.');
}

$label = '';
$reservedBy = null;
if ($type === 'seat') {
    $lookup = $conn->prepare('SELECT seat_number, reserved_by FROM seats WHERE id = ? LIMIT 1');
    $lookup->bind_param('i', $id);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$row) {
        http_response_code(404);
        exit('Seat not found.');
    }
    $label = (string) ($row['seat_number'] ?? '');
    $reservedBy = $row['reserved_by'] !== null ? (int) $row['reserved_by'] : null;
} elseif ($type === 'computer') {
    $lookup = $conn->prepare('SELECT computer_number, reserved_by FROM computers WHERE id = ? LIMIT 1');
    $lookup->bind_param('i', $id);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!$row) {
        http_response_code(404);
        exit('Computer not found.');
    }
    $label = (string) ($row['computer_number'] ?? '');
    $reservedBy = $row['reserved_by'] !== null ? (int) $row['reserved_by'] : null;
}

switch ($type) {
    case 'seat':
        $stmt = $conn->prepare(
            "UPDATE seats 
             SET status = 'available', reserved_by = NULL, reserved_at = NULL, expires_at = NULL 
             WHERE id = ?"
        );
        break;

    case 'computer':
        $stmt = $conn->prepare(
            "UPDATE computers 
             SET status = 'available', reserved_by = NULL, reserved_at = NULL, expires_at = NULL 
             WHERE id = ?"
        );
        break;

    default:
        http_response_code(400);
        exit('Invalid type.');
}

$stmt->bind_param("i", $id);
$stmt->execute();

auditLogWrite($conn, [
    'actor_user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'actor_username' => (string) ($_SESSION['username'] ?? ''),
    'actor_role' => (string) ($_SESSION['role'] ?? ''),
    'action' => 'reservation_release',
    'target_type' => $type,
    'target_id' => $id,
    'target_label' => $label,
    'details' => [
        'reserved_by' => $reservedBy,
    ],
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
]);

$_SESSION['message'] = ucfirst($type) . " reservation removed successfully.";
header("Location: admin_dashboard.php");
exit;
