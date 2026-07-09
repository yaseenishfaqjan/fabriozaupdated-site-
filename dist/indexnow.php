<?php
/**
 * IndexNow submitter for fabrioza.com
 * Reads sitemap.xml locally (avoids self-referencing HTTPS calls, which
 * cPanel security modules like Imunify360 often block) and submits all
 * URLs to IndexNow, feeding Bing + Yandex in one call.
 * Run manually by visiting /indexnow.php, or via cPanel Cron Job:
 *   curl -s https://fabrioza.com/indexnow.php > /dev/null   (daily)
 */

$host = "fabrioza.com";
$sitemapPath = __DIR__ . "/sitemap.xml";
$indexNowKey = "6e0726a1783a50994f70e2ffd2987f22";
$keyLocation = "https://{$host}/{$indexNowKey}.txt";
$endpoint = "https://api.indexnow.org/indexnow";

header("Content-Type: text/plain");

$xml = @file_get_contents($sitemapPath);
if ($xml === false) {
    die("ERROR: Could not read sitemap at {$sitemapPath}.\n");
}

$urls = [];
if (preg_match_all('/<loc>(.*?)<\/loc>/', $xml, $matches)) {
    $urls = array_map('trim', $matches[1]);
}
if (empty($urls)) {
    die("ERROR: No <loc> URLs found in sitemap.\n");
}

echo "Found " . count($urls) . " URLs in sitemap.\n";

$payload = json_encode([
    "host" => $host,
    "key" => $indexNowKey,
    "keyLocation" => $keyLocation,
    "urlList" => $urls,
]);

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json; charset=utf-8"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Submitted to IndexNow. HTTP status: {$statusCode}\n";
switch ($statusCode) {
    case 200: case 202: echo "SUCCESS: URLs accepted.\n"; break;
    case 400: echo "ERROR 400: Bad request.\n"; break;
    case 403: echo "ERROR 403: Key file not found/matching at {$keyLocation}\n"; break;
    case 422: echo "ERROR 422: URL/key mismatch.\n"; break;
    case 429: echo "ERROR 429: Rate limited.\n"; break;
    default: echo "Unexpected response: {$response}\n";
}
