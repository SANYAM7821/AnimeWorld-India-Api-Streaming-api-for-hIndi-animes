<?php
define('SEARCH_DOMAINS', [
    'https://piratexplay.cc'
]);
define('BASE_URL', 'https://piratexplay.cc');

// Upstash Redis REST Credentials (Primary, Secondary, Tertiary Accounts)
define('UPSTASH_REDIS_REST_URL', getenv('UPSTASH_REDIS_REST_URL') ?: '');
define('UPSTASH_REDIS_REST_TOKEN', getenv('UPSTASH_REDIS_REST_TOKEN') ?: '');

define('UPSTASH_REDIS_REST_URL_2', getenv('UPSTASH_REDIS_REST_URL_2') ?: '');
define('UPSTASH_REDIS_REST_TOKEN_2', getenv('UPSTASH_REDIS_REST_TOKEN_2') ?: '');

define('UPSTASH_REDIS_REST_URL_3', getenv('UPSTASH_REDIS_REST_URL_3') ?: '');
define('UPSTASH_REDIS_REST_TOKEN_3', getenv('UPSTASH_REDIS_REST_TOKEN_3') ?: '');

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
   PARALLEL MULTI-REDIS CACHING HELPER
   1. Sequential Read with Automatic Failover (Account 1 -> Account 2 -> Account 3)
   2. Parallel Multi-Account Storage (Replicates across ALL Redis accounts)
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
        CURLOPT_TIMEOUT => 3, // Fast 3s timeout
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

/**
 * Sequential Read & Automatic Failover across configured Redis accounts
 */
function getRedisCache($key) {
    $accounts = [
        ['url' => UPSTASH_REDIS_REST_URL, 'token' => UPSTASH_REDIS_REST_TOKEN],
        ['url' => UPSTASH_REDIS_REST_URL_2, 'token' => UPSTASH_REDIS_REST_TOKEN_2],
        ['url' => UPSTASH_REDIS_REST_URL_3, 'token' => UPSTASH_REDIS_REST_TOKEN_3],
    ];

    foreach ($accounts as $acc) {
        if (!empty($acc['url']) && !empty($acc['token'])) {
            try {
                $val = upstashRedisCommand($acc['url'], $acc['token'], ["GET", $key]);
                if ($val !== null && $val !== false && !empty($val)) {
                    return $val;
                }
            } catch (\Throwable $e) {}
        }
    }

    return null;
}

/**
 * Parallel Multi-Redis Storage
 * Replicates cached JSON payload in parallel across ALL configured Upstash Redis accounts
 */
function setRedisCache($key, $value, $ttlSeconds) {
    $accounts = [
        ['url' => UPSTASH_REDIS_REST_URL, 'token' => UPSTASH_REDIS_REST_TOKEN],
        ['url' => UPSTASH_REDIS_REST_URL_2, 'token' => UPSTASH_REDIS_REST_TOKEN_2],
        ['url' => UPSTASH_REDIS_REST_URL_3, 'token' => UPSTASH_REDIS_REST_TOKEN_3],
    ];

    $validAccounts = [];
    foreach ($accounts as $acc) {
        if (!empty($acc['url']) && !empty($acc['token'])) {
            $validAccounts[] = $acc;
        }
    }

    if (empty($validAccounts)) {
        return false;
    }

    $mh = curl_multi_init();
    $curlHandles = [];
    $payload = json_encode(["SET", $key, $value, "EX", (int)$ttlSeconds]);

    foreach ($validAccounts as $index => $acc) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($acc['url'], '/'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $acc['token'],
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_multi_add_handle($mh, $ch);
        $curlHandles[$index] = $ch;
    }

    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    foreach ($curlHandles as $ch) {
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return true;
}
