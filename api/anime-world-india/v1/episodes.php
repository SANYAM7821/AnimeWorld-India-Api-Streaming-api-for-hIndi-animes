<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

$seasonId = isset($_GET['seasonId']) ? trim($_GET['seasonId']) : '';

if ($seasonId === '') {
    echo json_encode(["success" => false, "error" => "Missing seasonId parameter"]);
    exit;
}

$seasonPath = str_starts_with($seasonId, "/") ? $seasonId : "/series/" . $seasonId;
$res = fetchHtmlWithFallback($seasonPath);

if (isset($res['error'])) {
    // Retry with /season/
    $seasonPath = "/season/" . $seasonId;
    $res = fetchHtmlWithFallback($seasonPath);
}

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

// Season Details
$animeTitleNode = $xpath->query("//h1[contains(@class,'entry-title')] | //h1")->item(0);
$posterNode     = $xpath->query("//figure//img | //img[contains(@class,'wp-post-image')] | //img")->item(0);
$descNode       = $xpath->query("//div[contains(@class,'description')]//p | //p[contains(@class,'meta')]")->item(0);
$ratingNode     = $xpath->query("//span[contains(@class,'vote')]")->item(0);

$animeTitle  = $animeTitleNode ? trim(html_entity_decode($animeTitleNode->textContent)) : null;
$poster      = $posterNode ? $posterNode->getAttribute("src") : null;
$description = $descNode ? trim(html_entity_decode($descNode->textContent)) : null;
$rating      = $ratingNode ? trim(preg_replace('/\s+/', ' ', $ratingNode->textContent)) : null;

// Episodes extraction
$episodeLinks = $xpath->query("//a[contains(@href,'/episode/')] | //a[contains(@href,'/watch/')]");
$episodes = [];
$seenEpisodes = [];

foreach ($episodeLinks as $a) {
    $href = $a->getAttribute("href");

    if ($href) {
        $cleanPath = parse_url($href, PHP_URL_PATH);
        $episodeId = trim(str_replace(["/episode/", "/watch/"], "", $cleanPath), "/");

        if ($episodeId && !isset($seenEpisodes[$episodeId])) {
            $seenEpisodes[$episodeId] = true;

            $titleNode = $xpath->query(".//h2[contains(@class,'entry-title')] | .//span[contains(@class,'title')]", $a)->item(0);
            $imgNode   = $xpath->query(".//img", $a)->item(0);

            $epTitle = $titleNode ? trim(html_entity_decode($titleNode->textContent)) : null;
            $image   = $imgNode ? $imgNode->getAttribute("src") : $poster;

            // Extract episode number
            $epNum = "1";
            if (preg_match('/(\d+x\d+|\d+)$/i', $episodeId, $m)) {
                $epNum = "Episode " . $m[1];
            }

            $episodes[] = [
                "episodeId"     => $episodeId,
                "title"         => $epTitle ? $epTitle : ($animeTitle ? $animeTitle . " - " . $epNum : $epNum),
                "episodeNumber" => $epNum,
                "airDate"       => null,
                "image"         => $image,
                "overview"      => $description
            ];
        }
    }
}

echo json_encode([
    "success" => true,
    "source" => str_replace('https://', '', $activeDomain) . "/season",
    "season" => [
        "seasonId"      => $seasonId,
        "animeTitle"    => $animeTitle,
        "seasonName"    => "Season 1",
        "totalEpisodes" => (string)count($episodes),
        "rating"        => $rating,
        "duration"      => null,
        "poster"        => $poster,
        "description"   => $description
    ],
    "episodes" => $episodes
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
