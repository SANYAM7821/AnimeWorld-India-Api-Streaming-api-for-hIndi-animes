<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

// Accept parameters
$anilistId = isset($_GET['anilistId']) ? trim($_GET['anilistId']) : (isset($_GET['anilist_id']) ? trim($_GET['anilist_id']) : null);
$epNum     = isset($_GET['ep']) ? trim($_GET['ep']) : (isset($_GET['episode']) ? trim($_GET['episode']) : '1');

$episodeId = isset($_GET['episodeId']) ? trim($_GET['episodeId']) : (isset($_GET['id']) ? trim($_GET['id']) : null);
$movieId   = isset($_GET['movieId']) ? trim($_GET['movieId']) : null;

// Ongoing status parameter (ongoing=true -> 12 Hours TTL; default -> 30 Days TTL)
$isOngoingParam = isset($_GET['ongoing']) ? strtolower(trim($_GET['ongoing'])) : (isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '');
$isOngoing = ($isOngoingParam === 'true' || $isOngoingParam === '1' || $isOngoingParam === 'ongoing' || $isOngoingParam === 'releasing');

// Force refresh parameter (refresh=true -> bypass cache, force re-scrape, update Redis)
$refreshParam = isset($_GET['refresh']) ? strtolower(trim($_GET['refresh'])) : (isset($_GET['force']) ? strtolower(trim($_GET['force'])) : '');
$forceRefresh = ($refreshParam === 'true' || $refreshParam === '1' || $refreshParam === 'yes');

// 1. Resolve AniList ID if provided
if ($anilistId && !$episodeId && !$movieId) {
    $resolvedSlug = resolveAniListToSlug($anilistId);
    if ($resolvedSlug) {
        // Build episode slug from resolved series slug & episode number
        $cleanEp = preg_replace('/[^0-9]/', '', $epNum) ?: '1';
        $episodeId = $resolvedSlug . "-1x" . $cleanEp;
    } else {
        echo json_encode(["success" => false, "error" => "Could not resolve AniList ID: " . $anilistId]);
        exit;
    }
}

if (!$episodeId && !$movieId) {
    echo json_encode(["success" => false, "error" => "Missing anilistId, episodeId, id, or movieId parameter"]);
    exit;
}

// 2. Calculate Cache Key & Smart Dynamic TTL
$cacheKey = $episodeId ? "stream_ep_" . $episodeId : "stream_movie_" . $movieId;
$ttlSeconds = $isOngoing ? 43200 : 2592000; // 12 Hours (43200s) for Ongoing, 30 Days (2592000s) for Completed

// 3. Check Upstash Redis Cache (if not force-refreshing)
if (!$forceRefresh) {
    $cachedResponse = getRedisCache($cacheKey);
    if ($cachedResponse !== null && !empty($cachedResponse)) {
        echo $cachedResponse;
        exit;
    }
}

// 4. Live Scraping Execution
$html = null;
$activeDomain = null;
$type = $episodeId ? "episode" : "movie";

