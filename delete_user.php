<?php
include 'auth.php';
requireRole('admin', 'superadmin');
include 'db.php';

enforceOperatingHoursPageGate(
	$conn,
	['admin'],
	'Admin Access Temporarily Closed',
	'Admin account management is available only during operating hours.'
);

if (!isset($_GET['id'])) {
	exit('No user ID specified');
}

$id = (int) $_GET['id'];
if ($id <= 0) {
	exit('Invalid user ID specified.');
}

$stmt = $conn->prepare('SELECT id, username, role, email FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
	exit('User not found.');
}

$stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

auditLogWrite($conn, [
	'actor_user_id' => (int) ($_SESSION['user_id'] ?? 0),
	'actor_username' => (string) ($_SESSION['username'] ?? ''),
	'actor_role' => (string) ($_SESSION['role'] ?? ''),
	'action' => 'user_delete',
	'target_type' => 'user',
	'target_id' => (int) ($user['id'] ?? 0),
	'target_label' => (string) ($user['username'] ?? ''),
	'details' => [
		'role' => (string) ($user['role'] ?? ''),
		'email' => (string) ($user['email'] ?? ''),
	],
	'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
]);

header('Location: admin_dashboard.php?tab=users');
exit;