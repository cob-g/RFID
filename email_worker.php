<?php
require 'db.php';
require 'email_helper.php';

$result = $conn->query("SELECT * FROM email_queue WHERE status='pending' LIMIT 5");

while ($row = $result->fetch_assoc()) {
    try {
        // send email using your existing helper
        $sent = false;

        if (strpos($row['subject'], 'Seat') !== false) {
            $sent = sendSeatEmail($row['to_email'], 'Student', 'Seat X'); // optional: parse actual data
        } else {
            $sent = sendComputerEmail($row['to_email'], 'Student', 'PC X'); // optional: parse actual data
        }

        $status = $sent ? 'sent' : 'failed';
        $stmt = $conn->prepare("UPDATE email_queue SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $row['id']);
        $stmt->execute();

    } catch (Exception $e) {
        $stmt = $conn->prepare("UPDATE email_queue SET status='failed' WHERE id=?");
        $stmt->bind_param("i", $row['id']);
        $stmt->execute();
    }
}