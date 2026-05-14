-- Migration: create faculty_profiles table
-- Run this against your MySQL database (phpMyAdmin or mysql CLI).

CREATE TABLE IF NOT EXISTS `faculty_profiles` (
  `user_id` int(11) NOT NULL,
  `faculty_level` varchar(32) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_faculty_profiles_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Rollback:
-- DROP TABLE IF EXISTS `faculty_profiles`;
