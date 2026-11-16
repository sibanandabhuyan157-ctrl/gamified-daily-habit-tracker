<?php
/**
 * LevelUp Life - High-Performance REST API Endpoint (PHP + PDO Database)
 * Handles Multi-User Registration, Login, Present & Historical Habit Logs, and Leaderboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Parse JSON request body if present
$rawInput = file_get_contents('php://input');
$body = $rawInput ? json_decode($rawInput, true) : [];
if (!is_array($body)) {
    $body = [];
}

$action = $_GET['action'] ?? $body['action'] ?? 'session';

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = getDB();

    switch ($action) {
        // --------------------------------------------------------
        // 1. CHECK SESSION & HYDRATE STATE
        // --------------------------------------------------------
        case 'session': {
            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            $state = $userId > 0 ? getUserFullState($pdo, $userId) : null;
            if (!$state && $userId > 0) {
                unset($_SESSION['user_id']);
            }
            jsonResponse([
                'ok'            => true,
                'authenticated' => $state !== null,
                'data'          => $state,
                'leaderboard'   => getCommunityLeaderboard($pdo),
            ]);
        }

        // --------------------------------------------------------
        // 2. USER REGISTRATION
        // --------------------------------------------------------
        case 'register': {
            $username    = trim((string)($body['username'] ?? ''));
            $email       = strtolower(trim((string)($body['email'] ?? '')));
            $password    = (string)($body['password'] ?? '');
            $displayName = trim((string)($body['displayName'] ?? $username));
            $avatarColor = trim((string)($body['avatarColor'] ?? 'indigo'));

            if (strlen($username) < 3 || strlen($username) > 40 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
                jsonResponse(['ok' => false, 'error' => 'Username must be 3-40 characters (letters, numbers, _, ., -).'], 400);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
            }
            if (strlen($password) < 6) {
                jsonResponse(['ok' => false, 'error' => 'Password must be at least 6 characters long.'], 400);
            }
            if ($displayName === '') {
                $displayName = $username;
            }

            // Check if username or email is already registered
            $chk = $pdo->prepare("SELECT id, username, email FROM users WHERE LOWER(username) = LOWER(:u) OR LOWER(email) = LOWER(:e) LIMIT 1");
            $chk->execute([':u' => $username, ':e' => $email]);
            $existing = $chk->fetch();
            if ($existing) {
                if (strcasecmp($existing['username'], $username) === 0) {
                    jsonResponse(['ok' => false, 'error' => 'That username is already taken. Please choose another.'], 409);
                }
                jsonResponse(['ok' => false, 'error' => 'An account with that email already exists. Please log in.'], 409);
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $pdo->beginTransaction();
            $ins = $pdo->prepare("
                INSERT INTO users (username, email, password_hash, display_name, avatar_color, xp, total_xp_earned, best_streak)
                VALUES (:u, :e, :p, :d, :c, 0, 0, 0)
            ");
            $ins->execute([
                ':u' => $username,
                ':e' => $email,
                ':p' => $hash,
                ':d' => $displayName,
                ':c' => $avatarColor,
            ]);
            $newUserId = (int)$pdo->lastInsertId();

            // Check if guest data was passed to import on registration, otherwise seed defaults
            if (!empty($body['guestState']) && is_array($body['guestState'])) {
                importStateIntoDatabase($pdo, $newUserId, $body['guestState']);
            } else {
                seedDefaultHabitsForUser($pdo, $newUserId);
            }
            $pdo->commit();

            session_regenerate_id(true);
            $_SESSION['user_id'] = $newUserId;

            jsonResponse([
                'ok'            => true,
                'authenticated' => true,
                'message'       => 'Account created! Welcome to LevelUp Life, ' . $displayName . '!',
                'data'          => getUserFullState($pdo, $newUserId),
                'leaderboard'   => getCommunityLeaderboard($pdo),
            ]);
        }

        // --------------------------------------------------------
        // 3. USER LOGIN
        // --------------------------------------------------------
        case 'login': {
            $identifier = trim((string)($body['identifier'] ?? $body['email'] ?? $body['username'] ?? ''));
            $password   = (string)($body['password'] ?? '');

            if ($identifier === '' || $password === '') {
                jsonResponse(['ok' => false, 'error' => 'Please enter both your username/email and password.'], 400);
            }

            $stmt = $pdo->prepare("
                SELECT id, username, display_name, password_hash
                FROM users
                WHERE LOWER(username) = LOWER(:id1) OR LOWER(email) = LOWER(:id2)
                LIMIT 1
            ");
            $stmt->execute([':id1' => $identifier, ':id2' => $identifier]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                jsonResponse(['ok' => false, 'error' => 'Invalid username/email or password.'], 401);
            }

            $userId = (int)$user['id'];
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;

            jsonResponse([
                'ok'            => true,
                'authenticated' => true,
                'message'       => 'Welcome back, ' . $user['display_name'] . '!',
                'data'          => getUserFullState($pdo, $userId),
                'leaderboard'   => getCommunityLeaderboard($pdo),
            ]);
        }

        // --------------------------------------------------------
        // 4. USER LOGOUT
        // --------------------------------------------------------
        case 'logout': {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
            }
            session_destroy();
            jsonResponse([
                'ok'            => true,
                'authenticated' => false,
                'message'       => 'You have been logged out safely.',
                'leaderboard'   => getCommunityLeaderboard($pdo),
            ]);
        }

        // --------------------------------------------------------
        // 5. CREATE OR UPDATE A HABIT
        // --------------------------------------------------------
        case 'save_habit': {
            $userId = requireAuth();
            $habit = $body['habit'] ?? [];
            $habitKey   = trim((string)($habit['id'] ?? ('h_' . round(microtime(true) * 1000))));
            $name       = trim((string)($habit['name'] ?? ''));
            $category   = trim((string)($habit['category'] ?? 'Exercises'));
            $icon       = trim((string)($habit['icon'] ?? 'fa-dumbbell'));
            $targetType = ($habit['targetType'] ?? 'boolean') === 'numeric' ? 'numeric' : 'boolean';
            $targetGoal = max(1, (int)($habit['targetGoal'] ?? 1));
            $targetUnit = trim((string)($habit['targetUnit'] ?? ''));
            $xp         = max(5, min(500, (int)($habit['xp'] ?? 20)));

            if ($name === '') {
                jsonResponse(['ok' => false, 'error' => 'Habit name is required.'], 400);
            }

            // Check if habit already exists for user
            $chk = $pdo->prepare("SELECT id FROM habits WHERE user_id = :u AND habit_key = :k LIMIT 1");
            $chk->execute([':u' => $userId, ':k' => $habitKey]);
            if ($chk->fetch()) {
                $upd = $pdo->prepare("
                    UPDATE habits
                    SET name = :name, category = :cat, icon = :icon,
                        target_type = :tt, target_goal = :tg, target_unit = :tu, xp = :xp, is_active = 1
                    WHERE user_id = :u AND habit_key = :k
                ");
                $upd->execute([
                    ':name' => $name, ':cat' => $category, ':icon' => $icon,
                    ':tt' => $targetType, ':tg' => $targetGoal, ':tu' => $targetUnit,
                    ':xp' => $xp, ':u' => $userId, ':k' => $habitKey,
                ]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO habits (user_id, habit_key, name, category, icon, target_type, target_goal, target_unit, xp, is_active)
                    VALUES (:u, :k, :name, :cat, :icon, :tt, :tg, :tu, :xp, 1)
                ");
                $ins->execute([
                    ':u' => $userId, ':k' => $habitKey, ':name' => $name,
                    ':cat' => $category, ':icon' => $icon, ':tt' => $targetType,
                    ':tg' => $targetGoal, ':tu' => $targetUnit, ':xp' => $xp,
                ]);
            }

            jsonResponse([
                'ok'   => true,
                'data' => getUserFullState($pdo, $userId),
            ]);
        }

        // --------------------------------------------------------
        // 6. DELETE A HABIT
        // --------------------------------------------------------
        case 'delete_habit': {
            $userId   = requireAuth();
            $habitKey = trim((string)($body['habitId'] ?? ''));
            if ($habitKey === '') {
                jsonResponse(['ok' => false, 'error' => 'Missing habitId.'], 400);
            }

            $del = $pdo->prepare("DELETE FROM habits WHERE user_id = :u AND habit_key = :k");
            $del->execute([':u' => $userId, ':k' => $habitKey]);

            jsonResponse([
                'ok'   => true,
                'data' => getUserFullState($pdo, $userId),
            ]);
        }

        // --------------------------------------------------------
        // 7. LOG PRESENT OR HISTORICAL HABIT PROGRESS & XP
        // --------------------------------------------------------
        case 'log_habit': {
            $userId        = requireAuth();
            $habitKey      = trim((string)($body['habitId'] ?? ''));
            $logDate       = trim((string)($body['date'] ?? date('Y-m-d')));
            $completed     = !empty($body['completed']) ? 1 : 0;
            $progress      = max(0, (int)($body['progress'] ?? 0));
            $xpAwarded     = max(0, (int)($body['xpAwarded'] ?? 0));
            $userXp        = max(0, (int)($body['userXp'] ?? 0));
            $totalXpEarned = max(0, (int)($body['totalXpEarned'] ?? $userXp));
            $bestStreak    = max(0, (int)($body['bestStreak'] ?? 0));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate) || $habitKey === '') {
                jsonResponse(['ok' => false, 'error' => 'Invalid habit log parameters.'], 400);
            }

            $pdo->beginTransaction();

            $chk = $pdo->prepare("SELECT id FROM habit_logs WHERE user_id = :u AND habit_key = :k AND log_date = :d LIMIT 1");
            $chk->execute([':u' => $userId, ':k' => $habitKey, ':d' => $logDate]);
            if ($chk->fetch()) {
                $upd = $pdo->prepare("
                    UPDATE habit_logs
                    SET completed = :c, progress = :p, xp_awarded = :xp
                    WHERE user_id = :u AND habit_key = :k AND log_date = :d
                ");
                $upd->execute([
                    ':c' => $completed, ':p' => $progress, ':xp' => $xpAwarded,
                    ':u' => $userId, ':k' => $habitKey, ':d' => $logDate,
                ]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO habit_logs (user_id, habit_key, log_date, completed, progress, xp_awarded)
                    VALUES (:u, :k, :d, :c, :p, :xp)
                ");
                $ins->execute([
                    ':u' => $userId, ':k' => $habitKey, ':d' => $logDate,
                    ':c' => $completed, ':p' => $progress, ':xp' => $xpAwarded,
                ]);
            }

            $uUpd = $pdo->prepare("
                UPDATE users
                SET xp = :xp,
                    total_xp_earned = :txp,
                    best_streak = CASE WHEN :bs > best_streak THEN :bs2 ELSE best_streak END
                WHERE id = :id
            ");
            $uUpd->execute([
                ':xp'  => $userXp,
                ':txp' => $totalXpEarned,
                ':bs'  => $bestStreak,
                ':bs2' => $bestStreak,
                ':id'  => $userId,
            ]);

            $pdo->commit();

            jsonResponse([
                'ok'          => true,
                'leaderboard' => getCommunityLeaderboard($pdo),
            ]);
        }

        // --------------------------------------------------------
        // 8. FULL STATE SYNC (Present + All Past History)
        // --------------------------------------------------------
        case 'sync_state': {
            $userId = requireAuth();
            $statePayload = $body['state'] ?? [];
            if (!is_array($statePayload)) {
                jsonResponse(['ok' => false, 'error' => 'Invalid state payload.'], 400);
            }

            $pdo->beginTransaction();
            importStateIntoDatabase($pdo, $userId, $statePayload);
            $pdo->commit();

            jsonResponse([
                'ok'          => true,
                'data'        => getUserFullState($pdo, $userId),
                'leaderboard' => getCommunityLeaderboard($pdo),
            ]);
        }

        // --------------------------------------------------------
        // 9. PUBLIC LEADERBOARD
        // --------------------------------------------------------
        case 'leaderboard': {
            jsonResponse([
                'ok'          => true,
                'leaderboard' => getCommunityLeaderboard($pdo),
            ]);
        }

        default:
            jsonResponse(['ok' => false, 'error' => 'Unknown API action: ' . $action], 400);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse([
        'ok'    => false,
        'error' => 'Server Error: ' . $e->getMessage(),
    ], 500);
}

/**
 * Ensures the request has an authenticated session user.
 */
