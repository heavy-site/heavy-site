<?php
/* youtube.php — Returns the channel's latest long-form videos as JSON.

   Transport: the channel RSS feed (works reliably from server-side PHP;
   scraping the HTML Videos tab does NOT — YouTube serves bots a stripped
   page without ytInitialData).

   Shorts / deleted filtering: for each RSS video we probe
   youtube.com/shorts/<id> WITHOUT following redirects, all in parallel via
   curl_multi (fast, no sequential timeouts):
       • 3xx redirect to /watch  → real long-form video  → KEEP
       • 200                      → Short                 → SKIP
       • 404 / other              → deleted / unavailable → SKIP

   Cached 15 min. ?refresh=1 forces a re-fetch and clears the cache. */
header('Content-Type: application/json; charset=utf-8');

$HANDLE   = 'heavy_rave';
$CACHE    = __DIR__ . '/../_cache_yt.json';
$IDFILE   = __DIR__ . '/../_cache_yt_id.txt';
$CACHE_S  = 900; // 15 min
$MAX      = 15;

$forceRefresh = isset($_GET['refresh']);
if ($forceRefresh) {
  @unlink($CACHE);
} elseif (is_file($CACHE) && (time() - filemtime($CACHE)) < $CACHE_S) {
  readfile($CACHE);
  exit;
}

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
  return @file_get_contents($url, false, $ctx) ?: '';
}

/* Resolve the channel's UC… id from the handle page (cached to a file). */
function resolve_channel_id($handle, $idFile) {
  if (is_file($idFile)) { $id = trim(file_get_contents($idFile)); if ($id !== '') return $id; }
  $page = yt_get('https://www.youtube.com/@' . $handle);
  if ($page !== '' &&
      (preg_match('#"channelId":"(UC[\w-]+)"#', $page, $m) ||
       preg_match('#/channel/(UC[\w-]+)#', $page, $m) ||
       preg_match('#"externalId":"(UC[\w-]+)"#', $page, $m))) {
    file_put_contents($idFile, $m[1]);
    return $m[1];
  }
  return '';
}

/* Classify a batch of video IDs in parallel. Returns [id => bool isLongVideo].
   Uses curl_multi if available; otherwise marks all unknown (true) so the
   feed degrades to showing everything rather than nothing. */
function classify_long_videos($ids) {
  $result = [];
  if (!function_exists('curl_multi_init')) {
    foreach ($ids as $id) $result[$id] = true; // can't probe → keep
    return $result;
  }
  $mh = curl_multi_init();
  $handles = [];
  foreach ($ids as $id) {
    $ch = curl_init('https://www.youtube.com/shorts/' . $id);
    curl_setopt_array($ch, [
      CURLOPT_NOBODY         => true,   // HEAD
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 7,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$id] = $ch;
  }
  do {
    $status = curl_multi_exec($mh, $running);
    if ($running) curl_multi_select($mh, 1.0);
  } while ($running && $status === CURLM_OK);

  foreach ($handles as $id => $ch) {
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    // Real long-form video: /shorts/<id> redirects to /watch.
    $result[$id] = ($code >= 300 && $code < 400 && strpos($redir, '/watch') !== false);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
  }
  curl_multi_close($mh);
  return $result;
}

// ── Fetch RSS ──
$channelId = resolve_channel_id($HANDLE, $IDFILE);
$entries = [];
if ($channelId !== '') {
  $rss = yt_get('https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId);
  $xml = $rss ? @simplexml_load_string($rss) : null;
  if ($xml) {
    $ns = $xml->getNamespaces(true);
    foreach ($xml->entry as $entry) {
      $yt = $entry->children($ns['yt'] ?? 'http://www.youtube.com/xml/schemas/2015');
      $id = (string)$yt->videoId;
      $title = (string)$entry->title;
      if ($id === '') continue;
      $entries[] = ['id' => $id, 'title' => $title];
    }
  }
}

// ── Filter Shorts / deleted in parallel ──
$ids = array_column($entries, 'id');
$keep = $ids ? classify_long_videos($ids) : [];

$videos = [];
foreach ($entries as $e) {
  if (empty($keep[$e['id']])) continue;
  $videos[] = ['title' => $e['title'], 'embed' => 'https://www.youtube.com/embed/' . $e['id'], 'id' => $e['id']];
}

/* Safety net: if filtering removed EVERYTHING (e.g. the probe was blocked),
   fall back to the unfiltered RSS list — better to show all videos than an
   empty feed. */
if (!$videos && $entries) {
  foreach ($entries as $e) {
    $videos[] = ['title' => $e['title'], 'embed' => 'https://www.youtube.com/embed/' . $e['id'], 'id' => $e['id']];
  }
}

$videos = array_slice($videos, 0, $MAX);

// Diagnostics: /api/youtube?debug=1
if (isset($_GET['debug'])) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'channelId'   => $channelId,
    'curl_multi'  => function_exists('curl_multi_init'),
    'allow_url'   => (bool)ini_get('allow_url_fopen'),
    'rss_count'   => count($entries),
    'kept_count'  => count($videos),
    'classify'    => $keep,
    'entries'     => $entries,
  ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

// Never overwrite a good cache with an empty result.
if (!$videos) {
  if (is_file($CACHE)) { readfile($CACHE); exit; }
  echo '[]';
  exit;
}

$json = json_encode($videos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($CACHE, $json);
echo $json;
