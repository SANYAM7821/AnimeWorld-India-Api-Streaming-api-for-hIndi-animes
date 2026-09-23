<?php
define('SEARCH_DOMAINS', [
    'https://piratexplay.cc'
]);
define('BASE_URL', 'https://piratexplay.cc');

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
