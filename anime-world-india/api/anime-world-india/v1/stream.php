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

// Cache TTL and Description Labels
$ttlSeconds  = $isOngoing ? 43200 : 2592000; // 12 Hours (43200s) for Ongoing, 30 Days (2592000s) for Completed
$ttlLabel    = $isOngoing ? "12 Hours (43200s - Ongoing Series)" : "30 Days (2592000s - Completed Series)";
$cacheMode   = $isOngoing ? "12 Hours Cache (Ongoing Anime)" : "30 Days Cache (Completed Anime)";

$cleanEpNumber = preg_replace('/[^0-9]/', '', $epNum) ?: '1';

// Helper function to extract servers from HTML (supports PirateXPlay iframes & Animesalt JS triggers)
function parseStreamEmbedsFromHtml($html, $cleanEp = '1') {
    $servers = [];
    $streamLink = null;

    // 1. Try PirateXPlay iframe extraction
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $iframeNodes = $xpath->query("//iframe");
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

    // Regex fallback if XPath misses iframes
    if (empty($servers)) {
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

    // 2. Animesalt triggerEpisode JS fallback
    if (empty($servers)) {
        $pattern = '/triggerEpisode\(\s*(\[\s*\{.*?\}\s*\])\s*,\s*["\'](?:Episode\s*' . $cleanEp . '|ep-' . $cleanEp . ')["\']/is';
        if (preg_match($pattern, $html, $m)) {
            $rawJson = html_entity_decode($m[1]);
            $parsed  = json_decode($rawJson, true);
            if (is_array($parsed)) {
                foreach ($parsed as $srv) {
                    $sUrl  = $srv['url'] ?? null;
                    $sLang = $srv['lang'] ?? '';
                    $sName = $srv['name'] ?? 'HD';
                    if ($sUrl) {
                        if (!$streamLink) {
                            $streamLink = $sUrl;
                        }
                        $servers[] = [
                            "name" => ($sLang ? $sLang . " - " : "") . $sName,
                            "url"  => $sUrl
                        ];
                    }
                }
            }
        }

        // Generic triggerEpisode regex if specific episode pattern didn't match
        if (empty($servers)) {
            if (preg_match_all('/triggerEpisode\(\s*(\[\s*\{.*?\}\s*\])\s*,\s*["\']([^"\']+)["\']/s', $html, $allMatches, PREG_SET_ORDER)) {
                foreach ($allMatches as $match) {
                    $epLabel = $match[2];
                    if (preg_match('/' . $cleanEp . '$/i', $epLabel) || str_contains(strtolower($epLabel), "episode " . $cleanEp) || str_contains(strtolower($epLabel), "ep-" . $cleanEp)) {
                        $rawJson = html_entity_decode($match[1]);
                        $parsed  = json_decode($rawJson, true);
                        if (is_array($parsed)) {
                            foreach ($parsed as $srv) {
                                $sUrl  = $srv['url'] ?? null;
                                $sLang = $srv['lang'] ?? '';
                                $sName = $srv['name'] ?? 'HD';
                                if ($sUrl) {
                                    if (!$streamLink) {
                                        $streamLink = $sUrl;
                                    }
                                    $servers[] = [
                                        "name" => ($sLang ? $sLang . " - " : "") . $sName,
                                        "url"  => $sUrl
                                    ];
                                }
                            }
                        }
                        break;
                    }
                }
            }
        }
    }

    return [
        'streamLink' => $streamLink,
        'servers'    => $servers
    ];
}

function resolveEpisodePageUrl($seriesSlug, $epNumber = '1') {
    $cleanEp = preg_replace('/[^0-9]/', '', $epNumber) ?: '1';

    $seriesPath = str_starts_with($seriesSlug, "/") ? $seriesSlug : "/series/" . $seriesSlug . "/";
    $res = fetchHtmlWithFallback($seriesPath);
    if (!isset($res['html'])) {
        return null;
    }

    $html = $res['html'];
    $activeDomain = $res['active_domain'];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Search for episode link directly on current series page
    $epLinks = $xpath->query("//a[contains(@href,'/episode/')]");
    foreach ($epLinks as $a) {
        $href = $a->getAttribute("href");
        if (str_contains($href, "-1x" . $cleanEp . "/") || str_ends_with(rtrim($href, "/"), "-" . $cleanEp)) {
            return ['url' => parse_url($href, PHP_URL_PATH), 'domain' => $activeDomain];
        }
    }

    // Check if Season 1 button exists if episode 1 was requested
    $seasonBtns = $xpath->query("//a[contains(@class,'season-btn')] | //a[contains(@href,'/series/')]");
    $targetSeasonUrl = null;

    foreach ($seasonBtns as $sBtn) {
        $seasonAttr = $sBtn->getAttribute("data-season");
        $href = $sBtn->getAttribute("href");

        if ($seasonAttr === "1" || str_contains($href, "season-1")) {
            $targetSeasonUrl = parse_url($href, PHP_URL_PATH);
            break;
        }
    }

    if ($targetSeasonUrl) {
        $sRes = fetchHtmlWithFallback($targetSeasonUrl);
        if (isset($sRes['html'])) {
            $sDom = new DOMDocument();
            libxml_use_internal_errors(true);
            $sDom->loadHTML($sRes['html']);
            libxml_clear_errors();
            $sXpath = new DOMXPath($sDom);

            $sEpLinks = $sXpath->query("//a[contains(@href,'/episode/')]");
            foreach ($sEpLinks as $a) {
                $href = $a->getAttribute("href");
                if (str_contains($href, "-1x" . $cleanEp . "/") || str_ends_with(rtrim($href, "/"), "-" . $cleanEp) || str_contains($href, "episode-" . $cleanEp)) {
                    return ['url' => parse_url($href, PHP_URL_PATH), 'domain' => $sRes['active_domain']];
                }
            }
        }
    }

    return null;
}

// 1. Resolve AniList ID if provided
if ($anilistId && !$episodeId && !$movieId) {
    $resolvedSlug = resolveAniListToSlug($anilistId);
    if ($resolvedSlug) {
        $resolvedEp = resolveEpisodePageUrl($resolvedSlug, $epNum);
        if ($resolvedEp) {
            $episodeId = trim($resolvedEp['url'], "/");
            if (str_starts_with($episodeId, "episode/")) {
                $episodeId = str_replace("episode/", "", $episodeId);
            }
        } else {
            $episodeId = $resolvedSlug . "-1x" . $cleanEpNumber;
        }
    } else {
        echo json_encode(["success" => false, "error" => "Could not resolve AniList ID: " . $anilistId]);
        exit;
    }
}

if (!$episodeId && !$movieId) {
    echo json_encode(["success" => false, "error" => "Missing anilistId, episodeId, id, or movieId parameter"]);
    exit;
}

// 2. Calculate Cache Key
$cacheKey = $episodeId ? "stream_ep_" . $episodeId : "stream_movie_" . $movieId;

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
    $targetPaths = ["/movies/" . trim($movieId, "/") . "/", "/movie/" . trim($movieId, "/") . "/"];
    foreach ($targetPaths as $path) {
        $res = fetchHtmlWithFallback($path);
        if (!isset($res['error'])) {
            $html = $res['html'];
            $activeDomain = $res['active_domain'];
            break;
        }
    }
} else {
    $targetPaths = ["/episode/" . trim($episodeId, "/") . "/", "/watch/" . trim($episodeId, "/") . "/"];

    if (preg_match('/^(.*?)-1x(\d+)$/', $episodeId, $matches)) {
        $seriesBase = $matches[1];
        $epNumber   = $matches[2];
        $resolvedEp = resolveEpisodePageUrl($seriesBase, $epNumber);
        if ($resolvedEp) {
            $targetPaths[] = $resolvedEp['url'];
        }
    }

    foreach ($targetPaths as $path) {
        $res = fetchHtmlWithFallback($path);
        if (!isset($res['error'])) {
            $html = $res['html'];
            $activeDomain = $res['active_domain'];
            break;
        }
    }
}