function requireAuth(): int
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'Authentication required. Please log in.'], 401);
    }
    return $userId;
}

/**
 * Imports/syncs habits, historical & present logs, and XP stats into the database for a user.
 */
function importStateIntoDatabase(PDO $pdo, int $userId, array $state): void
{
    $habits = $state['habits'] ?? [];
    if (is_array($habits) && count($habits) > 0) {
        $chkH = $pdo->prepare("SELECT id FROM habits WHERE user_id = :u AND habit_key = :k LIMIT 1");
        $updH = $pdo->prepare("
            UPDATE habits
            SET name = :name, category = :cat, icon = :icon, target_type = :tt,
                target_goal = :tg, target_unit = :tu, xp = :xp, is_active = 1
            WHERE user_id = :u AND habit_key = :k
        ");
        $insH = $pdo->prepare("
            INSERT INTO habits (user_id, habit_key, name, category, icon, target_type, target_goal, target_unit, xp, is_active)
            VALUES (:u, :k, :name, :cat, :icon, :tt, :tg, :tu, :xp, 1)
        ");

        foreach ($habits as $h) {
            if (!is_array($h) || empty($h['id']) || empty($h['name'])) {
                continue;
            }
            $params = [
                ':u'    => $userId,
                ':k'    => (string)$h['id'],
                ':name' => (string)$h['name'],
                ':cat'  => (string)($h['category'] ?? 'Exercises'),
                ':icon' => (string)($h['icon'] ?? 'fa-dumbbell'),
                ':tt'   => (($h['targetType'] ?? 'boolean') === 'numeric') ? 'numeric' : 'boolean',
                ':tg'   => max(1, (int)($h['targetGoal'] ?? 1)),
                ':tu'   => (string)($h['targetUnit'] ?? ''),
                ':xp'   => max(5, (int)($h['xp'] ?? 20)),
            ];
            $chkH->execute([':u' => $userId, ':k' => (string)$h['id']]);
            if ($chkH->fetch()) {
                $updH->execute($params);
            } else {
                $insH->execute($params);
            }
        }
    } else {
        // Ensure user has at least default habits if none exist
        $cntStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM habits WHERE user_id = :u");
        $cntStmt->execute([':u' => $userId]);
        $cnt = (int)($cntStmt->fetch()['c'] ?? 0);
        if ($cnt === 0) {
            seedDefaultHabitsForUser($pdo, $userId);
        }
    }

    $logs = $state['logs'] ?? [];
    if (is_array($logs)) {
        $chkL = $pdo->prepare("SELECT id FROM habit_logs WHERE user_id = :u AND habit_key = :k AND log_date = :d LIMIT 1");
        $updL = $pdo->prepare("
            UPDATE habit_logs
            SET completed = :c, progress = :p, xp_awarded = :xp
            WHERE user_id = :u AND habit_key = :k AND log_date = :d
        ");
        $insL = $pdo->prepare("
            INSERT INTO habit_logs (user_id, habit_key, log_date, completed, progress, xp_awarded)
            VALUES (:u, :k, :d, :c, :p, :xp)
        ");

        foreach ($logs as $dateStr => $dayEntries) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateStr) || !is_array($dayEntries)) {
                continue;
            }
            foreach ($dayEntries as $habitKey => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $completed = !empty($entry['completed']) ? 1 : 0;
                $progress  = max(0, (int)($entry['progress'] ?? 0));
                $xpAwarded = max(0, (int)($entry['xpAwarded'] ?? 0));

                $chkL->execute([':u' => $userId, ':k' => (string)$habitKey, ':d' => (string)$dateStr]);
                if ($chkL->fetch()) {
                    $updL->execute([
                        ':c' => $completed, ':p' => $progress, ':xp' => $xpAwarded,
                        ':u' => $userId, ':k' => (string)$habitKey, ':d' => (string)$dateStr,
                    ]);
                } else {
                    $insL->execute([
                        ':u' => $userId, ':k' => (string)$habitKey, ':d' => (string)$dateStr,
                        ':c' => $completed, ':p' => $progress, ':xp' => $xpAwarded,
                    ]);
                }
            }
        }
    }

    if (!empty($state['user']) && is_array($state['user'])) {
        $xp     = max(0, (int)($state['user']['xp'] ?? 0));
        $txp    = max($xp, (int)($state['user']['totalXpEarned'] ?? $xp));
        $streak = max(0, (int)($state['user']['bestStreak'] ?? 0));
        $uStmt  = $pdo->prepare("
            UPDATE users
            SET xp = CASE WHEN :xp > xp THEN :xp2 ELSE xp END,
                total_xp_earned = CASE WHEN :txp > total_xp_earned THEN :txp2 ELSE total_xp_earned END,
                best_streak = CASE WHEN :bs > best_streak THEN :bs2 ELSE best_streak END
            WHERE id = :id
        ");
        $uStmt->execute([
            ':xp' => $xp, ':xp2' => $xp,
            ':txp' => $txp, ':txp2' => $txp,
            ':bs' => $streak, ':bs2' => $streak,
            ':id' => $userId,
        ]);
    }
}
