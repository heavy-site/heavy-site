<?php
/* ───────────────────────────────────────────────────────────────
   Shared Monobank helpers for the cPanel/PHP host (he4vy.com).
   - Loads MONOBANK_TOKEN from a config file OUTSIDE the web root.
   - Provides mono_curl() (server-side API calls; X-Token header).
   - Tiny file-based order store in a NON-web data dir, so the
     webhook can mark orders paid across the payment round-trip.
   SECURITY: the token never reaches the browser; all calls are here.
─────────────────────────────────────────────────────────────── */
ini_set('display_errors', '0');                 // never leak errors into responses
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// Locate the secret config. Preferred: one level ABOVE the web root.
// We compute that from DOCUMENT_ROOT so it works regardless of the cPanel
// home path (e.g. /home3/hevycom/public_html → /home3/hevycom/monobank_config.php).
$__docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : '';
$__mono_candidates = [];
if ($__docroot !== '') {
  $__mono_candidates[] = dirname($__docroot) . '/monobank_config.php';  // outside web root (preferred)
  $__mono_candidates[] = $__docroot . '/monobank_config.php';            // inside web root (denied by .htaccess)
}
$__mono_candidates[] = '/home3/hevycom/monobank_config.php';
$__mono_candidates[] = __DIR__ . '/../../monobank_config.php';
$__mono_candidates[] = __DIR__ . '/../monobank_config.php';
$__mono_candidates[] = __DIR__ . '/monobank_config.php';

$__mono_cfg = null;
foreach ($__mono_candidates as $__p) {
  if (is_file($__p)) { $__mono_cfg = $__p; break; }
}
if ($__mono_cfg) require_once $__mono_cfg;

if (!defined('MONO_API'))      define('MONO_API', 'https://api.monobank.ua');
if (!defined('MONO_DATA_DIR')) define('MONO_DATA_DIR', ($__mono_cfg ? dirname($__mono_cfg) : sys_get_temp_dir()) . '/mono_orders');
@mkdir(MONO_DATA_DIR, 0700, true);

function mono_token_ok() {
  return defined('MONOBANK_TOKEN') && MONOBANK_TOKEN !== '' && MONOBANK_TOKEN !== 'REPLACE_WITH_YOUR_MONOBANK_TOKEN';
}

// Server-side Monobank API call. Returns decoded JSON (array) or null.
function mono_curl($method, $url, $payload = null) {
  $ch = curl_init($url);
  $headers = ['X-Token: ' . (defined('MONOBANK_TOKEN') ? MONOBANK_TOKEN : '')];
  if ($payload !== null) {
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  }
  if (strtoupper($method) === 'POST') curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 25,
  ]);
  $r = curl_exec($ch);
  return $r ? json_decode($r, true) : null;
}

// ── Minimal order store (one JSON file per invoice, outside web root) ──
function order_path($invoiceId) {
  return MONO_DATA_DIR . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$invoiceId) . '.json';
}
function order_save($invoiceId, $data) {
  @file_put_contents(order_path($invoiceId), json_encode($data));
}
function order_load($invoiceId) {
  $p = order_path($invoiceId);
  return is_file($p) ? json_decode(file_get_contents($p), true) : null;
}
