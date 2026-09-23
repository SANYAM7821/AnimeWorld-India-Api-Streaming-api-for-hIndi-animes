<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

function parseArticles($html, $type = "series") {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $items = [];
    $articles = $xpath->query("//article[contains(@class,'post')]");

    foreach ($articles as $art) {
        $titleNode  = $xpath->query(".//h2[contains(@class,'entry-title')]", $art)->item(0);
        $imgNode    = $xpath->query(".//img", $art)->item(0);
        $yearNode   = $xpath->query(".//span[contains(@class,'year')]", $art)->item(0);
        $linkNode   = $xpath->query(".//a[contains(@class,'lnk-blk')]", $art)->item(0);
        $ratingNode = $xpath->query(".//span[contains(@class,'vote')]", $art)->item(0);

        $title  = $titleNode ? trim(html_entity_decode($titleNode->textContent)) : null;
        $image  = $imgNode ? $imgNode->getAttribute("src") : null;
        $year   = $yearNode ? trim($yearNode->textContent) : null;
        $link   = $linkNode ? $linkNode->getAttribute("href") : null;
        $rating = $ratingNode ? trim(preg_replace('/\s+/', ' ', $ratingNode->textContent)) : null;

        $id = null;
        if ($link) {
            $cleanPath = parse_url($link, PHP_URL_PATH);
            $cleanPath = trim($cleanPath, "/");
            if ($type === "series") {
                $id = str_replace(["series/", "anime/"], "", $cleanPath);
            } else {
                $id = str_replace(["movies/", "movie/"], "", $cleanPath);
            }
        }

        if ($title) {
            $items[] = [
                "title"  => $title,
                "image"  => $image,
                "year"   => $year,
                "rating" => $rating,
                $type."Id" => $id
            ];
        }
    }
    return $items;
}

$seriesRes = fetchHtmlWithFallback('/category/anime/');
$moviesRes = fetchHtmlWithFallback('/category/movie/');

$latestSeries = isset($seriesRes['html']) ? parseArticles($seriesRes['html'], "series") : [];
$latestMovies = isset($moviesRes['html']) ? parseArticles($moviesRes['html'], "movie") : [];

$activeDomain = $seriesRes['active_domain'] ?? 'piratexplay.cc';

echo json_encode([
    "success" => true,
    "source" => str_replace('https://', '', $activeDomain),
    "latest_series" => $latestSeries,
    "latest_movies" => $latestMovies
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
