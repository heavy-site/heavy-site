<?php
/* youtube.php — Returns latest YouTube videos as JSON.
   Fetches the channel RSS feed, caches it for 15 minutes. */
header('Content-Type: application/json; charset=utf-8');

$HANDLE   = 'heavy_rave';
$CACHE    = __DIR__ . '/../_cache_yt.json';
$CACHE_S  = 900; // 15 min

// Serve cache if fresh
if (is_file($CACHE) && (time() - filemtime($CACHE)) < $CACHE_S) {
  readfile($CACHE);
  exit;
}

// Resolve channel ID from handle (cached separately)
$idFile = __DIR__ . '/../_cache_yt_id.txt';
$channelId = is_file($idFile) ? trim(file_get_contents($idFile)) : '';

if ($channelId === '') {
  $page = @file_get_contents('https://www.youtube.com/@' . $HANDLE);
  if ($page && preg_match('/"channelId":"(UC[^"]+)"/', $page, $m)) {
    $channelId = $m[1];
    file_put_contents($idFile, $channelId);
  } elseif ($page && preg_match('/"externalId":"(UC[^"]+)"/', $page, $m)) {
    $channelId = $m[1];
    file_put_contents($idFile, $channelId);
  }
}

if ($channelId === '') {
  http_response_code(502);
  echo json_encode(['error' => 'Could not resolve channel ID']);
  exit;
}

// Fetch RSS feed
$rss = @file_get_contents('https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId);
if (!$rss) {
  http_response_code(502);
  echo json_encode(['error' => 'RSS fetch failed']);
  exit;
}

$xml = @simplexml_load_string($rss);
if (!$xml) {
  http_response_code(502);
  echo json_encode(['error' => 'RSS parse failed']);
  exit;
}

$ns = $xml->getNamespaces(true);
$videos = [];

foreach ($xml->entry as $entry) {
  $yt = $entry->children($ns['yt'] ?? 'http://www.youtube.com/xml/schemas/2015');
  $media = $entry->children($ns['media'] ?? 'http://search.yahoo.com/mrss/');

  $videoId = (string)$yt->videoId;
  $title   = (string)$entry->title;

  // Skip Shorts (typically under 60s, but RSS doesn't expose duration reliably).
  // Heuristic: skip if title contains #shorts (common convention).
  if (stripos($title, '#short') !== false) continue;

  $videos[] = [
    'title' => $title,
    'embed' => 'https://www.youtube.com/embed/' . $videoId,
    'id'    => $videoId,
  ];
}

$json = json_encode($videos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($CACHE, $json);
echo $json;
