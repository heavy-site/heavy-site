<?php
/* dj-application.php — receives "Play at our event" DJ applications and
   emails them to our inbox via the shared Resend sender (_mail.php). */
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

// ── Validation ──
$required = ['firstName', 'lastName', 'age', 'soundcloud', 'about'];
foreach ($required as $f) {
  if (!isset($body[$f]) || trim((string)$body[$f]) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing field: ' . $f]);
    exit;
  }
}

$age = (int)$body['age'];
if ($age < 1 || $age > 120) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid age']);
  exit;
}

$soundcloud = trim((string)$body['soundcloud']);
if (!filter_var($soundcloud, FILTER_VALIDATE_URL)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid SoundCloud URL']);
  exit;
}

$firstName = trim((string)$body['firstName']);
$lastName  = trim((string)$body['lastName']);
$genres    = isset($body['genres']) ? trim((string)$body['genres']) : '';
$about     = trim((string)$body['about']);

// ── Store a copy (same pattern as booking.php) ──
$dataDir = dirname(__DIR__) . '/dj_applications';
if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
$entry = [
  'firstName'  => $firstName,
  'lastName'   => $lastName,
  'age'        => $age,
  'genres'     => $genres,
  'soundcloud' => $soundcloud,
  'about'      => $about,
  'ts'         => time(),
  'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
];
$filename = $dataDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
@file_put_contents($filename, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// ── Email the application ──
$name    = trim($firstName . ' ' . $lastName);
$scLink  = '<a href="' . mail_esc($soundcloud) . '" target="_blank" rel="noopener">' . mail_esc($soundcloud) . '</a>';
$rows = mail_rows([
  'Name'       => $name,
  'Age'        => $age,
  'Genres'     => $genres,
  'SoundCloud' => $scLink,
  'About'      => nl2br(mail_esc($about)),
], ['SoundCloud', 'About']);   // these two are pre-built HTML
$subject = 'New DJ Application — ' . $name;
$html    = mail_shell('New DJ application to play at our event', $rows);
list($sent, $code, $resp) = resend_send($subject, $html, '', mail_to_tagged('artistform'));

echo json_encode(['ok' => true, 'emailed' => $sent]);
