<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit();
}
?>

<h2>Welcome, <?php echo $_SESSION['username']; ?>!</h2>
<p>Role: <?php echo $_SESSION['role']; ?></p>
<a href="logout.php">Logout</a>