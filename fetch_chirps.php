<?php
require_once __DIR__ . '/include/bsky.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['bsky_access_jwt']) || empty($_SESSION['bsky_access_jwt'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required. Please sign in with BlueSky.']);
    exit;
}

try {
    $result = bsky_get_timeline(12);

    if (!$result['success']) {
        $status = $result['status'] ?? 500;
        http_response_code($status);
        echo json_encode(['error' => 'Unable to fetch BlueSky timeline.', 'details' => $result['error'] ?? null]);
        exit;
    }

    echo json_encode($result['chirps']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred while fetching the BlueSky feed.']);
}

