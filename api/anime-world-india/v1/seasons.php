<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

$seriesID = isset($_GET['seriesID']) ? trim($_GET['seriesID']) : '';

if ($seriesID === '') {
    echo json_encode(["success" => false, "error" => "Missing seriesID parameter"]);
    exit;
}

$seriesPath = str_starts_with($seriesID, "/") ? $seriesID : "/series/" . $seriesID;
$res = fetchHtmlWithFallback($seriesPath);

if (isset($res['error'])) {
    echo json_encode(["success" => false, "error" => "Failed to load HTML: " . $res['error']]);
    exit;
}

$html = $res['html'];
$activeDomain = $res['active_domain'];

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();

$xpath = new DOMXPath($dom);

$titleNode    = $xpath->query("//h1[contains(@class,'entry-title')] | //h1")->item(0);
$imgNode      = $xpath->query("//div[contains(@class,'poster')]//img | //figure//img | //img[contains(@class,'wp-post-image')]")->item(0);
$yearNode     = $xpath->query("//span[contains(@class,'year')]")->item(0);
$durationNode = $xpath->query("//span[contains(@class,'duration')]")->item(0);
$descNode     = $xpath->query("//div[contains(@class,'description')]//p | //p[contains(@class,'meta')] | //div[contains(@class,'entry-content')]//p")->item(0);
$ratingNode   = $xpath->query("//span[contains(@class,'vote')]")->item(0);

$seriesTitle = $titleNode ? trim(html_entity_decode($titleNode->textContent)) : null;
$poster      = $imgNode ? $imgNode->getAttribute("src") : null;
$year        = $yearNode ? trim($yearNode->textContent) : null;
$duration    = $durationNode ? trim($durationNode->textContent) : null;
$description = $descNode ? trim(html_entity_decode($descNode->textContent)) : null;
$rating      = $ratingNode ? trim(preg_replace('/\s+/', ' ', $ratingNode->textContent)) : null;

// Seasons extraction
$seasonNodes = $xpath->query("//a[contains(@class,'season-btn')] | //div[contains(@class,'season-card')] | //a[contains(@href,'/series/')]");
$seasons = [];
$seenSeasons = [];

foreach ($seasonNodes as $btn) {
    $link = $btn->getAttribute("href");
    $name = trim(html_entity_decode($btn->textContent));

    if ($link && str_contains($link, "/series/")) {
        $cleanPath = parse_url($link, PHP_URL_PATH);
        $seasonId  = trim(str_replace("/series/", "", $cleanPath), "/");

        if (!isset($seenSeasons[$seasonId])) {
            $seenSeasons[$seasonId] = true;
            $seasonNum = preg_match('/season-(\d+)/i', $seasonId, $m) ? $m[1] : "1";

            $seasons[] = [
                "seasonNumber" => $seasonNum,
                "seasonName"   => $name ? $name : "Season " . $seasonNum,
                "episodes"     => "Episodes available",
                "seasonId"     => $seasonId
            ];
        }
    }
}

if (empty($seasons)) {
    $seasons[] = [
        "seasonNumber" => "1",
        "seasonName"   => "Season 1",
        "episodes"     => "Episodes available",
        "seasonId"     => trim(str_replace("/series/", "", parse_url($seriesPath, PHP_URL_PATH)), "/")
    ];
}

echo json_encode([
    "success" => true,
    "source" => str_replace('https://', '', $activeDomain) . "/series",
    "series" => [
        "seriesId"     => $seriesID,
        "title"        => $seriesTitle,
        "poster"       => $poster,
        "year"         => $year,
        "duration"     => $duration,
        "rating"       => $rating,
        "totalSeasons" => (string)count($seasons),
        "description"  => $description
    ],
    "seasons" => $seasons
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
