<?php
require_once 'auth.php';
requireLogin();
requireRole('admin', 'superadmin', 'librarian', 'assistant');

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'free_item_admin.php' . ($query !== '' ? ('?' . $query) : '');
header('Location: ' . $target);
exit;
