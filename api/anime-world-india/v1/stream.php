<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

$episodeId = isset($_GET['episodeId']) ? trim($_GET['episodeId']) : null;
$movieId   = isset($_GET['movieId']) ? trim($_GET['movieId']) : null;

if (!$episodeId && !$movieId) {
    echo json_encode([
        "success" => false,
        "error" => "Missing episodeId or movieId"
    ]);
    exit;
}

$html = null;
$activeDomain = null;
$type = $episodeId ? "episode" : "movie";

if ($type === "movie") {
    $targetPath = "/movie/" . $movieId;
    $res = fetchHtmlWithFallback($targetPath);
    if (!isset($res['error'])) {
        $html = $res['html'];
        $activeDomain = $res['active_domain'];
    }
} else {
    // Episode logic with intelligent variation hunting if direct hit fails
    $candidates = ["/episode/" . $episodeId];

    // If the episodeId looks like 'slug-s1-e1' or 'slug-1x1', generate variations
    if (preg_match('/^(.*)-(?:s|season-)?(\d+)(?:x|xe|e|episode-)?(\d+)$/i', $episodeId, $matches)) {
        $slug = $matches[1];
        $s = (int)$matches[2];
        $e = (int)$matches[3];
        $ePadded = str_pad($e, 2, '0', STR_PAD_LEFT);

        $candidates[] = "/episode/$slug-{$s}x$e/";
        $candidates[] = "/episode/$slug-{$s}x$ePadded/";
        $candidates[] = "/episode/$slug-season-$s-episode-$e/";
        $candidates[] = "/episode/$slug-s$s-e$e/";
    }

    foreach ($candidates as $path) {
        $res = fetchHtmlWithFallback($path);
        if (!isset($res['error'])) {
            $html = $res['html'];
            $activeDomain = $res['active_domain'];
            break;
        }
    }
}

if (!$html) {
    echo json_encode(["success" => false, "error" => "Failed to find content. Target might be down or ID is invalid."]);
    exit;
}

// Load DOM
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

/* =========================
   COMMON – STREAM DATA (Intelligent Regex matching)
========================= */

$iframeNode = $xpath->query("//div[contains(@class,'video-embed')]//iframe")->item(0);
$downloadNode = $xpath->query("//div[contains(@class,'archive-link-wrap')]//a")->item(0);

$streamLink = $iframeNode ? $iframeNode->getAttribute("src") : null;
$downloadLink = $downloadNode ? $downloadNode->getAttribute("href") : null;

// Fallback regex to capture hidden script variables or post players if iframe is blank
if (!$streamLink) {
    if (preg_match('/<iframe[^>]+src=["\'](https?:\/\/play\.zephyrix\.org\/video\/[a-zA-Z0-9]+)["\']/i', $html, $zM)) {
        $streamLink = $zM[1];
    } elseif (preg_match('/(?:src|data-src)=["\'](https?:\/\/[^"\']+(?:player1\.php|zephyrix|embed)[^"\']+)["\']/i', $html, $pM)) {
        $streamLink = $pM[1];
    }
}

/* =========================
   MOVIE MODE
========================= */

