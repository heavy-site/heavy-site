<?php
/* free-ticket.php — hidden, link-only free ticket giveaway.
   A secret code in the URL unlocks a form; each claim issues one real
   ticket (same QR / PDF / check-in system) via issue_tickets_and_email(),
   with NO payment. Bounded by a deadline, a total quota and one per email.

   GET  /api/free-ticket?code=XXX        → { ok, status, event, remaining }
   POST /api/free-ticket  {code,email,firstName,lastName} → claim one ticket */
require __DIR__ . '/_mono.php';
require __DIR__ . '/_event.php';
require __DIR__ . '/_tickets.php';
header('Content-Type: application/json; charset=utf-8');

// ── Campaign config ─────────────────────────────────────────────
define('FREE_CODE',   'jnGa-8zhE75j');      // the secret in the shared link
define('FREE_EVENT',  'heavy');
define('FREE_UNTIL',  1792576800);          // 2026-10-21 13:00 Europe/Kyiv
define('FREE_QUOTA',  50);                  // total free tickets
define('FREE_PER_EMAIL', 1);                // claims allowed per email

$store = MONO_DATA_DIR . '/free_' . FREE_EVENT;
@mkdir($store, 0700, true);

function free_count($store) {
  $n = 0;
  foreach (glob($store . '/*.json') ?: [] as $f) $n++;
  return $n;
}
function free_email_key($email) { return md5(strtolower(trim($email))); }

$ev = heavy_event(FREE_EVENT);
if (!$ev) { http_response_code(500); echo json_encode(['error' => 'Event not configured']); exit; }

$pub = [
  'name'  => $ev['name'],
  'date'  => $ev['date'],
  'time'  => $ev['time'],
  'venue' => $ev['venue'],
];

// ── Status (GET) ────────────────────────────────────────────────
function free_state($codeOk) {
  if (!$codeOk)               return 'invalid';
  if (time() > FREE_UNTIL)    return 'expired';
  return 'open';   // quota is folded in by the caller (it knows the count)
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $code   = isset($_GET['code']) ? (string)$_GET['code'] : '';
  $codeOk = hash_equals(FREE_CODE, $code);
  $used   = free_count($store);
  $remaining = max(0, FREE_QUOTA - $used);
  $state  = free_state($codeOk);
  if ($state === 'open' && $remaining <= 0) $state = 'soldout';
  echo json_encode([
    'ok'        => true,
    'status'    => $state,                 // invalid | expired | soldout | open
    'event'     => $codeOk ? $pub : null,
    'remaining' => $codeOk ? $remaining : null,
    'until'     => FREE_UNTIL,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// ── Claim (POST) ────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }

if (!hash_equals(FREE_CODE, isset($body['code']) ? (string)$body['code'] : '')) {
  http_response_code(403); echo json_encode(['error' => 'invalid', 'status' => 'invalid']); exit;
}
if (time() > FREE_UNTIL) {
  http_response_code(410); echo json_encode(['error' => 'expired', 'status' => 'expired']); exit;
}

$email     = isset($body['email'])     ? trim((string)$body['email'])     : '';
$firstName = isset($body['firstName']) ? trim((string)$body['firstName']) : '';
$lastName  = isset($body['lastName'])  ? trim((string)$body['lastName'])  : '';
$lang      = (isset($body['lang']) && $body['lang'] === 'en') ? 'en' : 'ua';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['error' => 'Invalid email']); exit; }
if ($firstName === '' || $lastName === '')      { http_response_code(400); echo json_encode(['error' => 'Missing name']); exit; }

$key      = free_email_key($email);
$keyPath  = $store . '/' . $key . '.json';

// Reserve a slot under a lock: enforces the quota and one-per-email atomically.
$lock = fopen($store . '/.lock', 'c');
if ($lock) { flock($lock, LOCK_EX); }
if (is_file($keyPath)) {
  if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
  http_response_code(409); echo json_encode(['error' => 'already_claimed', 'status' => 'claimed']); exit;
}
if (free_count($store) >= FREE_QUOTA) {
  if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
  http_response_code(410); echo json_encode(['error' => 'soldout', 'status' => 'soldout']); exit;
}
$reference = bin2hex(random_bytes(16));
@file_put_contents($keyPath, json_encode([
  'reference' => $reference, 'email' => $email,
  'firstName' => $firstName, 'lastName' => $lastName,
  'status' => 'reserved', 'ts' => time(),
], JSON_UNESCAPED_UNICODE));
if ($lock) { flock($lock, LOCK_UN); fclose($lock); }

// Issue + email the ticket (slow: QR + PDF + Resend). Outside the lock.
$order = [
  'reference'          => $reference,
  'ticket'             => FREE_EVENT,
  'quantity'           => 1,
  'email'              => $email,
  'firstName'          => $firstName,
  'lastName'           => $lastName,
  'lang'               => $lang,
  'free'               => true,
  'typeNameOverride'   => 'Guest',
  'typeNameUaOverride' => 'Гість',
  'status'             => 'free',
  'paid'               => true,     // nothing to pay; marks it issuable
];
try {
  issue_tickets_and_email($order);
} catch (\Throwable $e) {
  @unlink($keyPath);  // free the reserved slot so the visitor can retry
  error_log('[free] issue failed: ' . $e->getMessage());
  if (function_exists('wlog')) wlog('FREE issue EXCEPTION: ' . $e->getMessage());
  http_response_code(500); echo json_encode(['error' => 'Could not issue ticket']); exit;
}

@file_put_contents($keyPath, json_encode([
  'reference' => $reference, 'email' => $email,
  'firstName' => $firstName, 'lastName' => $lastName,
  'ticketIds' => isset($order['ticketIds']) ? $order['ticketIds'] : [],
  'emailed'   => !empty($order['emailed']),
  'status' => 'issued', 'ts' => time(),
], JSON_UNESCAPED_UNICODE));

echo json_encode(['ok' => true, 'emailed' => !empty($order['emailed'])]);
