<?php
/* Test Resend integration — shows the full Resend API response for debugging.

     https://he4vy.com/api/test-resend.php?key=SECRET&to=your@email.com

   The key is the ticket signing secret, same gate as reissue.php. It is not
   optional: this endpoint sends mail from the verified he4vy.com sender to
   any address it is handed, so ungated it is an open relay — free spam under
   our domain, our Resend quota, and our sending reputation.               */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/_mono.php';
require __DIR__ . '/_tickets.php';          // ticket_secret()

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!hash_equals(ticket_secret(), $key)) {
  http_response_code(403);
  echo json_encode(['error' => 'Forbidden']);
  exit;
}

$to = isset($_GET['to']) ? trim($_GET['to']) : '';
if ($to === '') { echo json_encode(['error' => 'Add ?to=your@email.com']); exit; }

// Check config
$checks = [
  'RESEND_API_KEY defined' => defined('RESEND_API_KEY') && RESEND_API_KEY !== '' && RESEND_API_KEY !== 'REPLACE_WITH_YOUR_RESEND_API_KEY',
  'RESEND_API_KEY prefix'  => defined('RESEND_API_KEY') ? substr(RESEND_API_KEY, 0, 3) : 'NOT SET',
  'MAIL_FROM'              => defined('MAIL_FROM') ? MAIL_FROM : 'NOT SET (will use default)',
  'curl extension'         => extension_loaded('curl'),
  'openssl extension'      => extension_loaded('openssl'),
];

if (!$checks['RESEND_API_KEY defined']) {
  echo json_encode(['error' => 'RESEND_API_KEY not configured in monobank_config.php', 'checks' => $checks], JSON_PRETTY_PRINT);
  exit;
}

$from = (defined('MAIL_FROM') && MAIL_FROM !== '') ? MAIL_FROM : 'HEAVY <onboarding@resend.dev>';

$payload = [
  'from'    => $from,
  'to'      => [$to],
  'subject' => '[TEST] HEAVY — Resend works!',
  'html'    => '<h2>Resend is working</h2><p>If you see this email, the integration is OK.</p><p>From: ' . htmlspecialchars($from) . '</p>',
];

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => [
    'Authorization: Bearer ' . RESEND_API_KEY,
    'Content-Type: application/json',
  ],
  CURLOPT_POSTFIELDS     => json_encode($payload),
  CURLOPT_TIMEOUT        => 25,
  CURLOPT_CONNECTTIMEOUT => 10,
]);

$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
$curlNo   = curl_errno($ch);
curl_close($ch);

echo json_encode([
  'checks'       => $checks,
  'request'      => ['from' => $from, 'to' => $to],
  'http_code'    => $httpCode,
  'curl_error'   => $curlErr ?: null,
  'curl_errno'   => $curlNo,
  'resend_response' => json_decode($resp, true) ?: $resp,
  'success'      => $httpCode >= 200 && $httpCode < 300,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
