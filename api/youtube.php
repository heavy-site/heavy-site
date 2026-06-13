<?php
/* youtube.php — Returns the channel's latest long-form videos as JSON.

   Strategy: scrape the channel's "Videos" tab (youtube.com/@handle/videos).
   That tab natively lists ONLY full videos — Shorts live on a separate
   tab and never appear here — and it reflects the live channel, so
   deleted videos drop off automatically. Parsed from the embedded
   ytInitialData JSON. Cached for 15 minutes.

   Falls back to the RSS feed only if scraping fails; the frontend has its
   own curated fallback list if this endpoint returns nothing. */
header('Content-Type: application/json; charset=utf-8');

$HANDLE   = 'heavy_rave';
$CACHE    = __DIR__ . '/../_cache_yt.json';
$CACHE_S  = 900; // 15 min
$MAX      = 15;

// Serve fresh cache (unless ?refresh=1 forces a re-fetch)
$forceRefresh = isset($_GET['refresh']);
if (!$forceRefresh && is_file($CACHE) && (time() - filemtime($CACHE)) < $CACHE_S) {
  readfile($CACHE);
  exit;
}

/* Fetch a URL with a browser-like UA and consent cookie (avoids YouTube's
   EU consent interstitial). Returns body string or '' on failure. */
function yt_get($url) {
  $ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'timeout'       => 8,
    'ignore_errors' => true,
    'header'        =>
      "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 " .
      "(KHTML, like Gecko) Chrome/120.0 Safari/537.36\r\n" .
      "Accept-Language: en-US,en;q=0.9\r\n" .
      "Cookie: CONSENT=YES+1; SOCS=CAI\r\n",
  ]]);
  $body = @file_get_contents($url, false, $ctx);
  return $body ?: '';
}

/* Recursively collect every videoRenderer (videoId + title) in document
   order. The Videos tab contains only long-form videos, so no filtering
   for Shorts is needed. */
function collect_videos($node, &$out, &$seen) {
  if (is_array($node)) {
    if (isset($node['videoRenderer']['videoId'])) {
      $vr = $node['videoRenderer'];
      $id = $vr['videoId'];
      if (!isset($seen[$id])) {
        $title = '';
        if (isset($vr['title']['runs'][0]['text']))      $title = $vr['title']['runs'][0]['text'];
        elseif (isset($vr['title']['simpleText']))        $title = $vr['title']['simpleText'];
        $seen[$id] = true;
        $out[] = ['title' => $title, 'embed' => 'https://www.youtube.com/embed/' . $id, 'id' => $id];
      }
    }
    foreach ($node as $child) {
      if (is_array($child)) collect_videos($child, $out, $seen);
    }
  }
}

$videos = [];

// ── Primary: scrape the Videos tab ──
$page = yt_get('https://www.youtube.com/@' . $HANDLE . '/videos');
if ($page !== '' &&
    preg_match('/ytInitialData\s*=\s*(\{.+?\})\s*;\s*<\/script>/s', $page, $m)) {
  $data = json_decode($m[1], true);
  if (is_array($data)) {
    $seen = [];
    collect_videos($data, $videos, $seen);
  }
}

// ── Fallback: RSS feed (includes Shorts, but better than nothing) ──
if (!$videos) {
  $channelId = '';
  if ($page !== '' && preg_match('/"(?:channelId|externalId)":"(UC[^"]+)"/', $page, $cm)) {
    $channelId = $cm[1];
  }
  if ($channelId !== '') {
    $rss = yt_get('https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId);
    $xml = $rss ? @simplexml_load_string($rss) : null;
    if ($xml) {
      $ns = $xml->getNamespaces(true);
      foreach ($xml->entry as $entry) {
        $yt = $entry->children($ns['yt'] ?? 'http://www.youtube.com/xml/schemas/2015');
        $id = (string)$yt->videoId;
        $title = (string)$entry->title;
        if (stripos($title, '#short') !== false) continue;
        $videos[] = ['title' => $title, 'embed' => 'https://www.youtube.com/embed/' . $id, 'id' => $id];
      }
    }
  }
}

$videos = array_slice($videos, 0, $MAX);

// Don't overwrite a good cache with an empty result (transient fetch failure).
if (!$videos && is_file($CACHE)) {
  readfile($CACHE);
  exit;
}

$json = json_encode($videos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($videos) file_put_contents($CACHE, $json);
echo $json;