if ($type === "movie") {
    $targetPaths = ["/movies/" . $movieId, "/movie/" . $movieId];
    foreach ($targetPaths as $path) {
        $res = fetchHtmlWithFallback($path);
        if (!isset($res['error'])) {
            $html = $res['html'];
            $activeDomain = $res['active_domain'];
            break;
        }
    }
} else {
    $targetPaths = ["/episode/" . $episodeId, "/watch/" . $episodeId];

    // Attempt variations if first lookup returns nothing
    if (preg_match('/^(.*?)-1x(\d+)$/', $episodeId, $matches)) {
        $seriesBase = $matches[1];
        $epNumber   = $matches[2];
        $targetPaths[] = "/series/" . $seriesBase . "/";
    }

    foreach ($targetPaths as $path) {
        $res = fetchHtmlWithFallback($path);
        if (!isset($res['error'])) {
            $html = $res['html'];
            $activeDomain = $res['active_domain'];

            // If we fetched a series page, find the exact episode link
            if (str_contains($path, "/series/")) {
                libxml_use_internal_errors(true);
                $domSeries = new DOMDocument();
                $domSeries->loadHTML($html);
                libxml_clear_errors();
                $xpathSeries = new DOMXPath($domSeries);

                $epLinks = $xpathSeries->query("//a[contains(@href,'/episode/')]");
                $foundEpUrl = null;
                $targetPattern = "-1x" . ($cleanEp ?? '1');

                foreach ($epLinks as $aEl) {
                    $href = $aEl->getAttribute("href");
                    if (str_contains($href, $targetPattern) || str_ends_with(rtrim($href, "/"), "-" . ($cleanEp ?? '1'))) {
                        $foundEpUrl = parse_url($href, PHP_URL_PATH);
                        break;
                    }
                }

                if ($foundEpUrl) {
                    $resEp = fetchHtmlWithFallback($foundEpUrl);
                    if (!isset($resEp['error'])) {
                        $html = $resEp['html'];
                        $activeDomain = $resEp['active_domain'];
                    }
                }
            }
            break;
        }
    }
}

// Fallback to stale cache if scraping fails on forceRefresh
if (!$html) {
    if ($forceRefresh) {
        $staleCache = getRedisCache($cacheKey);
        if ($staleCache !== null && !empty($staleCache)) {
            echo $staleCache;
            exit;
        }
    }
    echo json_encode(["success" => false, "error" => "Failed to find stream content. Target server might be down or ID is invalid."]);
    exit;
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

/* Extract stream link and servers */
$iframeNodes = $xpath->query("//iframe");
$streamLink  = null;
$servers     = [];

foreach ($iframeNodes as $iframe) {
    $src     = $iframe->getAttribute("src");
    $dataSrc = $iframe->getAttribute("data-src");
    $url     = $src ? $src : $dataSrc;

    if ($url && !str_contains($url, "about:blank")) {
        if (!$streamLink) {
            $streamLink = $url;
        }
        $servers[] = [
            "name" => "Server " . (count($servers) + 1),
            "url"  => $url
        ];
    }
}

// Fallback regex if XPath iframe is missing
if (!$streamLink) {
    if (preg_match_all('/<iframe[^>]+(?:src|data-src)=["\']([^"\']+)["\']/i', $html, $matches)) {
        foreach ($matches[1] as $url) {
            if ($url && !str_contains($url, "about:blank")) {
                if (!$streamLink) {
                    $streamLink = $url;
                }
                $servers[] = [
                    "name" => "Server " . (count($servers) + 1),
                    "url"  => $url
                ];
            }
        }
    }
}

$downloadNode = $xpath->query("//a[contains(@href,'download') or contains(@class,'download')]")->item(0);
$downloadLink = $downloadNode ? $downloadNode->getAttribute("href") : $streamLink;

$titleNode = $xpath->query("//h1[contains(@class,'entry-title')] | //h1")->item(0);
$titleText = $titleNode ? trim(html_entity_decode($titleNode->textContent)) : "Stream";

/* Construct Lean & Fast JSON Response (Stream object at top, no heavy episode lists) */
$responseArray = [
    "success" => true,
    "cached"  => false,
    "ttl"     => $ttlSeconds,
    "stream"  => [
        "streamLink" => $streamLink,
        "file"       => $downloadLink,
        "servers"    => $servers
    ],
    "info" => [
        "title"     => $titleText,
        "id"        => $episodeId ? $episodeId : $movieId,
        "anilistId" => $anilistId ? (int)$anilistId : null,
        "type"      => $type,
        "source"    => str_replace('https://', '', $activeDomain ?? 'piratexplay.cc')
    ]
];

$jsonOutput = json_encode($responseArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// 5. Save Fresh Stream Result to Upstash Redis
if ($streamLink) {
    setRedisCache($cacheKey, $jsonOutput, $ttlSeconds);
}

echo $jsonOutput;
