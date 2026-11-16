<?php
/**
 * LevelUp Life - Main PHP Application Entry Point
 * Pre-hydrates User Authentication Session, Present & Historical Habit Logs,
 * and Community Leaderboard on the server side for instant page load performance.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Security & Performance Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$initialPayload = [
    'ok'            => true,
    'authenticated' => false,
    'data'          => null,
    'leaderboard'   => [],
];

try {
    $pdo = getDB();
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId > 0) {
        $userState = getUserFullState($pdo, $userId);
        if ($userState !== null) {
            $initialPayload['authenticated'] = true;
            $initialPayload['data'] = $userState;
        } else {
            unset($_SESSION['user_id']);
        }
    }
    $initialPayload['leaderboard'] = getCommunityLeaderboard($pdo);
} catch (Throwable $e) {
    $initialPayload['error'] = $e->getMessage();
}

$htmlTemplate = file_get_contents(__DIR__ . '/gamified_daily_habit_tracker.html');
$serverStateScript = '<script>window.__INITIAL_SERVER_STATE__ = '
    . json_encode($initialPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . ';</script>';

// Inject pre-hydrated server state right before </head> for zero-latency initialization
echo str_replace('</head>', $serverStateScript . "\n</head>", $htmlTemplate);
