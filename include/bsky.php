<?php

function bsky_api_request(string $endpoint, string $method = 'GET', $data = null, bool $useAuth = true, bool $retry = true): array {
    $url = 'https://bsky.social/xrpc/' . $endpoint;
    $headers = [
        'Accept: application/json'
    ];

    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    if ($useAuth) {
        if (!isset($_SESSION['bsky_access_jwt']) || empty($_SESSION['bsky_access_jwt'])) {
            return ['status' => 401, 'body' => json_encode(['error' => 'AuthMissing'])];
        }

        $headers[] = 'Authorization: Bearer ' . $_SESSION['bsky_access_jwt'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 401 && $retry && $useAuth && !empty($_SESSION['bsky_refresh_jwt'])) {
        if (bsky_refresh_session()) {
            return bsky_api_request($endpoint, $method, $data, $useAuth, false);
        }
    }

    return ['status' => $status, 'body' => $body];
}

function bsky_create_session(string $identifier, string $password): array {
    $response = bsky_api_request('com.atproto.server.createSession', 'POST', [
        'identifier' => $identifier,
        'password' => $password
    ], false);

    if ($response['status'] !== 200) {
        return ['success' => false, 'response' => json_decode($response['body'], true)];
    }

    return ['success' => true, 'data' => json_decode($response['body'], true)];
}

function bsky_refresh_session(): bool {
    if (!isset($_SESSION['bsky_refresh_jwt']) || empty($_SESSION['bsky_refresh_jwt'])) {
        return false;
    }

    $response = bsky_api_request('com.atproto.server.refreshSession', 'POST', [
        'refresh' => $_SESSION['bsky_refresh_jwt']
    ], false);

    if ($response['status'] !== 200) {
        unset($_SESSION['bsky_access_jwt'], $_SESSION['bsky_refresh_jwt'], $_SESSION['bsky_handle'], $_SESSION['bsky_did']);
        return false;
    }

    $data = json_decode($response['body'], true);
    if (isset($data['accessJwt'], $data['refreshJwt'])) {
        $_SESSION['bsky_access_jwt'] = $data['accessJwt'];
        $_SESSION['bsky_refresh_jwt'] = $data['refreshJwt'];
        return true;
    }

    return false;
}

function bsky_make_links_clickable(string $text): string {
    $urlPattern = '/\b((https?:\/\/)?([a-z0-9-]+\.)+[a-z]{2,6}(\/[^\s]*)?(\?[^\s]*)?)/i';

    $text = preg_replace_callback($urlPattern, function ($matches) {
        $url = $matches[1];
        $url = html_entity_decode($url);

        if (strpos($url, 'https://') !== 0 && strpos($url, 'http://') !== 0) {
            $url = 'http://' . $url;
        }

        $parsedUrl = parse_url($url);
        $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        $query = isset($parsedUrl['query']) ? $parsedUrl['query'] : '';

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        $fileExtension = pathinfo($path, PATHINFO_EXTENSION);

        foreach ($imageExtensions as $extension) {
            if (stripos($query, 'format=' . $extension) !== false) {
                $fileExtension = $extension;
                break;
            }
        }

        if (in_array(strtolower($fileExtension), $imageExtensions, true)) {
            return '<div class="chirpImageContainer"><img class="imageInChirp" src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="Photo"></div>';
        }

        return '<a class="linkInChirp" href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($url, ENT_QUOTES) . '</a>';
    }, $text);

    $mentionPattern = '/(?<!\S)@([a-zA-Z0-9_]+)(?!\S)/';
    $text = preg_replace_callback($mentionPattern, function ($matches) {
        $username = $matches[1];
        $profileUrl = '/user/?id=' . htmlspecialchars($username, ENT_QUOTES);
        return '<a class="linkInChirp" href="' . $profileUrl . '">@' . htmlspecialchars($username, ENT_QUOTES) . '</a>';
    }, $text);

    return $text;
}

function bsky_format_feed_item(array $item): array {
    $post = $item['post'] ?? [];
    $author = $post['author'] ?? [];
    $record = $post['record'] ?? [];

    $text = $record['text'] ?? '';
    $createdAt = $post['createdAt'] ?? $record['createdAt'] ?? null;
    $timestamp = $createdAt ? (int) floor(strtotime($createdAt) / 1000) : time();

    return [
        'id' => hash('sha256', ($post['uri'] ?? '') . ($post['cid'] ?? '')),
        'profilePic' => $author['avatar'] ?? '/src/images/users/guest/user.svg',
        'name' => $author['displayName'] ?: ($author['handle'] ?? 'BlueSky user'),
        'username' => $author['handle'] ?? 'unknown',
        'isVerified' => false,
        'timestamp' => $timestamp,
        'chirp' => nl2br(bsky_make_links_clickable(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'))),
        'reply_count' => 0,
        'rechirp_count' => 0,
        'like_count' => 0,
        'liked_by_current_user' => false,
        'rechirped_by_current_user' => false,
    ];
}

function bsky_get_timeline(int $limit = 12): array {
    $response = bsky_api_request('app.bsky.feed.getTimeline?limit=' . $limit);

    if ($response['status'] !== 200) {
        return ['success' => false, 'status' => $response['status'], 'error' => json_decode($response['body'], true)];
    }

    $data = json_decode($response['body'], true);
    $items = $data['feed'] ?? $data['timeline'] ?? [];
    $chirps = [];

    foreach ($items as $item) {
        $chirps[] = bsky_format_feed_item($item);
    }

    return ['success' => true, 'chirps' => $chirps];
}

function bsky_get_author_feed(string $actor, int $limit = 12): array {
    $actor = ltrim($actor, '@');
    $actor = rawurlencode($actor);
    $response = bsky_api_request('app.bsky.feed.getAuthorFeed?actor=' . $actor . '&limit=' . $limit);

    if ($response['status'] !== 200) {
        return ['success' => false, 'status' => $response['status'], 'error' => json_decode($response['body'], true)];
    }

    $data = json_decode($response['body'], true);
    $items = $data['feed'] ?? [];
    $chirps = [];

    foreach ($items as $item) {
        $chirps[] = bsky_format_feed_item($item);
    }

    return ['success' => true, 'chirps' => $chirps];
}
