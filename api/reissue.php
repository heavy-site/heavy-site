<?php
/* reissue.php — manually (re)issue tickets for an order that was paid but
   whose tickets never went out (e.g. the qty>=2 timeout bug). Safe because:
   - it confirms with Monobank that the invoice status is "success" before issuing;
   - issue_tickets_and_email() is idempotent (skips if already issued);
   - it is gated by a secret key.

   Usage (browser):
     https://he4vy.com/api/reissue.php?key=SECRET&invoiceId=...
   The key is the ticket signing secret (same one used to sign tickets).      */
require __DIR__ . '/_mono.php';
require __DIR__ . '/_tickets.php';
header('Content-Type: application/json; charset=utf-8');

if (!mono_token_ok()) { http_response_code(500); echo json_encode(['error' => 'Payments not configured']); exit; }

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!hash_equals(ticket_secret(), $key)) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

$invoiceId = isset($_GET['invoiceId']) ? trim((string)$_GET['invoiceId']) : '';
if ($invoiceId === '') { http_response_code(400); echo json_encode(['error' => 'Missing invoiceId']); exit; }

$order = order_load($invoiceId);
if (!$order) { http_response_code(404); echo json_encode(['error' => 'Order not found', 'invoiceId' => $invoiceId]); exit; }

// Confirm the payment really succeeded at Monobank before issuing anything.
$resp = mono_curl('GET', MONO_API . '/api/merchant/invoice/status?invoiceId=' . urlencode($invoiceId));
$status = isset($resp['status']) ? $resp['status'] : null;
if ($status !== 'success') {
  http_response_code(409);
  echo json_encode(['error' => 'Invoice not in success state', 'monobank_status' => $status]);
  exit;
}

$alreadyIssued = !empty($order['issued']);
$order['paid'] = true;
$order['status'] = 'success';

@set_time_limit(0);
@ini_set('memory_limit', '512M');
try {
  issue_tickets_and_email($order);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Issuance failed', 'detail' => $e->getMessage()]);
  exit;
}
order_save($invoiceId, $order);

echo json_encode([
  'ok'             => true,
  'invoiceId'      => $invoiceId,
  'email'          => isset($order['email']) ? $order['email'] : '',
  'quantity'       => isset($order['quantity']) ? $order['quantity'] : 1,
  'alreadyIssued'  => $alreadyIssued,
  'issued'         => !empty($order['issued']),
  'emailed'        => !empty($order['emailed']),
  'ticketIds'      => isset($order['ticketIds']) ? $order['ticketIds'] : [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
