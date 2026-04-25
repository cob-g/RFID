<?php
$password = 'superAdmin';
$hashed = password_hash($password, PASSWORD_DEFAULT);
echo $hashed;
?>