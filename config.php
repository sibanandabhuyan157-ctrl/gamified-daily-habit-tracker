<?php
/**
 * LevelUp Life - High-Performance Database & Session Configuration
 * Supports MySQL / MariaDB (XAMPP, cPanel, Cloud DB) with automatic SQLite PDO fallback.
 */

declare(strict_types=1);

// Enable output buffering & gzip compression for faster response times
if (!headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

// Configure secure & persistent PHP sessions (30 days)
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Database connection settings (reads from Environment Variables first for Cloud/Docker hosting)
define('DB_DRIVER', getenv('DB_DRIVER') ?: 'auto'); // 'mysql', 'sqlite', or 'auto'
define('DB_HOST',   getenv('DB_HOST')   ?: '127.0.0.1');
define('DB_PORT',   getenv('DB_PORT')   ?: '3306');
define('DB_NAME',   getenv('DB_NAME')   ?: 'levelup_habit_tracker');
define('DB_USER',   getenv('DB_USER')   ?: 'root');
define('DB_PASS',   getenv('DB_PASS')   ?: '');
define('SQLITE_PATH', __DIR__ . '/database/levelup.sqlite');

/**
 * Returns a singleton PDO instance with prepared statement performance optimizations.
 */
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // 1. Try MySQL if configured or in 'auto' mode
    if (DB_DRIVER === 'mysql' || DB_DRIVER === 'auto') {
        try {
            $dsnNoDb = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
            $tempPdo = new PDO($dsnNoDb, DB_USER, DB_PASS, $options);
            $tempPdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            initializeSchema($pdo, 'mysql');
            return $pdo;
        } catch (Throwable $e) {
            if (DB_DRIVER === 'mysql') {
                throw new RuntimeException('MySQL Connection Failed: ' . $e->getMessage());
            }
            // Fall through to SQLite when in 'auto' mode
        }
    }

    // 2. Zero-config SQLite fallback for instant portability & high local performance
    $dbDir = dirname(SQLITE_PATH);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }
    $pdo = new PDO('sqlite:' . SQLITE_PATH, null, null, $options);
    // Performance pragmas for SQLite (WAL mode & synchronous normal)
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    initializeSchema($pdo, 'sqlite');

    return $pdo;
}

/**
 * Automatically creates required tables if they do not exist yet.
 */
