<?php
session_start();

define('DB_OPTIONAL', true);
include __DIR__ . '/db.php';

if (isset($_SESSION['user_id']) && $conn instanceof mysqli) {
	authLogWrite($conn, [
		'user_id' => (int) $_SESSION['user_id'],
		'username' => (string) ($_SESSION['username'] ?? ''),
		'role' => (string) ($_SESSION['role'] ?? ''),
		'identity' => (string) ($_SESSION['username'] ?? ''),
		'action' => 'logout',
		'session_id' => session_id(),
		'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
		'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
	]);
}

session_unset();
session_destroy();
header("Location: index.php");
exit();
