<?php
require 'email_helper.php';

$result = sendSeatEmail(
    "kyledomingo783@gmail.com",
    "Test User",
    "S-01",
    "Test Area"
);

var_dump($result);