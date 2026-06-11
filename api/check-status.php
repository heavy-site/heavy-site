<?php
/* check-status.php?invoiceId=... — server queries Monobank invoice status. */
require __DIR__ . '/_mono.php';
header('Content-Type: application/json; charset=utf-8');

if (!mono_token_ok()) { http_response_code(500); echo json_encode(['error' => 'Payments not configured']); exit; }

$invoiceId = isset($_GET['invoiceId']) ? trim((string)$_GET['invoiceId']) : '';
// Fallback: allow lookup by our reference (resolve to invoiceId from the store).
if ($invoiceId === '' && isset($_GET['ref'])) {
  $ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['ref']);
  foreach (glob(MONO_DATA_DIR . '/*.json') as $f) {
    $o = json_decode(file_get_contents($f), true);
    if ($o && isset($o['reference']) && $o['reference'] === $ref) { $invoiceId = $o['invoiceId']; break; }
  }
}
if ($invoiceId === '') { http_response_code(400); echo json_encode(['error' => 'Missing invoiceId']); exit; }

$resp = mono_curl('GET', MONO_API . '/api/merchant/invoice/status?invoiceId=' . urlencode($invoiceId));
if (!$resp) { http_response_code(502); echo json_encode(['error' => 'Status check failed']); exit; }

$order = order_load($invoiceId);
echo json_encode([
  'status' => isset($resp['status']) ? $resp['status'] : null,
  'amount' => isset($resp['amount']) ? $resp['amount'] : null,
  'ccy'    => isset($resp['ccy'])    ? $resp['ccy']    : null,
  'paid'   => $order ? !empty($order['paid']) : false,
]);
