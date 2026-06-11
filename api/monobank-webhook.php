<?php
/* monobank-webhook.php — the trustworthy paid trigger.
   Verifies the X-Sign (ECDSA-SHA256) over the RAW body with Monobank's
   public key before acting. Only valid + status==="success" marks paid. */
require __DIR__ . '/_mono.php';
require __DIR__ . '/_tickets.php';

if (!mono_token_ok()) { http_response_code(500); exit; }

$raw   = file_get_contents('php://input');
$xsign = isset($_SERVER['HTTP_X_SIGN']) ? $_SERVER['HTTP_X_SIGN'] : '';
if ($raw === '' || $xsign === '') { http_response_code(400); exit; }

$pubFile = MONO_DATA_DIR . '/pubkey.pem';

function mono_fetch_pubkey($pubFile) {
  $r = mono_curl('GET', MONO_API . '/api/merchant/pubkey');
  if ($r && !empty($r['key'])) {
    $pem = base64_decode($r['key']);           // pubkey endpoint returns base64 PEM
    @file_put_contents($pubFile, $pem);
    return $pem;
  }
  return null;
}
function mono_verify($raw, $xsign, $pem) {
  if (!$pem) return false;
  // openssl_verify: 1 = valid, 0 = invalid, -1 = error
  return openssl_verify($raw, base64_decode($xsign), $pem, OPENSSL_ALGO_SHA256) === 1;
}

$pem = is_file($pubFile) ? file_get_contents($pubFile) : mono_fetch_pubkey($pubFile);
$ok  = mono_verify($raw, $xsign, $pem);
if (!$ok) {                                     // key may have rotated → refetch once, retry
  $pem = mono_fetch_pubkey($pubFile);
  $ok  = mono_verify($raw, $xsign, $pem);
}
if (!$ok) { http_response_code(403); exit; }    // forged / invalid → reject

$body      = json_decode($raw, true);
$invoiceId = isset($body['invoiceId']) ? $body['invoiceId'] : '';
$status    = isset($body['status'])    ? $body['status']    : '';

$order = order_load($invoiceId);
if ($order) {
  $order['status'] = $status;
  if ($status === 'success' && empty($order['paid'])) {
    $order['paid'] = true;
    // Payment confirmed AND signature verified → generate signed QR
    // tickets and email them (idempotent; logs Resend response).
    try { issue_tickets_and_email($order); }
    catch (\Throwable $e) { error_log('[webhook] issue_tickets failed: ' . $e->getMessage()); }
  }
  order_save($invoiceId, $order);
}
http_response_code(200);                         // ack
