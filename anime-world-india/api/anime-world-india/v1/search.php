<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$page  = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;

if ($query === '') {
    echo json_encode(["success" => false, "error" => "Missing query parameter"]);
    exit;
}

$searchPath = "/?s=" . urlencode($query) . ($page > 1 ? "&page=" . $page : "");
$res = fetchHtmlWithFallback($searchPath);

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

$articles = $xpath->query("//article[contains(@class,'post')]");
$results = [];

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

    $type = "series";
    $seriesId = null;
    $movieId  = null;

    if ($link) {
        $cleanPath = parse_url($link, PHP_URL_PATH);
        $cleanPath = trim($cleanPath, "/");

        if (str_contains($cleanPath, "movie")) {
            $type = "movie";
            $movieId = str_replace(["movies/", "movie/"], "", $cleanPath);
        } else {
            $type = "series";
            $seriesId = str_replace(["series/", "anime/"], "", $cleanPath);
        }
    }

    if ($title) {
        $item = [
            "title"  => $title,
            "image"  => $image,
            "year"   => $year,
            "rating" => $rating,
            "type"   => $type
        ];

        if ($type === "series") {
            $item["seriesId"] = $seriesId;
        } else {
            $item["movieId"] = $movieId;
        }

        $results[] = $item;
    }
}

// Pagination
$pages = [];
$currentPage = $page;
$totalPages = 1;

$pageNodes = $xpath->query("//a[contains(@class,'page-link')]");
foreach ($pageNodes as $pNode) {
    $num = trim($pNode->textContent);
    if (is_numeric($num)) {
        $pages[] = (int)$num;
        if (str_contains($pNode->getAttribute("class"), "current")) {
            $currentPage = (int)$num;
        }
    }
}

if (!empty($pages)) {
    $totalPages = max($pages);
}

$hasNextPage = $currentPage < $totalPages;

echo json_encode([
    "success" => true,
    "query" => $query,
    "currentPage" => $currentPage,
    "totalPages" => $totalPages,
    "hasNextPage" => $hasNextPage,
    "source" => str_replace('https://', '', $activeDomain) . "/search",
    "results" => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
