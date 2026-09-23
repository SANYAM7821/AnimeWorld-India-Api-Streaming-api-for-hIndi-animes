<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

// Accept parameters: episodeId OR id, movieId, ongoing, refresh / force
$episodeId = isset($_GET['episodeId']) ? trim($_GET['episodeId']) : (isset($_GET['id']) ? trim($_GET['id']) : null);
$movieId   = isset($_GET['movieId']) ? trim($_GET['movieId']) : null;

// Ongoing status parameter (ongoing=true -> 12 Hours TTL; default -> 30 Days TTL)
$isOngoingParam = isset($_GET['ongoing']) ? strtolower(trim($_GET['ongoing'])) : (isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '');
$isOngoing = ($isOngoingParam === 'true' || $isOngoingParam === '1' || $isOngoingParam === 'ongoing' || $isOngoingParam === 'releasing');

// Force refresh parameter (refresh=true -> bypass cache, force re-scrape, update Redis)
$refreshParam = isset($_GET['refresh']) ? strtolower(trim($_GET['refresh'])) : (isset($_GET['force']) ? strtolower(trim($_GET['force'])) : '');
$forceRefresh = ($refreshParam === 'true' || $refreshParam === '1' || $refreshParam === 'yes');

if (!$episodeId && !$movieId) {
    echo json_encode(["success" => false, "error" => "Missing episodeId or movieId parameter"]);
    exit;
}

// 1. Calculate Cache Key & Smart Dynamic TTL
$cacheKey = $episodeId ? "stream_ep_" . $episodeId : "stream_movie_" . $movieId;
$ttlSeconds = $isOngoing ? 43200 : 2592000; // 12 Hours (43200s) for Ongoing, 30 Days (2592000s) for Completed

// 2. Check Upstash Redis Cache (if not force-refreshing)
if (!$forceRefresh) {
    $cachedResponse = getRedisCache($cacheKey);
    if ($cachedResponse !== null && !empty($cachedResponse)) {
        // Return cached JSON instantly
        echo $cachedResponse;
        exit;
    }
}

// 3. Live Scraping Execution
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
    foreach ($targetPaths as $path) {
        $res = fetchHtmlWithFallback($path);
        if (!isset($res['error'])) {
            $html = $res['html'];
            $activeDomain = $res['active_domain'];
            break;
        }
    }
}

// If scraping failed but forceRefresh was requested and we had old cache, fallback gracefully
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

/* Construct Response JSON */
if ($type === "movie") {
    $titleNode  = $xpath->query("//h1[contains(@class,'entry-title')] | //h1")->item(0);
    $posterNode = $xpath->query("//figure//img | //img")->item(0);
    $descNode   = $xpath->query("//div[contains(@class,'description')]//p | //p")->item(0);
    $yearNode   = $xpath->query("//span[contains(@class,'year')]")->item(0);
    $ratingNode = $xpath->query("//span[contains(@class,'vote')]")->item(0);

    $movie = [
        "movieId"     => $movieId,
        "title"       => $titleNode ? trim(html_entity_decode($titleNode->textContent)) : null,
        "poster"      => $posterNode ? $posterNode->getAttribute("src") : null,
        "description" => $descNode ? trim(html_entity_decode($descNode->textContent)) : null,
        "year"        => $yearNode ? trim($yearNode->textContent) : null,
        "duration"    => null,
        "rating"      => $ratingNode ? trim(preg_replace('/\s+/', ' ', $ratingNode->textContent)) : null
    ];

    $responseArray = [
        "success" => true,
        "type"    => "movie",
        "cached"  => false,
        "ttl"     => $ttlSeconds,
        "source"  => str_replace('https://', '', $activeDomain) . "/movie",
        "movie"   => $movie,
        "stream"  => [
            "streamLink" => $streamLink,
            "file"       => $downloadLink,
            "servers"    => $servers
        ]
    ];
} else {
    $titleNode  = $xpath->query("//h1[contains(@class,'entry-title')] | //h1")->item(0);
    $posterNode = $xpath->query("//figure//img | //img")->item(0);
    $descNode   = $xpath->query("//div[contains(@class,'description')]//p | //p")->item(0);

    $currentEpisode = [
        "episodeId" => $episodeId,
        "title"     => $titleNode ? trim(html_entity_decode($titleNode->textContent)) : null,
        "airDate"   => null,
        "overview"  => $descNode ? trim(html_entity_decode($descNode->textContent)) : null
    ];

    $episodeLinks = $xpath->query("//a[contains(@href,'/episode/')]");
    $episodes = [];
    $seen = [];

    foreach ($episodeLinks as $a) {
        $href = $a->getAttribute("href");
        if ($href) {
            $cleanPath = parse_url($href, PHP_URL_PATH);
            $epId = trim(str_replace("/episode/", "", $cleanPath), "/");
            if ($epId && !isset($seen[$epId])) {
                $seen[$epId] = true;
                $episodes[] = [
                    "episodeId"     => $epId,
                    "title"         => $epId,
                    "episodeNumber" => $epId,
                    "airDate"       => null,
                    "image"         => $posterNode ? $posterNode->getAttribute("src") : null
                ];
            }
        }
    }

    $responseArray = [
        "success" => true,
        "type"    => "episode",
        "cached"  => false,
        "ttl"     => $ttlSeconds,
        "source"  => str_replace('https://', '', $activeDomain) . "/episode",
        "series"  => [
            "title"         => $titleNode ? trim(html_entity_decode($titleNode->textContent)) : null,
            "poster"        => $posterNode ? $posterNode->getAttribute("src") : null,
            "season"        => "Season 1",
            "totalEpisodes" => (string)count($episodes),
            "rating"        => null,
            "duration"      => null,
            "description"   => $descNode ? trim(html_entity_decode($descNode->textContent)) : null
        ],
        "current"  => $currentEpisode,
        "previous" => null,
        "next"     => null,
        "episodes" => $episodes,
        "stream"   => [
            "streamLink" => $streamLink,
            "file"       => $downloadLink,
            "servers"    => $servers
        ]
    ];
}

$jsonOutput = json_encode($responseArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// 4. Save Fresh Stream Result to Upstash Redis
if ($streamLink) {
    setRedisCache($cacheKey, $jsonOutput, $ttlSeconds);
}

echo $jsonOutput;
