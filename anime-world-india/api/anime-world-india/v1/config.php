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

    $cleanPath = '/' . ltrim($path, '/');
    if (!str_contains($cleanPath, '?') && !str_ends_with($cleanPath, '/')) {
        $cleanPath .= '/';
    }

    foreach ($domains as $domain) {
        $targetUrl = str_starts_with($cleanPath, 'http') ? $cleanPath : $domain . $cleanPath;
        $res = fetchHtml($targetUrl);

        if (!isset($res['error']) && !empty($res) && strlen($res) > 100 && !str_contains($res, 'This domain is for sale')) {
            return [
                'html' => $res,
                'active_domain' => $domain
            ];
        }
        $lastError = isset($res['error']) ? $res['error'] : "Domain returned empty or invalid response";
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

/* =========================================================================
   ANILIST ID RESOLVER HELPER
   Maps AniList ID -> PirateXPlay Series Slug & Caches in Redis
   ========================================================================= */

function fetchAniListDetails($anilistId) {
    $query = 'query ($id: Int) { Media (id: $id, type: ANIME) { title { romaji english native } synonyms } }';
    $body  = json_encode(['query' => $query, 'variables' => ['id' => (int)$anilistId]]);

    $ch = curl_init('https://graphql.anilist.co');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $res) {
        $json = json_decode($res, true);
        if (isset($json['data']['Media'])) {
            return $json['data']['Media'];
        }
    }
    return null;
}

function resolveAniListToSlug($anilistId) {
    $mappingKey = "mapping_anilist_" . $anilistId;

    // Check Redis mapping cache
    $cachedSlug = getRedisCache($mappingKey);
    if ($cachedSlug && !empty($cachedSlug)) {
        return $cachedSlug;
    }

    // Fetch titles from AniList
    $aniDetails = fetchAniListDetails($anilistId);
    if (!$aniDetails) {
        return null;
    }

    $titlesToTry = [];
    if (!empty($aniDetails['title']['english'])) {
        $titlesToTry[] = $aniDetails['title']['english'];
    }
    if (!empty($aniDetails['title']['romaji'])) {
        $titlesToTry[] = $aniDetails['title']['romaji'];
    }
    if (!empty($aniDetails['synonyms'])) {
        foreach ($aniDetails['synonyms'] as $syn) {
            if (is_string($syn) && strlen($syn) > 2) {
                $titlesToTry[] = $syn;
            }
        }
    }

    foreach ($titlesToTry as $title) {
        $searchRes = fetchHtmlWithFallback("/?s=" . urlencode($title));
        if (isset($searchRes['html'])) {
            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML($searchRes['html']);
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);

            $articles = $xpath->query("//article[contains(@class,'post')]");
            foreach ($articles as $art) {
                $linkNode = $xpath->query(".//a[contains(@class,'lnk-blk')]", $art)->item(0);
                if ($linkNode) {
                    $link = $linkNode->getAttribute("href");
                    if ($link && str_contains($link, "series")) {
                        $cleanPath = parse_url($link, PHP_URL_PATH);
                        $foundSlug = trim(str_replace("/series/", "", $cleanPath), "/");
                        if ($foundSlug) {
                            // Save mapping in Redis permanently (30 Days TTL)
                            setRedisCache($mappingKey, $foundSlug, 2592000);
                            return $foundSlug;
                        }
                    }
                }
            }
        }
    }

    return null;
}
