<?php
/* contact.php — main-page contact forms: team applications ("For People")
   and collaboration requests ("For Organizations"). Emails via the shared
   Resend sender (_mail.php), reply-to set to the submitted address. */
require __DIR__ . '/_mail.php';
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

$type = isset($body['type']) && $body['type'] === 'collab' ? 'collab' : 'team';
$required = $type === 'team'
  ? ['firstName', 'lastName', 'email', 'description']
  : ['organization', 'email', 'description'];
foreach ($required as $f) {
  if (!isset($body[$f]) || trim((string)$body[$f]) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing field: ' . $f]);
    exit;
  }
}

$email = trim((string)$body['email']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid email']);
  exit;
}

$val = function ($k) use ($body) { return isset($body[$k]) ? trim((string)$body[$k]) : ''; };

// ── Store a copy (same pattern as booking.php) ──
$dataDir = dirname(__DIR__) . '/contact_requests';
if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
$entry = [
  'type'         => $type,
  'firstName'    => $val('firstName'),
  'lastName'     => $val('lastName'),
  'organization' => $val('organization'),
  'telegram'     => $val('telegram'),
  'instagram'    => $val('instagram'),
  'email'        => $email,
  'description'  => $val('description'),
  'ts'           => time(),
  'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
];
$filename = $dataDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
@file_put_contents($filename, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// ── Email ──
if ($type === 'team') {
  $name    = trim($entry['firstName'] . ' ' . $entry['lastName']);
  $subject = 'Team Application — ' . $name;
  $title   = 'New team application ("For People" form)';
  $rows    = mail_rows([
    'Name'        => $name,
    'Telegram'    => $entry['telegram'],
    'Instagram'   => $entry['instagram'],
    'Email'       => $entry['email'],
    'Description' => nl2br(mail_esc($entry['description'])),
  ], ['Description']);
} else {
  $subject = 'Collaboration Request — ' . $entry['organization'];
  $title   = 'New collaboration request ("For Organizations" form)';
  $rows    = mail_rows([
    'Organization' => $entry['organization'],
    'Email'        => $entry['email'],
    'Instagram'    => $entry['instagram'],
    'Description'  => nl2br(mail_esc($entry['description'])),
  ], ['Description']);
}
$html = mail_shell($title, $rows);
list($sent, $code, $resp) = resend_send($subject, $html, $email, mail_to_tagged($type));

echo json_encode(['ok' => true, 'emailed' => $sent]);
