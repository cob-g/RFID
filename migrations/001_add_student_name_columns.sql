-- Migration: add name columns to student_profiles
-- Run this against your MySQL database used by the app (e.g. via phpMyAdmin or mysql CLI).

ALTER TABLE student_profiles
  ADD COLUMN `first_name` VARCHAR(100) NOT NULL DEFAULT '' AFTER `user_id`,
  ADD COLUMN `last_name` VARCHAR(100) NOT NULL DEFAULT '' AFTER `first_name`,
  ADD COLUMN `middle_initial` VARCHAR(5) DEFAULT '' AFTER `last_name`;

-- Note: this makes the new columns default to empty strings for existing rows.
-- If you prefer NULL defaults, edit the statements accordingly before running.
