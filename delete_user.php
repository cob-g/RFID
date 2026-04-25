<?php
include 'auth.php';
requireRole('admin', 'superadmin');
include 'db.php';

if (!isset($_GET['id'])) exit('No user ID specified');

$id = intval($_GET['id']);
mysqli_query($conn, "DELETE FROM users WHERE id=$id");
header("Location: admin_dashboard.php");
exit;
