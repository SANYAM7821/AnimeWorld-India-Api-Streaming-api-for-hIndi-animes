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
    // If $path is already a full http/https URL, fetch it directly
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        $res = fetchHtml($path);
        if (!isset($res['error']) && !empty($res) && strlen($res) > 100 && !str_contains($res, 'This domain is for sale')) {
            $parsedHost = parse_url($path, PHP_URL_HOST);
            return [
                'html' => $res,
                'active_domain' => $parsedHost
            ];
        }
        return ["error" => $res['error'] ?? "Empty or invalid response from " . $path];
    }

    $domains = SEARCH_DOMAINS;
    $lastError = "No domains available";

    $cleanPath = '/' . ltrim($path, '/');
    if (!str_contains($cleanPath, '?') && !str_ends_with($cleanPath, '/')) {
        $cleanPath .= '/';
    }

    foreach ($domains as $domain) {
        $targetUrl = rtrim($domain, '/') . $cleanPath;
        $res = fetchHtml($targetUrl);

        if (!isset($res['error']) && !empty($res) && strlen($res) > 100 && !str_contains($res, 'This domain is for sale')) {
            return [
                'html' => $res,
                'active_domain' => str_replace('https://', '', $domain)
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
   ANILIST ID RESOLVER HELPER (With Ani.zip High-Reliability Fallback & Redis Caching)
   Maps AniList ID -> PirateXPlay Series/Movie Slug & Caches in Redis
   ========================================================================= */

function fetchAniListDetails($anilistId) {
    $detailKey = "anilist_details_" . $anilistId;
    $cachedDetail = getRedisCache($detailKey);
    if ($cachedDetail && !empty($cachedDetail)) {
        $decoded = json_decode($cachedDetail, true);
        if ($decoded && isset($decoded['title'])) {
            return $decoded;
        }
    }

    // 1. AniList GraphQL API
    $query = 'query ($id: Int) { Media (id: $id) { id type format title { romaji english native } synonyms } }';
    $body  = json_encode(['query' => $query, 'variables' => ['id' => (int)$anilistId]]);

    $ch = curl_init('https://graphql.anilist.co');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($body),
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $res) {
        $json = json_decode($res, true);
        if (isset($json['data']['Media']['title'])) {
            $media = $json['data']['Media'];
            setRedisCache($detailKey, json_encode($media), 2592000);
            return $media;
        }
    }

    // 2. High-reliability Fallback: Ani.zip Mappings API
    $ch2 = curl_init("https://api.ani.zip/mappings?anilist_id=" . (int)$anilistId);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
        ],
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $azRes = curl_exec($ch2);
    $azCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($azCode === 200 && $azRes) {
        $azJson = json_decode($azRes, true);
        if (isset($azJson['titles'])) {
            $media = [
                'format' => $azJson['mappings']['type'] ?? 'TV',
                'title'  => [
                    'english' => $azJson['titles']['en'] ?? ($azJson['titles']['x-jat'] ?? ''),
                    'romaji'  => $azJson['titles']['x-jat'] ?? ($azJson['titles']['en'] ?? ''),
                    'native'  => $azJson['titles']['ja'] ?? ''
                ],
                'tmdb_id' => $azJson['mappings']['themoviedb_id'] ?? null,
                'synonyms' => []
            ];
            setRedisCache($detailKey, json_encode($media), 2592000);
            return $media;
        }
    }

    return null;
}

function resolveAniListToSlug($anilistId, $forceRefresh = false) {
    $mappingKey = "mapping_anilist_" . $anilistId;

    // Check Redis mapping cache if not force refreshing
    if (!$forceRefresh) {
        $cachedData = getRedisCache($mappingKey);
        if ($cachedData && !empty($cachedData)) {
            $decoded = json_decode($cachedData, true);
            if ($decoded && isset($decoded['slug']) && !empty($decoded['slug'])) {
                return $decoded;
            }
            if (is_string($cachedData) && strlen($cachedData) > 2) {
                return ['type' => 'series', 'slug' => $cachedData];
            }
        }
    }

    // Fetch titles from AniList (with Ani.zip fallback)
    $aniDetails = fetchAniListDetails($anilistId);
    if (!$aniDetails) {
        return null;
    }

    $format = strtolower($aniDetails['format'] ?? '');
    $isMovieFormat = ($format === 'movie' || $format === 'special');

    $rawTitles = [];
    if (!empty($aniDetails['title']['english'])) {
        $rawTitles[] = $aniDetails['title']['english'];
    }
    if (!empty($aniDetails['title']['romaji'])) {
        $rawTitles[] = $aniDetails['title']['romaji'];
    }
    if (!empty($aniDetails['synonyms'])) {
        foreach ($aniDetails['synonyms'] as $syn) {
            if (is_string($syn) && strlen($syn) > 2) {
                $rawTitles[] = $syn;
            }
        }
    }

    // Also include TMDB ID search if provided by Ani.zip
    if (!empty($aniDetails['tmdb_id'])) {
        $rawTitles[] = (string)$aniDetails['tmdb_id'];
    }

    $titlesToTry = [];
    foreach ($rawTitles as $t) {
        $titlesToTry[] = $t;
        // Strip season indicators e.g. "Season 3", "3rd Season", "Part 2", etc.
        $baseTitle = preg_replace('/\s*(?:season\s*\d+|\d+(?:st|nd|rd|th)\s*season|part\s*\d+)/i', '', $t);
        $baseTitle = trim($baseTitle);
        if (strlen($baseTitle) > 2 && $baseTitle !== $t) {
            $titlesToTry[] = $baseTitle;
        }
    }

    foreach (array_unique($titlesToTry) as $title) {
        $cleanTitle = preg_replace('/[:!\?]+/', '', $title);
        $searchRes  = fetchHtmlWithFallback("/?s=" . urlencode($cleanTitle));

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
                    if ($link) {
                        $cleanPath = parse_url($link, PHP_URL_PATH);
                        $foundSlug = trim($cleanPath, "/");

                        if (str_contains($link, "movie") || $isMovieFormat) {
                            $foundSlug = str_replace(["movies/", "movie/"], "", $foundSlug);
                            $result = ['type' => 'movie', 'slug' => $foundSlug];
                            setRedisCache($mappingKey, json_encode($result), 2592000);
                            return $result;
                        } elseif (str_contains($link, "series")) {
                            $foundSlug = str_replace("series/", "", $foundSlug);
                            $result = ['type' => 'series', 'slug' => $foundSlug];
                            setRedisCache($mappingKey, json_encode($result), 2592000);
                            return $result;
                        }
                    }
                }
            }
        }
    }

    return null;
}
