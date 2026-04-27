<?php
require_once 'auth.php';
requireLogin();
requireRole('admin', 'superadmin');
require_once 'db.php';

enforceOperatingHoursPageGate(
    $conn,
    ['admin'],
    'Admin Access Temporarily Closed',
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

$_SESSION['message'] = ucfirst($type) . " reservation removed successfully.";
header("Location: admin_dashboard.php");
exit;
