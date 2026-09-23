<?php
define('SEARCH_DOMAINS', [
    'https://piratexplay.cc'
]);
define('BASE_URL', 'https://piratexplay.cc');

// Upstash Redis REST Credentials (Primary & Secondary Failover)
define('UPSTASH_REDIS_REST_URL', getenv('UPSTASH_REDIS_REST_URL') ?: '');
define('UPSTASH_REDIS_REST_TOKEN', getenv('UPSTASH_REDIS_REST_TOKEN') ?: '');

define('UPSTASH_REDIS_REST_URL_2', getenv('UPSTASH_REDIS_REST_URL_2') ?: '');
define('UPSTASH_REDIS_REST_TOKEN_2', getenv('UPSTASH_REDIS_REST_TOKEN_2') ?: '');

function fetchHtml($url) {
    $ch = curl_init();

    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: max-age=0',
        'Sec-Ch-Ua: "Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1',
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => "",
    ]);

    $html = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ["error" => $error];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        return ["error" => "HTTP error code: $httpCode"];
    }

    return $html;
}

function fetchHtmlWithFallback($path) {
    $domains = SEARCH_DOMAINS;
    $lastError = "No domains available";

    $cleanPath = (str_starts_with($path, '/') || str_starts_with($path, 'http')) ? $path : '/' . $path;

    foreach ($domains as $domain) {
        $targetUrl = str_starts_with($cleanPath, 'http') ? $cleanPath : $domain . $cleanPath;
        $res = fetchHtml($targetUrl);

        if (!isset($res['error']) && !empty($res) && !str_contains($res, 'This domain is for sale')) {
            return [
                'html' => $res,
                'active_domain' => $domain
            ];
        }
        $lastError = isset($res['error']) ? $res['error'] : "Domain returned empty response";
    }

    return ["error" => $lastError];
}

/* =========================================================================
   UPSTASH REDIS CACHING HELPER (REST API)
   Handles Primary & Secondary Redis Accounts with Automatic Fallback
   ========================================================================= */

function upstashRedisCommand($url, $token, $commandArray) {
    if (empty($url) || empty($token)) {
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => rtrim($url, '/'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($commandArray),
        CURLOPT_TIMEOUT => 3, // 3s fast timeout
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $res) {
        $data = json_decode($res, true);
        if (isset($data['result']) && !isset($data['error'])) {
            return $data['result'];
        }
    }
    return null;
}

function getRedisCache($key) {
    // 1. Try Primary Redis
    try {
        $val = upstashRedisCommand(UPSTASH_REDIS_REST_URL, UPSTASH_REDIS_REST_TOKEN, ["GET", $key]);
        if ($val !== null && $val !== false) {
            return $val;
        }
    } catch (\Throwable $e) {}

    // 2. Try Secondary / Backup Redis
    try {
        $val = upstashRedisCommand(UPSTASH_REDIS_REST_URL_2, UPSTASH_REDIS_REST_TOKEN_2, ["GET", $key]);
        if ($val !== null && $val !== false) {
            return $val;
        }
    } catch (\Throwable $e) {}

    return null;
}

function setRedisCache($key, $value, $ttlSeconds) {
    $success = false;

    // 1. Try Primary Redis
    try {
        $res = upstashRedisCommand(UPSTASH_REDIS_REST_URL, UPSTASH_REDIS_REST_TOKEN, ["SET", $key, $value, "EX", (int)$ttlSeconds]);
        if ($res === "OK") {
            $success = true;
        }
    } catch (\Throwable $e) {}

    // 2. Try Secondary / Backup Redis
    try {
        $res2 = upstashRedisCommand(UPSTASH_REDIS_REST_URL_2, UPSTASH_REDIS_REST_TOKEN_2, ["SET", $key, $value, "EX", (int)$ttlSeconds]);
        if ($res2 === "OK") {
            $success = true;
        }
    } catch (\Throwable $e) {}

    return $success;
}
