<?php
/* verify-ticket.php — door scanning.
   GET  ?t=<token>  → verify signature + report status (no side effects).
   POST {t:<token>} → check in (marks used once; blocks reuse). */
require __DIR__ . '/_tickets.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $b = json_decode(file_get_contents('php://input'), true);
  $token = is_array($b) && isset($b['t']) ? $b['t'] : '';
  $tid = ticket_verify($token);
  $t = $tid ? ticket_load($tid) : null;
  if (!$t) { echo json_encode(['ok' => false, 'reason' => 'invalid_or_forged']); exit; }
  if (($t['status'] ?? '') === 'used') {
    echo json_encode(['ok' => false, 'reason' => 'already_used', 'usedAt' => $t['usedAt'], 'name' => $t['name']]);
    exit;
  }
  $t['status'] = 'used'; $t['usedAt'] = time(); ticket_save($t);
  echo json_encode(['ok' => true, 'name' => $t['name'], 'index' => $t['index'], 'of' => $t['of']]);
  exit;
}

$token = isset($_GET['t']) ? $_GET['t'] : '';
$tid = ticket_verify($token);
$t = $tid ? ticket_load($tid) : null;
if (!$t) { echo json_encode(['valid' => false, 'reason' => 'invalid_or_forged']); exit; }
$ev = heavy_event($t['eventId']) ?: [];
echo json_encode([
  'valid'  => true,
  'used'   => ($t['status'] ?? '') === 'used',
  'usedAt' => $t['usedAt'] ?? null,
  'name'   => $t['name'], 'index' => $t['index'], 'of' => $t['of'],
  'event'  => ['name' => $ev['name'] ?? '', 'date' => $ev['date'] ?? '', 'time' => $ev['time'] ?? '', 'venue' => $ev['venue'] ?? ''],
]);
