<?php
require_once __DIR__ . '/../include/bsky.php';

// Set session cookie parameters
session_set_cookie_params([
    'lifetime' => 3456000, // 40 days in seconds
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['pWord'] ?? '';

        if (empty($username) || empty($password)) {
            echo json_encode(['error' => 'Please fill in both fields.']);
            exit;
        }

        $loginResult = bsky_create_session($username, $password);

        if (!$loginResult['success']) {
            $errorMessage = 'Invalid BlueSky credentials.';
            $errorDetail = $loginResult['response']['message'] ?? $loginResult['response']['error'] ?? null;
            if ($errorDetail) {
                $errorMessage .= ' ' . htmlspecialchars($errorDetail, ENT_QUOTES, 'UTF-8');
            }
            echo json_encode(['error' => $errorMessage]);
            exit;
        }

        $sessionData = $loginResult['data'];

        session_regenerate_id(true);

        $_SESSION['bsky_access_jwt'] = $sessionData['accessJwt'] ?? '';
        $_SESSION['bsky_refresh_jwt'] = $sessionData['refreshJwt'] ?? '';
        $_SESSION['bsky_handle'] = $sessionData['handle'] ?? $username;
        $_SESSION['bsky_did'] = $sessionData['did'] ?? null;
        $_SESSION['username'] = $sessionData['handle'] ?? $username;
        $_SESSION['name'] = $sessionData['handle'] ?? $username;
        $_SESSION['profile_pic'] = '/src/images/users/guest/user.svg';
        $_SESSION['is_verified'] = false;

        setcookie(session_name(), session_id(), [
            'expires' => time() + 604800,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        header('Location: /');
        exit();
    } catch (Exception $e) {
        echo json_encode(['error' => 'Login error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
        exit();
    }
} else {
    header('Location: /signin/');
    exit();
}
?>
