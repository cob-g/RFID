SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
SET time_zone = "+00:00";

CREATE TABLE `allocation_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `item_type` enum('seat','computer') NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_label` varchar(50) NOT NULL,
  `action` enum('reserved','released') NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `attendance` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(80) NOT NULL,
  `uid` varchar(23) NOT NULL,
  `date` date NOT NULL,
  `time` time NOT NULL,
  `action` enum('TIME_IN','TIME_OUT') NOT NULL,
  `event_id` varchar(16) NOT NULL,
  `device` varchar(50) NOT NULL DEFAULT 'unknown',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `rfid_cards` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `uid` varchar(32) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE `enrollment_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE `system_hours` (
  `id` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `open_time` time NOT NULL DEFAULT '08:00:00',
  `close_time` time NOT NULL DEFAULT '17:00:00',
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


INSERT INTO `system_hours` (`id`, `open_time`, `close_time`, `updated_by`) VALUES
(1, '08:00:00', '17:00:00', NULL);


CREATE TABLE `computers` (
  `id` int(11) NOT NULL,
  `computer_number` varchar(50) NOT NULL,
  `status` enum('available','reserved') DEFAULT 'available',
  `reserved_by` int(11) DEFAULT NULL,
  `reserved_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `reservation_status` enum('pending','confirmed') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


INSERT INTO `computers` (`id`, `computer_number`, `status`, `reserved_by`, `reserved_at`, `expires_at`) VALUES
(1, 'pc1', 'available', NULL, NULL, NULL),
(2, 'pc2', 'available', NULL, NULL, NULL),
(3, 'pc3', 'available', NULL, NULL, NULL),
(4, 'pc4', 'available', NULL, NULL, NULL),
(5, 'pc5', 'available', NULL, NULL, NULL),
(6, 'pc6', 'available', NULL, NULL, NULL),
(7, 'pc7', 'reserved', NULL, NULL, NULL),
(8, 'pc8', 'available', NULL, NULL, NULL),
(9, 'pc9', 'available', NULL, NULL, NULL),
(10, 'pc10', 'available', NULL, NULL, NULL);



CREATE TABLE `pending_rfid_assignments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `fulfilled` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



INSERT INTO `pending_rfid_assignments` (`id`, `user_id`, `token`, `expires_at`, `fulfilled`, `created_at`, `status`) VALUES
(2, 15, 'c59c2afa069cfe030fa64fdc6168085b183a7612ed859b7d9d374fc696ec569a', '2026-04-22 03:46:25', 0, '2026-04-22 07:44:25', 'pending');



CREATE TABLE `rfid_devices` (
  `id` int(11) NOT NULL,
  `uid` varchar(32) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE `seats` (
  `id` int(11) NOT NULL,
  `seat_number` varchar(3) NOT NULL,
  `row_num` int(11) DEFAULT NULL,
  `col_num` int(11) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `status` enum('available','reserved') DEFAULT 'available',
  `reserved_by` int(11) DEFAULT NULL,
  `reserved_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `reservation_status` enum('pending','confirmed') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


