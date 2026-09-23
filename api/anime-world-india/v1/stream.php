<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

$episodeId = isset($_GET['episodeId']) ? trim($_GET['episodeId']) : null;
$movieId   = isset($_GET['movieId']) ? trim($_GET['movieId']) : null;

if (!$episodeId && !$movieId) {
    echo json_encode(["success" => false, "error" => "Missing episodeId or movieId"]);
    exit;
}

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

if (!$html) {
    echo json_encode(["success" => false, "error" => "Failed to find content. Target might be down or ID is invalid."]);
    exit;
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

/* Extract stream link and download link */
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

    echo json_encode([
        "success" => true,
        "type"    => "movie",
        "source"  => str_replace('https://', '', $activeDomain) . "/movie",
        "movie"   => $movie,
        "stream"  => [
            "streamLink" => $streamLink,
            "file"       => $downloadLink,
            "servers"    => $servers
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Episode Mode
$titleNode  = $xpath->query("//h1[contains(@class,'entry-title')] | //h1")->item(0);
$posterNode = $xpath->query("//figure//img | //img")->item(0);
$descNode   = $xpath->query("//div[contains(@class,'description')]//p | //p")->item(0);

$currentEpisode = [
    "episodeId" => $episodeId,
    "title"     => $titleNode ? trim(html_entity_decode($titleNode->textContent)) : null,
    "airDate"   => null,
    "overview"  => $descNode ? trim(html_entity_decode($descNode->textContent)) : null
];

// All episodes
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

echo json_encode([
    "success" => true,
    "type"    => "episode",
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
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
