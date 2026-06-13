<?php
/* youtube.php — Returns the channel's latest long-form videos as JSON.

   Strategy: scrape the channel's "Videos" tab (youtube.com/@handle/videos).
   That tab natively lists ONLY full videos — Shorts live on a separate tab
   and never appear here — and it reflects the LIVE channel, so deleted
   videos drop off automatically. Parsed from the embedded ytInitialData
   JSON via a brace-balanced extractor (robust to YouTube markup changes).
   Cached for 15 minutes.

   We deliberately do NOT fall back to the RSS feed: RSS includes Shorts
   and lags on deletions, which is exactly what reintroduces the broken /
   removed videos. If scraping yields nothing, we keep the previous good
   cache; the frontend has its own curated fallback list otherwise. */
header('Content-Type: application/json; charset=utf-8');

$HANDLE   = 'heavy_rave';
$CACHE    = __DIR__ . '/../_cache_yt.json';
$CACHE_S  = 900; // 15 min
$MAX      = 15;

// Serve fresh cache (unless ?refresh=1 forces a re-fetch + drops stale cache)
$forceRefresh = isset($_GET['refresh']);
if ($forceRefresh) {
  @unlink($CACHE);
} elseif (is_file($CACHE) && (time() - filemtime($CACHE)) < $CACHE_S) {
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

/* Extract the JSON object assigned to `ytInitialData` using brace balancing
   that respects strings/escapes — far more robust than a lazy regex. */
function extract_initial_data($html) {
  $needle = 'ytInitialData';
  $p = strpos($html, $needle);
  while ($p !== false) {
    // Find the first '{' after the marker (skip the ' = ' part)
    $brace = strpos($html, '{', $p);
    $eq    = strpos($html, '=', $p);
    if ($brace !== false && ($eq === false || $brace > $eq)) {
      $json = scan_balanced_object($html, $brace);
      if ($json !== '') {
        $data = json_decode($json, true);
        if (is_array($data)) return $data;
      }
    }
    $p = strpos($html, $needle, $p + strlen($needle));
  }
  return null;
}

/* Given the index of an opening '{', return the substring through its
   matching '}', honoring string literals and escapes. */
function scan_balanced_object($s, $start) {
  $len = strlen($s);
  $depth = 0;
  $inStr = false;
  $esc = false;
  for ($i = $start; $i < $len; $i++) {
    $c = $s[$i];
    if ($inStr) {
      if ($esc)            { $esc = false; }
      elseif ($c === '\\') { $esc = true; }
      elseif ($c === '"')  { $inStr = false; }
    } else {
      if ($c === '"')      { $inStr = true; }
      elseif ($c === '{')  { $depth++; }
      elseif ($c === '}')  { $depth--; if ($depth === 0) return substr($s, $start, $i - $start + 1); }
    }
  }
  return '';
}

/* Recursively collect every videoRenderer (videoId + title) in document
   order. The Videos tab contains only long-form videos. */
function collect_videos($node, &$out, &$seen) {
  if (!is_array($node)) return;
  if (isset($node['videoRenderer']['videoId'])) {
    $vr = $node['videoRenderer'];
    $id = $vr['videoId'];
    if (!isset($seen[$id])) {
      $title = '';
      if (isset($vr['title']['runs'][0]['text'])) $title = $vr['title']['runs'][0]['text'];
      elseif (isset($vr['title']['simpleText']))  $title = $vr['title']['simpleText'];
      $seen[$id] = true;
      $out[] = ['title' => $title, 'embed' => 'https://www.youtube.com/embed/' . $id, 'id' => $id];
    }
  }
  foreach ($node as $child) {
    if (is_array($child)) collect_videos($child, $out, $seen);
  }
}

$videos = [];
$page = yt_get('https://www.youtube.com/@' . $HANDLE . '/videos');
if ($page !== '') {
  $data = extract_initial_data($page);
  if ($data) {
    $seen = [];
    collect_videos($data, $videos, $seen);
  }
}

$videos = array_slice($videos, 0, $MAX);

// Never overwrite a good cache with an empty result (transient failure).
if (!$videos) {
  if (is_file($CACHE)) { readfile($CACHE); exit; }
  echo '[]';
  exit;
}

$json = json_encode($videos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($CACHE, $json);
echo $json;
