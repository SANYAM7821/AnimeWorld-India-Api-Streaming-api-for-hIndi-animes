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

// Helper to filter out non-video iframes (e.g. YouTube trailers, ads, social embeds)
function isIgnoredIframeUrl($url) {
    if (empty($url) || str_contains($url, "about:blank")) {
        return true;
    }
    $ignoredKeywords = ['youtube.com', 'youtu.be', 'facebook.com', 'twitter.com', 'google.com', 'doubleclick', 'disqus'];
    foreach ($ignoredKeywords as $kw) {
        if (str_contains(strtolower($url), $kw)) {
            return true;
        }
    }
    return false;
}

// Helper function to extract servers from HTML (supports PirateXPlay iframes & Animesalt JS triggers)
function parseStreamEmbedsFromHtml($html, $cleanEp = '1') {
    $servers = [];
    $streamLink = null;

    // 1. Try PirateXPlay iframe extraction (excluding YouTube / non-video iframes)
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

        if ($url && !isIgnoredIframeUrl($url)) {
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
                if ($url && !isIgnoredIframeUrl($url)) {
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

    // 2. Animesalt triggerEpisode JS extraction (supporting &quot; and quotes)
    if (empty($servers)) {
        if (preg_match_all('/triggerEpisode\(\s*(\[\s*\{.*?\}\s*\])\s*,\s*(?:&quot;|["\'])(.*?)(?:&quot;|["\'])/s', $html, $allMatches, PREG_SET_ORDER)) {
            foreach ($allMatches as $match) {
                $rawJson = html_entity_decode($match[1]);
                $epLabel = trim(html_entity_decode($match[2])); // e.g. "Episode 15" or "ep-15"

                if (preg_match('/^Episode\s*' . $cleanEp . '$/i', $epLabel) ||
                    preg_match('/^ep-' . $cleanEp . '$/i', $epLabel) ||
                    preg_match('/\bEpisode\s*' . $cleanEp . '\b/i', $epLabel) ||
                    preg_match('/\bEP\s*' . $cleanEp . '\b/i', $epLabel) ||
                    preg_match('/\bep-' . $cleanEp . '\b/i', $epLabel) ||
                    $epLabel === $cleanEp) {

                    $parsed = json_decode($rawJson, true);
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
                    if (!empty($servers)) {
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
    $targetEpInt = (int)$cleanEp;

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

    // Check all season buttons
    $seasonBtns = $xpath->query("//a[contains(@class,'season-btn')] | //a[contains(@href,'/series/')]");
    $seasonUrls = [];

    foreach ($seasonBtns as $sBtn) {
        $href = $sBtn->getAttribute("href");
        if ($href && str_contains($href, "/series/")) {
            $seasonUrls[] = parse_url($href, PHP_URL_PATH);
        }
    }

    // Estimate season URLs for long multi-season shows
    if (preg_match('/^(.*?)-season-\d+-(.*)$/i', $seriesSlug, $m)) {
        $basePre = $m[1];
        $basePost = $m[2];
        $estSeason = (int)ceil($targetEpInt / 50);
        if ($estSeason < 1) $estSeason = 1;

        $seasonUrls[] = "/series/{$basePre}-season-{$estSeason}-{$basePost}/";
        $seasonUrls[] = "/series/{$basePre}-season-" . ($estSeason + 1) . "-{$basePost}/";
        $seasonUrls[] = "/series/{$basePre}-season-" . max(1, $estSeason - 1) . "-{$basePost}/";
    }

    foreach (array_unique($seasonUrls) as $sUrl) {
        if (!empty($sUrl)) {
            $sRes = fetchHtmlWithFallback($sUrl);
            if (isset($sRes['html'])) {
                $sDom = new DOMDocument();
                libxml_use_internal_errors(true);
                $sDom->loadHTML($sRes['html']);
                libxml_clear_errors();
                $sXpath = new DOMXPath($sDom);

                $sEpLinks = $sXpath->query("//a[contains(@href,'/episode/')]");
                foreach ($sEpLinks as $a) {
                    $eHref = $a->getAttribute("href");
                    if (str_contains($eHref, "x" . $cleanEp . "/") || str_contains($eHref, "-" . $cleanEp . "/")) {
                        return ['url' => parse_url($eHref, PHP_URL_PATH), 'domain' => $sRes['active_domain']];
                    }
                }
            }
        }
    }

    return null;
}

// 1. Resolve AniList ID if provided
if ($anilistId && !$episodeId && !$movieId) {
    $resolvedData = resolveAniListToSlug($anilistId, $forceRefresh);
    if ($resolvedData && isset($resolvedData['slug'])) {
        $resolvedSlug = $resolvedData['slug'];
        $resolvedType = $resolvedData['type'] ?? 'series';

        if ($resolvedType === 'movie') {
            $movieId = $resolvedSlug;
        } else {
            $resolvedEp = resolveEpisodePageUrl($resolvedSlug, $epNum);
            if ($resolvedEp) {
                $episodeId = trim($resolvedEp['url'], "/");
                if (str_starts_with($episodeId, "episode/")) {
                    $episodeId = str_replace("episode/", "", $episodeId);
                }
            } else {
                $episodeId = $resolvedSlug . "-1x" . $cleanEpNumber;
            }
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
$type = $movieId ? "movie" : "episode";

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

// 5. Automatic Secondary Source Fallback (Animesalt) if PirateXPlay returned 0 iframes or timed out
if (empty($extractedStreams['servers'])) {
    $rawSlug = $episodeId ?? $movieId;
    $fallbackSlug = preg_replace('/(-season-\d+-\d+|-season-\d+|-1x\d+|\d+x\d+).*$/i', '', $rawSlug);
    $fallbackSlug = trim($fallbackSlug, "-");

    $asPaths = [
        "https://animesalt.me/tv/" . $fallbackSlug . "/",
        "https://animesalt.me/tv/" . str_replace("demon-slayer-", "", $fallbackSlug) . "/"
    ];

    if (!empty($anilistId)) {
        $aniData = fetchAniListDetails($anilistId);
        if ($aniData) {
            if (!empty($aniData['title']['english'])) {
                $asPaths[] = "https://animesalt.me/tv/" . strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $aniData['title']['english']), '-')) . "/";
            }
            if (!empty($aniData['title']['romaji'])) {
                $asPaths[] = "https://animesalt.me/tv/" . strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $aniData['title']['romaji']), '-')) . "/";
            }
        }
    }

    foreach (array_unique($asPaths) as $asPath) {
        $asRes = fetchHtmlWithFallback($asPath);
        if (isset($asRes['html'])) {
            $extractedFallback = parseStreamEmbedsFromHtml($asRes['html'], $cleanEpNumber);
            if (!empty($extractedFallback['servers'])) {
                $extractedStreams = $extractedFallback;
                $activeDomain = $asRes['active_domain'];
                break;
            }
        }
    }
}

$streamLink   = $extractedStreams['streamLink'];
$servers      = $extractedStreams['servers'];
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

$titleText = ($type === "movie") ? "Movie Stream" : "Episode " . $cleanEpNumber;

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

// 6. Save Fresh Stream Result to Upstash Redis ONLY if valid streamLink and servers exist!
if ($streamLink && !empty($servers)) {
    setRedisCache($cacheKey, $jsonOutput, $ttlSeconds);
}

echo $jsonOutput;
