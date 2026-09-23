<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

$letter = $_GET['letter'] ?? '';
$page   = $_GET['page'] ?? 1;

$letter = trim($letter);
$page   = (int)$page;

if ($letter === '') {
    echo json_encode(["success" => false, "error" => "letter parameter is required"]);
    exit;
}

$a2zPath = ($page > 1) ? "/category/anime/page/" . $page : "/category/anime/";
$res = fetchHtmlWithFallback($a2zPath);

if (isset($res['error'])) {
    echo json_encode(["success" => false, "error" => "Failed to fetch page: " . $res['error']]);
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
$items = [];

foreach ($articles as $art) {
    $titleNode  = $xpath->query(".//h2[contains(@class,'entry-title')]", $art)->item(0);
    $imgNode    = $xpath->query(".//img", $art)->item(0);
    $yearNode   = $xpath->query(".//span[contains(@class,'year')]", $art)->item(0);
    $linkNode   = $xpath->query(".//a[contains(@class,'lnk-blk')]", $art)->item(0);
    $ratingNode = $xpath->query(".//span[contains(@class,'vote')]", $art)->item(0);

    $title  = $titleNode ? trim(html_entity_decode($titleNode->textContent)) : null;
    $poster = $imgNode ? $imgNode->getAttribute("src") : null;
    $year   = $yearNode ? trim($yearNode->textContent) : null;
    $link   = $linkNode ? $linkNode->getAttribute("href") : null;
    $rating = $ratingNode ? trim(preg_replace('/\s+/', ' ', $ratingNode->textContent)) : null;

    $id = null;
    if ($link) {
        $cleanPath = parse_url($link, PHP_URL_PATH);
        $id = trim($cleanPath, "/");
    }

    if ($title && ($letter === '0-9' || str_starts_with(strtolower($title), strtolower($letter)))) {
        $items[] = [
            "title"  => $title,
            "rating" => $rating,
            "year"   => $year,
            "poster" => $poster,
            "url"    => $link ? $activeDomain . $link : null,
            "id"     => $id,
            "type"   => str_contains($id ?? '', "movie") ? "movie" : "series"
        ];
    }
}

echo json_encode([
    "success"       => true,
    "letter"        => $letter,
    "current_page"  => $page,
    "total_pages"   => 1,
    "has_next"      => false,
    "has_prev"      => $page > 1,
    "total_results" => count($items),
    "results"       => $items
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
