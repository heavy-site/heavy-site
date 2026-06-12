<?php
/* ───────────────────────────────────────────────────────────────
   debug-log.php — TEMPORARY diagnostic. Shows the tail of the
   webhook + PHP error logs (stored outside the web root) so we can
   see exactly where the payment→ticket→email flow stops.

   Gated by a key so it isn't world-readable (logs contain emails).
   Usage:  /api/debug-log.php?key=heavy-debug-2026
   DELETE THIS FILE once the issue is solved.
─────────────────────────────────────────────────────────────── */
require __DIR__ . '/_mono.php';

header('Content-Type: text/plain; charset=utf-8');

$KEY = 'heavy-debug-2026';
if (!isset($_GET['key']) || !hash_equals($KEY, (string)$_GET['key'])) {
  http_response_code(403);
  echo "forbidden\n";
  exit;
}

function tail_file($path, $maxBytes = 20000) {
  if (!is_file($path)) return "(missing: $path)\n";
  $size = filesize($path);
  $fh = fopen($path, 'rb');
  if ($size > $maxBytes) fseek($fh, -$maxBytes, SEEK_END);
  $data = stream_get_contents($fh);
  fclose($fh);
  return $data;
}

echo "=== ENV ===\n";
echo 'MONO_DATA_DIR = ' . MONO_DATA_DIR . "\n";
echo 'data dir writable = ' . (is_writable(MONO_DATA_DIR) ? 'yes' : 'NO') . "\n";
echo 'memory_limit = ' . ini_get('memory_limit') . "\n";
echo 'TICKET_SECRET set = ' . (defined('TICKET_SECRET') && TICKET_SECRET !== '' ? 'yes' : 'no') . "\n";
echo 'RESEND_API_KEY set = ' . (defined('RESEND_API_KEY') && RESEND_API_KEY !== '' ? 'yes' : 'no') . "\n";
echo 'MAIL_FROM = ' . (defined('MAIL_FROM') ? MAIL_FROM : '(undefined)') . "\n";
echo 'TCPDF class = ' . (class_exists('\\TCPDF') ? 'loaded' : 'NOT loaded (run composer install)') . "\n";
@require_once __DIR__ . '/vendor/autoload.php';
echo 'TCPDF after autoload = ' . (class_exists('\\TCPDF') ? 'loaded' : 'NOT loaded') . "\n";
echo 'Endroid QrCode = ' . (class_exists('\\Endroid\\QrCode\\QrCode') ? 'loaded' : 'NOT loaded') . "\n";

echo "\n=== orders in store ===\n";
$orders = glob(MONO_DATA_DIR . '/*.json');
echo count($orders) . " order file(s)\n";
$recent = array_slice(array_reverse($orders), 0, 5);
foreach ($recent as $f) {
  $o = json_decode(file_get_contents($f), true);
  if (!$o) continue;
  echo '  ' . basename($f, '.json')
     . ' status=' . ($o['status'] ?? '?')
     . ' paid=' . (!empty($o['paid']) ? 'y' : 'n')
     . ' issued=' . (!empty($o['issued']) ? 'y' : 'n')
     . ' emailed=' . (!empty($o['emailed']) ? 'y' : 'n')
     . ' email=' . ($o['email'] ?? '')
     . "\n";
}

echo "\n=== webhook.log (tail) ===\n";
echo tail_file(MONO_DATA_DIR . '/webhook.log');

echo "\n=== php_error.log (tail) ===\n";
echo tail_file(MONO_DATA_DIR . '/php_error.log');