$extractedStreams = $html ? parseStreamEmbedsFromHtml($html, $cleanEpNumber) : ['streamLink' => null, 'servers' => []];

// 5. Automatic Animesalt Fallback if PirateXPlay returned 0 iframes or timed out
if (empty($extractedStreams['servers'])) {
    $fallbackSlug = preg_replace('/-season-\d+-\d+$/i', '', $episodeId);
    $fallbackSlug = preg_replace('/-1x\d+$/i', '', $fallbackSlug);

    $asPaths = [
        "/tv/" . $fallbackSlug . "/",
        "/tv/" . str_replace("demon-slayer-", "", $fallbackSlug) . "/"
    ];

    foreach ($asPaths as $asPath) {
        $asRes = fetchHtmlWithFallback("https://animesalt.me" . $asPath);
        if (isset($asRes['html'])) {
            $extractedFallback = parseStreamEmbedsFromHtml($asRes['html'], $cleanEpNumber);
            if (!empty($extractedFallback['servers'])) {
                $extractedStreams = $extractedFallback;
                $activeDomain = 'animesalt.me';
                break;
            }
        }
    }
}

$streamLink = $extractedStreams['streamLink'];
$servers    = $extractedStreams['servers'];
$downloadLink = $streamLink;

if (empty($servers)) {
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

$titleText = "Episode " . $cleanEpNumber;

/* Construct Lean & Fast JSON Response (Stream object at top, explicit TTL & Cache Mode in info) */
$responseArray = [
    "success" => true,
    "cached"  => false,
    "ttl"     => $ttlLabel,
    "stream"  => [
        "streamLink" => $streamLink,
        "file"       => $downloadLink,
        "servers"    => $servers
    ],
    "info" => [
        "title"     => $titleText,
        "cacheMode" => $cacheMode,
        "id"        => $episodeId ? $episodeId : $movieId,
        "anilistId" => $anilistId ? (int)$anilistId : null,
        "type"      => $type,
        "source"    => str_replace('https://', '', $activeDomain ?? 'piratexplay.cc')
    ]
];

$jsonOutput = json_encode($responseArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// 6. Save Fresh Stream Result to Upstash Redis
if ($streamLink) {
    setRedisCache($cacheKey, $jsonOutput, $ttlSeconds);
}

echo $jsonOutput;