function initializeSchema(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec("
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
                INDEX `idx_users_xp` (`xp` DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE IF NOT EXISTS `habits` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `habit_key` VARCHAR(64) NOT NULL,
                `name` VARCHAR(120) NOT NULL,
                `category` VARCHAR(60) NOT NULL DEFAULT 'Exercises',
                `icon` VARCHAR(60) NOT NULL DEFAULT 'fa-dumbbell',
                `target_type` VARCHAR(20) NOT NULL DEFAULT 'boolean',
                `target_goal` INT UNSIGNED NOT NULL DEFAULT 1,
                `target_unit` VARCHAR(30) DEFAULT '',
                `xp` INT UNSIGNED NOT NULL DEFAULT 20,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_user_habit_key` (`user_id`, `habit_key`),
                INDEX `idx_habits_user` (`user_id`, `is_active`),
                CONSTRAINT `fk_habits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
                CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                display_name TEXT NOT NULL,
                avatar_color TEXT DEFAULT 'indigo',
                xp INTEGER NOT NULL DEFAULT 0,
                total_xp_earned INTEGER NOT NULL DEFAULT 0,
                best_streak INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_login TEXT DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS habits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                habit_key TEXT NOT NULL,
                name TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT 'Exercises',
                icon TEXT NOT NULL DEFAULT 'fa-dumbbell',
                target_type TEXT NOT NULL DEFAULT 'boolean',
                target_goal INTEGER NOT NULL DEFAULT 1,
                target_unit TEXT DEFAULT '',
                xp INTEGER NOT NULL DEFAULT 20,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(user_id, habit_key),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS habit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                habit_key TEXT NOT NULL,
                log_date TEXT NOT NULL,
                completed INTEGER NOT NULL DEFAULT 0,
                progress INTEGER NOT NULL DEFAULT 0,
                xp_awarded INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(user_id, habit_key, log_date),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_habits_user ON habits(user_id, is_active);
            CREATE INDEX IF NOT EXISTS idx_logs_user_date ON habit_logs(user_id, log_date);
        ");
    }
}

/**
 * Default starter habits for newly registered users.
 */
function getDefaultHabits(): array
{
    return [
        [
            'id'         => 'h1',
            'name'       => 'Morning Workout',
            'category'   => 'Exercises',
            'icon'       => 'fa-dumbbell',
            'targetType' => 'boolean',
            'targetGoal' => 1,
            'targetUnit' => '',
            'xp'         => 25,
        ],
        [
            'id'         => 'h2',
            'name'       => 'Deep Work / Coding',
            'category'   => 'Daily Work',
            'icon'       => 'fa-briefcase',
            'targetType' => 'numeric',
            'targetGoal' => 4,
            'targetUnit' => 'hrs',
            'xp'         => 30,
        ],
        [
            'id'         => 'h3',
            'name'       => 'Study & Reading',
            'category'   => 'Study Times',
            'icon'       => 'fa-book-open',
            'targetType' => 'numeric',
            'targetGoal' => 60,
            'targetUnit' => 'mins',
            'xp'         => 20,
        ],
        [
            'id'         => 'h4',
            'name'       => 'Quality Sleep',
            'category'   => 'Sleep Time',
            'icon'       => 'fa-bed',
            'targetType' => 'numeric',
            'targetGoal' => 8,
            'targetUnit' => 'hrs',
            'xp'         => 25,
        ],
    ];
}

/**
 * Seed default habits into the database for a new user.
 */
function seedDefaultHabitsForUser(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare("
        INSERT INTO habits (user_id, habit_key, name, category, icon, target_type, target_goal, target_unit, xp)
        VALUES (:user_id, :habit_key, :name, :category, :icon, :target_type, :target_goal, :target_unit, :xp)
    ");

    foreach (getDefaultHabits() as $h) {
        $stmt->execute([
            ':user_id'     => $userId,
            ':habit_key'   => $h['id'],
            ':name'        => $h['name'],
            ':category'    => $h['category'],
            ':icon'        => $h['icon'],
            ':target_type' => $h['targetType'],
            ':target_goal' => $h['targetGoal'],
            ':target_unit' => $h['targetUnit'],
            ':xp'          => $h['xp'],
        ]);
    }
}

/**
 * Fetches full hydrated state (user profile, active habits, all historical & present logs, and leaderboard)
 */
function getUserFullState(PDO $pdo, int $userId): ?array
{
    $uStmt = $pdo->prepare("SELECT id, username, email, display_name, avatar_color, xp, total_xp_earned, best_streak, created_at FROM users WHERE id = :id");
    $uStmt->execute([':id' => $userId]);
    $user = $uStmt->fetch();

    if (!$user) {
        return null;
    }

    // Fetch user's habits
    $hStmt = $pdo->prepare("
        SELECT habit_key AS id, name, category, icon, target_type AS targetType,
               target_goal AS targetGoal, target_unit AS targetUnit, xp, created_at AS createdAt
        FROM habits
        WHERE user_id = :user_id AND is_active = 1
        ORDER BY id ASC
    ");
    $hStmt->execute([':user_id' => $userId]);
    $habits = [];
    foreach ($hStmt->fetchAll() as $row) {
        $habits[] = [
            'id'         => (string)$row['id'],
            'name'       => $row['name'],
            'category'   => $row['category'],
            'icon'       => $row['icon'],
            'targetType' => $row['targetType'],
            'targetGoal' => (int)$row['targetGoal'],
            'targetUnit' => (string)$row['targetUnit'],
            'xp'         => (int)$row['xp'],
            'createdAt'  => $row['createdAt'],
        ];
    }

    // Fetch all historical & present habit logs for this user
    $lStmt = $pdo->prepare("
        SELECT habit_key, log_date, completed, progress, xp_awarded, updated_at
        FROM habit_logs
        WHERE user_id = :user_id
        ORDER BY log_date DESC
    ");
    $lStmt->execute([':user_id' => $userId]);
    $logs = [];
    foreach ($lStmt->fetchAll() as $row) {
        $date = $row['log_date'];
        if (!isset($logs[$date])) {
            $logs[$date] = [];
        }
        $logs[$date][$row['habit_key']] = [
            'completed' => (bool)$row['completed'],
            'progress'  => (int)$row['progress'],
            'xpAwarded' => (int)$row['xp_awarded'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    return [
        'user' => [
            'id'            => (int)$user['id'],
            'username'      => $user['username'],
            'email'         => $user['email'],
            'displayName'   => $user['display_name'],
            'avatarColor'   => $user['avatar_color'],
            'xp'            => (int)$user['xp'],
            'totalXpEarned' => (int)$user['total_xp_earned'],
            'bestStreak'    => (int)$user['best_streak'],
            'createdAt'     => $user['created_at'],
        ],
        'habits' => $habits,
        'logs'   => (object)$logs,
    ];
}

/**
 * Fetch public community leaderboard (Top 20 players by XP)
 */
function getCommunityLeaderboard(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT username, display_name, avatar_color, xp, total_xp_earned, best_streak, created_at
        FROM users
        ORDER BY xp DESC, best_streak DESC
        LIMIT 20
    ");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $idx => $r) {
        $result[] = [
            'rank'          => $idx + 1,
            'username'      => $r['username'],
            'displayName'   => $r['display_name'],
            'avatarColor'   => $r['avatar_color'] ?: 'indigo',
            'xp'            => (int)$r['xp'],
            'totalXpEarned' => (int)$r['total_xp_earned'],
            'bestStreak'    => (int)$r['best_streak'],
            'joinedAt'      => $r['created_at'],
        ];
    }
    return $result;
}
