<?php
/* create-invoice.php — server creates a Monobank invoice (amount decided here). */
require __DIR__ . '/_mono.php';
header('Content-Type: application/json; charset=utf-8');

if (!mono_token_ok()) { http_response_code(500); echo json_encode(['error' => 'Payments not configured']); exit; }

// ── Amount: SERVER-decided. 30000 kop = 300 UAH per ticket. ──
$UNIT_KOP = 30000;

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$qty = isset($body['quantity']) ? (int)$body['quantity'] : 1;
if ($qty < 1 || $qty > 10) $qty = 1;
$amount = $UNIT_KOP * $qty;                         // never trust a client-sent amount

// Buyer info (stored for the webhook's ticket TODO; validated lightly).
$email     = isset($body['email'])     ? trim((string)$body['email'])     : '';
$firstName = isset($body['firstName']) ? trim((string)$body['firstName']) : '';
$lastName  = isset($body['lastName'])  ? trim((string)$body['lastName'])  : '';
$lang      = (isset($body['lang']) && $body['lang'] === 'en') ? 'en' : 'ua';

$reference = bin2hex(random_bytes(16));
$payload = [
  'amount' => $amount,
  'ccy'    => 980,                                   // UAH
  'merchantPaymInfo' => [
    'reference'   => $reference,
    'destination' => 'HEAVY — Alter Ego Part 2 x' . $qty,
  ],
  'redirectUrl' => 'https://he4vy.com/payment-result',
  'webHookUrl'  => 'https://he4vy.com/api/monobank-webhook.php',
  'validity'    => 3600,
  'paymentType' => 'debit',
];

$resp = mono_curl('POST', MONO_API . '/api/merchant/invoice/create', $payload);
if (!$resp || empty($resp['invoiceId']) || empty($resp['pageUrl'])) {
  http_response_code(502);
  echo json_encode(['error' => 'Could not create invoice']);
  exit;
}

// Persist the order BEFORE the buyer pays, so the webhook can mark it paid.
order_save($resp['invoiceId'], [
  'invoiceId' => $resp['invoiceId'],
  'reference' => $reference,
  'quantity'  => $qty,
  'amount'    => $amount,
  'email'     => $email,
  'firstName' => $firstName,
  'lastName'  => $lastName,
  'lang'      => $lang,
  'status'    => 'created',
  'paid'      => false,
  'ts'        => time(),
]);

echo json_encode([
  'invoiceId' => $resp['invoiceId'],
  'pageUrl'   => $resp['pageUrl'],
  'reference' => $reference,
]);
