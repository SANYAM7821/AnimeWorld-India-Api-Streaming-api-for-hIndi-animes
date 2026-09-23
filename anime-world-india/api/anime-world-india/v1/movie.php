<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

$page = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;

$moviesPath = ($page > 1) ? "/category/movie/page/" . $page : "/category/movie/";
$res = fetchHtmlWithFallback($moviesPath);

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
$movies = [];

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

    $movieId = null;
    if ($link) {
        $cleanPath = parse_url($link, PHP_URL_PATH);
        $cleanPath = trim($cleanPath, "/");
        $movieId   = str_replace(["movies/", "movie/"], "", $cleanPath);
    }

    if ($title) {
        $movies[] = [
            "title"   => $title,
            "image"   => $image,
            "year"    => $year,
            "rating"  => $rating,
            "movieId" => $movieId
        ];
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

$hasNext = $currentPage < $totalPages;
$hasPrev = $currentPage > 1;

echo json_encode([
    "success" => true,
    "source" => str_replace('https://', '', $activeDomain) . "/movies",
    "current_page" => $currentPage,
    "total_pages" => $totalPages,
    "has_next" => $hasNext,
    "has_prev" => $hasPrev,
    "pages" => array_values(array_unique($pages)),
    "total_results" => count($movies),
    "movies" => $movies
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
