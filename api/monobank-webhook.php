<?php
/* monobank-webhook.php — the trustworthy paid trigger.
   Verifies the X-Sign (ECDSA-SHA256) over the RAW body with Monobank's
   public key before acting. Only valid + status==="success" marks paid. */
require __DIR__ . '/_mono.php';
require __DIR__ . '/_tickets.php';

// STEP 1 — log the hit before ANY logic (proves Monobank reaches us).
$raw   = file_get_contents('php://input');
$xsign = isset($_SERVER['HTTP_X_SIGN']) ? $_SERVER['HTTP_X_SIGN'] : '';
wlog('WEBHOOK HIT method=' . $_SERVER['REQUEST_METHOD'] . ' xsign=' . substr($xsign, 0, 44) . ' raw=' . substr($raw, 0, 800));

if (!mono_token_ok()) { wlog('ABORT: token not configured'); http_response_code(500); exit; }
if ($raw === '' || $xsign === '') { wlog('ABORT: empty raw body or X-Sign (likely a manual/GET hit, not Monobank)'); http_response_code(400); exit; }

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
if (!$ok) { wlog('SIG FAIL — rejected (pem ' . ($pem ? 'present' : 'MISSING') . ', openssl ' . (extension_loaded('openssl') ? 'on' : 'OFF') . ')'); http_response_code(403); exit; }
wlog('SIG OK');

$body      = json_decode($raw, true);
$invoiceId = isset($body['invoiceId']) ? $body['invoiceId'] : '';
$status    = isset($body['status'])    ? $body['status']    : '';
wlog('PARSED status=' . var_export($status, true) . ' invoiceId=' . $invoiceId . ' payload=' . json_encode($body));

$order = order_load($invoiceId);
if (!$order) { wlog('ORDER NOT FOUND for invoiceId=' . $invoiceId); }
if ($order) {
  wlog('ORDER found email=' . (!empty($order['email']) ? $order['email'] : 'EMAIL EMPTY') . ' qty=' . ($order['quantity'] ?? '?') . ' issued=' . (!empty($order['issued']) ? 'yes' : 'no'));
  $order['status'] = $status;
  if ($status === 'success' && empty($order['paid'])) {
    $order['paid'] = true;
    wlog('SUCCESS → issuing tickets + email');
    try { issue_tickets_and_email($order); wlog('issue_tickets_and_email done; emailed=' . (!empty($order['emailed']) ? 'yes' : 'NO')); }
    catch (\Throwable $e) { wlog('issue_tickets EXCEPTION: ' . $e->getMessage()); error_log('[webhook] issue_tickets failed: ' . $e->getMessage()); }
  } else {
    wlog('No action: status=' . $status . ' paid=' . (!empty($order['paid']) ? 'yes' : 'no'));
  }
  order_save($invoiceId, $order);
}
http_response_code(200);                         // ack
