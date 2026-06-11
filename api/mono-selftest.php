<?php
/* TEMPORARY diagnostic — reports config/token/pubkey status as BOOLEANS only.
   NEVER prints the token. Remove after debugging (it discloses server paths). */
require __DIR__ . '/_mono.php';
header('Content-Type: application/json; charset=utf-8');

$checked = isset($__mono_candidates) ? $__mono_candidates : [];
$existing = array_values(array_filter($checked, 'is_file'));

$pubkey_ok = false;
if (mono_token_ok()) {
  $r = mono_curl('GET', MONO_API . '/api/merchant/pubkey');
  $pubkey_ok = ($r && !empty($r['key']));
}

echo json_encode([
  'document_root'    => isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : null,
  'config_found'     => $__mono_cfg ?: false,        // resolved path or false
  'config_candidates'=> $checked,                    // where it looked
  'token_set'        => mono_token_ok(),             // bool — NOT the token
  'token_len'        => defined('MONOBANK_TOKEN') ? strlen(MONOBANK_TOKEN) : 0,
  'pubkey_reachable' => $pubkey_ok,
  'data_dir'         => MONO_DATA_DIR,
  'data_dir_writable'=> is_writable(MONO_DATA_DIR),
], JSON_PRETTY_PRINT);