if ($type === "movie") {

    $titleNode = $xpath->query("//h1[contains(@class,'entry-title')]")->item(0);
    $posterNode = $xpath->query("//div[contains(@class,'post-thumbnail')]//img")->item(0);
    $descNode = $xpath->query("//div[contains(@class,'description')]//p")->item(0);
    $yearNode = $xpath->query("//span[contains(@class,'year')]")->item(0);
    $durationNode = $xpath->query("//span[contains(@class,'duration')]")->item(0);
    $ratingNode = $xpath->query("//span[contains(@class,'vote')]//span[contains(@class,'num')]")->item(0);

    $movie = [
        "movieId" => $movieId,
        "title" => $titleNode ? trim(html_entity_decode($titleNode->textContent)) : null,
        "poster" => $posterNode ? $posterNode->getAttribute("src") : null,
        "description" => $descNode ? trim(html_entity_decode($descNode->textContent)) : null,
        "year" => $yearNode ? trim($yearNode->textContent) : null,
        "duration" => $durationNode ? trim($durationNode->textContent) : null,
        "rating" => $ratingNode ? trim($ratingNode->textContent) : null
    ];

    echo json_encode([
        "success" => true,
        "type" => "movie",
        "source" => str_replace('https://', '', $activeDomain) . "/movie",
        "movie" => $movie,
        "stream" => [
            "streamLink" => $streamLink,
            "file" => $downloadLink
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    exit;
}

/* =========================
   EPISODE MODE
========================= */

// Series / Season Info
$animeTitleNode = $xpath->query("//h1[contains(@class,'anime-title')]")->item(0);
$posterNode = $xpath->query("//div[contains(@class,'season-poster')]//img")->item(0);
$descNode = $xpath->query("//p[contains(@class,'season-desc')]")->item(0);

$metaNodes = $xpath->query("//div[contains(@class,'meta-row')]//span[contains(@class,'meta-item')]");

$seasonName = null;
$totalEpisodes = null;
$rating = null;
$duration = null;

foreach ($metaNodes as $meta) {
    $text = trim($meta->textContent);
    if (str_starts_with($text, "Season:")) {
        $seasonName = trim(str_replace("Season:", "", $text));
    } elseif (str_starts_with($text, "Episodes:")) {
        $totalEpisodes = trim(str_replace("Episodes:", "", $text));
    } elseif (str_starts_with($text, "Rating:")) {
        $rating = trim(str_replace("Rating:", "", $text));
    } elseif (str_starts_with($text, "Duration:")) {
        $duration = trim(str_replace("Duration:", "", $text));
    }
}

// Current Episode Info
$currentTitleNode = $xpath->query("//h1[contains(@class,'episode-title')]")->item(0);
$currentDateNode = $xpath->query("//span[contains(text(),'Air Date')]")->item(0);
$currentOverviewNode = $xpath->query("//p[contains(@class,'episode-overview')]")->item(0);

$currentEpisode = [
    "episodeId" => $episodeId,
    "title" => $currentTitleNode ? trim(html_entity_decode($currentTitleNode->textContent)) : null,
    "airDate" => $currentDateNode ? trim(str_replace("Air Date:", "", $currentDateNode->textContent)) : null,
    "overview" => $currentOverviewNode ? trim(html_entity_decode($currentOverviewNode->textContent)) : null
];

// Prev / Next
$prevNode = $xpath->query("//div[contains(@class,'nav-prev')]//a")->item(0);
$nextNode = $xpath->query("//div[contains(@class,'nav-next')]//a")->item(0);

$prevId = null;
$nextId = null;

if ($prevNode) {
    $href = $prevNode->getAttribute("href");
    if ($href && str_contains($href, "/episode/")) {
        $prevId = trim(str_replace("/episode/", "", $href), "/");
    }
}

if ($nextNode) {
    $href = $nextNode->getAttribute("href");
    if ($href && str_contains($href, "/episode/")) {
        $nextId = trim(str_replace("/episode/", "", $href), "/");
    }
}

// All Episodes
$episodeLinks = $xpath->query("//section[contains(@class,'episodes-section')]//a[contains(@href,'/episode/')]");

$episodes = [];

foreach ($episodeLinks as $a) {
    $href = $a->getAttribute("href");
    $epId = null;
    if ($href && str_contains($href, "/episode/")) {
        $epId = trim(str_replace("/episode/", "", $href), "/");
    }

    $titleNode = $xpath->query(".//h2[contains(@class,'entry-title')]", $a)->item(0);
    $imgNode = $xpath->query(".//img", $a)->item(0);
    $numNode = $xpath->query(".//span[contains(@class,'year')]", $a)->item(0);
    $dateNode = $xpath->query(".//span[contains(@class,'number')]", $a)->item(0);

    $episodes[] = [
        "episodeId" => $epId,
        "title" => $titleNode ? trim(html_entity_decode($titleNode->textContent)) : null,
        "episodeNumber" => $numNode ? trim($numNode->textContent) : null,
        "airDate" => $dateNode ? trim($dateNode->textContent) : null,
        "image" => $imgNode ? $imgNode->getAttribute("src") : null
    ];
}

// Output
echo json_encode([
    "success" => true,
    "type" => "episode",
    "source" => str_replace('https://', '', $activeDomain) . "/episode",
    "series" => [
        "title" => $animeTitleNode ? trim(html_entity_decode($animeTitleNode->textContent)) : null,
        "poster" => $posterNode ? $posterNode->getAttribute("src") : null,
        "season" => $seasonName,
        "totalEpisodes" => $totalEpisodes,
        "rating" => $rating,
        "duration" => $duration,
        "description" => $descNode ? trim(html_entity_decode($descNode->textContent)) : null
    ],
    "current" => $currentEpisode,
    "previous" => $prevId,
    "next" => $nextId,
    "episodes" => $episodes,
    "stream" => [
        "streamLink" => $streamLink,
        "file" => $downloadLink
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
