<?php
/* booking.php — Receives booking form submissions.
   Stores the request and can be extended to send email notifications. */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'POST only']);
  exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid JSON']);
  exit;
}

$required = ['firstName', 'lastName', 'email', 'eventName', 'eventDate'];
foreach ($required as $f) {
  if (empty($body[$f])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing field: ' . $f]);
    exit;
  }
}

if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid email']);
  exit;
}

$dataDir = dirname(__DIR__) . '/booking_requests';
if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);

$entry = [
  'artist'    => isset($body['artist'])    ? $body['artist']    : '',
  'firstName' => $body['firstName'],
  'lastName'  => $body['lastName'],
  'phone'     => isset($body['phone'])     ? $body['phone']     : '',
  'email'     => $body['email'],
  'eventName' => $body['eventName'],
  'eventDate' => $body['eventDate'],
  'budget'    => isset($body['budget'])    ? $body['budget']    : '',
  'currency'  => isset($body['currency'])  ? $body['currency']  : 'UAH',
  'comment'   => isset($body['comment'])   ? $body['comment']   : '',
  'ts'        => time(),
  'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
];

$filename = $dataDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($filename, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode(['ok' => true]);