INSERT INTO `seats` (`id`, `seat_number`, `row_num`, `col_num`, `active`, `created_at`, `status`, `reserved_by`, `reserved_at`, `expires_at`) VALUES
(1, '1', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(2, '2', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(3, '3', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(4, '4', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(5, '5', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(6, '6', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(7, '7', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(8, '8', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(9, '9', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(10, '10', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(11, '11', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(12, '12', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(13, '13', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(14, '14', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(15, '15', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(16, '16', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(17, '17', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(18, '18', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(19, '19', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(20, '20', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(21, '21', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(22, '22', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(23, '23', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(24, '24', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(25, '25', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(26, '26', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(27, '27', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(28, '28', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(29, '29', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(30, '30', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(31, '31', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(32, '32', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(33, '33', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(34, '34', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(35, '35', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(36, '36', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(37, '37', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(38, '38', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(39, '39', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(40, '40', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(41, '41', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(42, '42', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(43, '43', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(44, '44', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(45, '45', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(46, '46', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(47, '47', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(48, '48', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(49, '49', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(50, '50', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(51, '51', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(52, '52', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(53, '53', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(54, '54', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(55, '55', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(56, '56', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(57, '57', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(58, '58', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(59, '59', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(60, '60', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(61, '61', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(62, '62', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(63, '63', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(64, '64', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(65, '65', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(66, '66', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(67, '67', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(68, '68', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(69, '69', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(70, '70', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(71, '71', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL),
(72, '72', NULL, NULL, 1, '2026-03-30 02:12:20', 'available', NULL, NULL, NULL);



CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','faculty','assistant','librarian','admin','superadmin') NOT NULL,
  `verification_code` varchar(10) DEFAULT NULL,
  `status` enum('pending','verified','active','suspended') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `verification_code`, `status`, `created_at`, `updated_at`) VALUES
(1, 'admin', '', '$2y$10$fVGU3MoNyLaPK8MCTpJnmuyKZ81WwBqFqyhDL6UNt8bEzZ2oBqAca', 'admin', NULL, 'active', '2025-12-12 03:10:53', '2026-04-01 03:55:20'),
(11, 'superadmin', 'superadmin@gmail.com', '$2y$10$b2juVII/OmJZF3ExFH9RruYWsjrQwRB2hKbYGk5ihLBuXWqfAyD9m', 'superadmin', NULL, 'pending', '2026-04-01 03:42:13', '2026-04-01 03:57:30'),
(15, 'kyle', 'kyledomingo783@gmail.com', '$2y$10$c.8ArdKvihcaCXe2g563LOC3R6Khf6Te5Meb37o2vF9hhSDBqs/Yi', 'student', NULL, 'verified', '2026-04-22 07:38:59', '2026-04-22 07:39:17');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `allocation_log`
--
ALTER TABLE `allocation_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `event_id` (`event_id`),
  ADD KEY `idx_uid_date` (`uid`,`date`),
  ADD KEY `idx_user_date` (`user_id`,`date`),
  ADD KEY `idx_date_action` (`date`,`action`);

--
-- Indexes for table `computers`
--
ALTER TABLE `computers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_reserved_by` (`reserved_by`),
  ADD KEY `idx_reservation_exp` (`reservation_status`,`expires_at`);

--
-- Indexes for table `enrollment_tokens`
--
ALTER TABLE `enrollment_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_enrollment_token` (`token`),
  ADD UNIQUE KEY `uq_enrollment_user` (`user_id`),
  ADD KEY `idx_enrollment_exp` (`expires_at`);

--
-- Indexes for table `system_hours`
--
ALTER TABLE `system_hours`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_system_hours_updated_by` (`updated_by`);

--
-- Indexes for table `pending_rfid_assignments`
--
ALTER TABLE `pending_rfid_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_token` (`token`),
  ADD UNIQUE KEY `uq_user` (`user_id`),
  ADD KEY `idx_exp` (`expires_at`);

--
-- Indexes for table `rfid_devices`
--
ALTER TABLE `rfid_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uid` (`uid`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `rfid_cards`
--
ALTER TABLE `rfid_cards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rfid_cards_uid` (`uid`),
  ADD UNIQUE KEY `uq_rfid_cards_user` (`user_id`),
  ADD KEY `idx_rfid_cards_status` (`status`);

--
-- Indexes for table `seats`
--
ALTER TABLE `seats`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_reserved_by` (`reserved_by`),
  ADD KEY `idx_reservation_exp` (`reservation_status`,`expires_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `allocation_log`
--
ALTER TABLE `allocation_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `computers`
--
ALTER TABLE `computers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `enrollment_tokens`
--
ALTER TABLE `enrollment_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pending_rfid_assignments`
--
ALTER TABLE `pending_rfid_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `rfid_devices`
--
ALTER TABLE `rfid_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rfid_cards`
--
ALTER TABLE `rfid_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `seats`
--
ALTER TABLE `seats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `allocation_log`
--
ALTER TABLE `allocation_log`
  ADD CONSTRAINT `allocation_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_att_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `computers`
--
ALTER TABLE `computers`
  ADD CONSTRAINT `computers_ibfk_1` FOREIGN KEY (`reserved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `enrollment_tokens`
--
ALTER TABLE `enrollment_tokens`
  ADD CONSTRAINT `fk_enrollment_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `pending_rfid_assignments`
--
ALTER TABLE `pending_rfid_assignments`
  ADD CONSTRAINT `fk_pra_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `rfid_devices`
--
ALTER TABLE `rfid_devices`
  ADD CONSTRAINT `rfid_devices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rfid_cards`
--
ALTER TABLE `rfid_cards`
  ADD CONSTRAINT `fk_rfid_cards_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- One-time rollout backfill (run after schema deploy)
-- Fill historical attendance.user_id where mapping is unambiguous.
--
UPDATE `attendance` a
JOIN `rfid_cards` c ON c.uid = a.uid AND c.status = 'active'
SET a.user_id = c.user_id
WHERE a.user_id IS NULL;

UPDATE `attendance` a
JOIN `rfid_devices` d ON d.uid = a.uid AND d.status = 'active'
SET a.user_id = d.user_id
WHERE a.user_id IS NULL;

UPDATE `attendance` a
JOIN `users` u ON u.username = a.name
SET a.user_id = u.id
WHERE a.user_id IS NULL;

UPDATE `attendance` a
JOIN `users` u ON u.email = a.name
SET a.user_id = u.id
WHERE a.user_id IS NULL;
SET FOREIGN_KEY_CHECKS = 1;

