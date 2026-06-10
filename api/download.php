<?php
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
/* ───────────────────────────────────────────────────────────────
   Full-resolution image proxy (cPanel / PHP) — mirrors Node
   /api/download (and /api/photo via ?inline=1).
   getpublinkdownload tokens are IP-bound, so we resolve AND fetch
   here (same server IP), then stream the bytes to the browser.
   ?inline=1 → shown inline;  otherwise → attachment download.
─────────────────────────────────────────────────────────────── */
$APIS = ['https://eapi.pcloud.com', 'https://api.pcloud.com'];

function extract_code($s) {
  if (preg_match('/[?&]code=([^&]+)/', $s, $m)) return urldecode($m[1]);
  return trim($s);
}
function pc_get_json($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => true]);
  $r = curl_exec($ch);
  return $r ? json_decode($r, true) : null;
}

$code   = isset($_GET['code'])   ? extract_code($_GET['code']) : '';
$fileid = isset($_GET['fileid']) ? preg_replace('/\D/', '', $_GET['fileid']) : '';
$inline = isset($_GET['inline']);
if ($code === '' || $fileid === '') { http_response_code(400); echo 'Missing code or fileid'; exit; }

$full = null;
foreach ($APIS as $base) {
  $dl = pc_get_json($base . '/getpublinkdownload?code=' . urlencode($code) . '&fileid=' . urlencode($fileid));
  if ($dl && isset($dl['result']) && $dl['result'] === 0 && !empty($dl['hosts'])) {
    $full = 'https://' . $dl['hosts'][0] . $dl['path'];
    break;
  }
}
if (!$full) { http_response_code(502); echo 'Could not resolve image'; exit; }

// Filename for the attachment (sanitised).
$name = isset($_GET['name']) ? preg_replace('/[^\w.\- ]+/u', '_', $_GET['name']) : ('ticket-' . $fileid . '.jpg');
if (!preg_match('/\.\w{2,4}$/', $name)) $name .= '.jpg';

if ($inline) header('Content-Disposition: inline');
else         header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: public, max-age=3600');

// Stream upstream → client, copying its Content-Type/Length.
$ch = curl_init($full);
curl_setopt_array($ch, [
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HEADER => false,
  CURLOPT_HEADERFUNCTION => function ($c, $h) {
    if (stripos($h, 'Content-Type:') === 0 || stripos($h, 'Content-Length:') === 0) header(trim($h));
    return strlen($h);
  },
  CURLOPT_WRITEFUNCTION => function ($c, $data) { echo $data; return strlen($data); },
]);
curl_exec($ch);
