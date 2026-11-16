-- ============================================================
-- LevelUp Life - Gamified Daily Habit Tracker Database Schema
-- Compatible with MySQL 5.7+ / 8.0+ and MariaDB 10.3+
-- ============================================================

CREATE DATABASE IF NOT EXISTS `levelup_habit_tracker`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `levelup_habit_tracker`;

-- ------------------------------------------------------------
-- 1. USERS TABLE (Authentication & RPG Progression)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(80) NOT NULL,
    `avatar_color` VARCHAR(30) DEFAULT 'indigo',
    `xp` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_xp_earned` INT UNSIGNED NOT NULL DEFAULT 0,
    `best_streak` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_xp` (`xp` DESC),
    INDEX `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. HABITS TABLE (Per-User Custom & Default Habits)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `habits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `habit_key` VARCHAR(64) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `category` VARCHAR(60) NOT NULL DEFAULT 'Exercises',
    `icon` VARCHAR(60) NOT NULL DEFAULT 'fa-dumbbell',
    `target_type` ENUM('boolean', 'numeric') NOT NULL DEFAULT 'boolean',
    `target_goal` INT UNSIGNED NOT NULL DEFAULT 1,
    `target_unit` VARCHAR(30) DEFAULT '',
    `xp` INT UNSIGNED NOT NULL DEFAULT 20,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_habit_key` (`user_id`, `habit_key`),
    INDEX `idx_habits_user` (`user_id`, `is_active`),
    CONSTRAINT `fk_habits_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. HABIT LOGS TABLE (Present & Historical Daily Tracking)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `habit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `habit_key` VARCHAR(64) NOT NULL,
    `log_date` DATE NOT NULL,
    `completed` TINYINT(1) NOT NULL DEFAULT 0,
    `progress` INT UNSIGNED NOT NULL DEFAULT 0,
    `xp_awarded` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_habit_date` (`user_id`, `habit_key`, `log_date`),
    INDEX `idx_logs_user_date` (`user_id`, `log_date`),
    CONSTRAINT `fk_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
