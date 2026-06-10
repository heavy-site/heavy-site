<?php
// API endpoint: never let PHP warnings/notices leak into the JSON body.
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
/* ───────────────────────────────────────────────────────────────
   pCloud album proxy (cPanel / PHP) — mirrors the Node /api/photos.
   The browser CANNOT call eapi.pcloud.com/showpublink directly (CORS),
   so we do it server-side here and return the same JSON shape:
     { ok:true, photos:[ { name, fileid, thumb, large } ] }
   thumb/large are direct getpubthumb URLs (those load fine as <img>).
   Link is e.pcloud.link → EU host first, US host as fallback.
─────────────────────────────────────────────────────────────── */
header('Content-Type: application/json; charset=utf-8');

$APIS = ['https://eapi.pcloud.com', 'https://api.pcloud.com'];

function extract_code($s) {
  if (preg_match('/[?&]code=([^&]+)/', $s, $m)) return urldecode($m[1]);
  return trim($s);
}
function pc_get_json($url) {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => true]);
    $r = curl_exec($ch);
  } else {
    $r = @file_get_contents($url);
  }
  return $r ? json_decode($r, true) : null;
}

$code = isset($_GET['code']) ? extract_code($_GET['code']) : '';
if ($code === '') { http_response_code(400); echo json_encode(['error' => 'Missing code']); exit; }

foreach ($APIS as $base) {
  $meta = pc_get_json($base . '/showpublink?code=' . urlencode($code) . '&iconformat=id');
  if (!$meta || !isset($meta['result']) || $meta['result'] !== 0) continue;

  $md = isset($meta['metadata']) ? $meta['metadata'] : [];
  $files = (!empty($md['isfolder']) && isset($md['contents'])) ? $md['contents'] : [$md];
  $photos = [];
  foreach ($files as $f) {
    if (!empty($f['isfolder'])) continue;
    if (!isset($f['category']) || $f['category'] !== 1) continue;   // 1 = image
    $fid = $f['fileid'];
    $q = 'code=' . urlencode($code) . '&fileid=' . $fid;
    $photos[] = [
      'name'   => isset($f['name']) ? $f['name'] : ('photo-' . $fid),
      'fileid' => $fid,
      'thumb'  => $base . '/getpubthumb?' . $q . '&size=600x600&crop=0',
      'large'  => $base . '/getpubthumb?' . $q . '&size=2048x2048&crop=0&type=auto',
    ];
  }
  echo json_encode(['ok' => true, 'photos' => $photos]);
  exit;
}

http_response_code(502);
echo json_encode(['error' => 'Could not load pCloud album']);
