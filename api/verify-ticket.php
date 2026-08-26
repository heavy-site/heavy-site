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
  list($ok, $reason) = ticket_check_in($t);
  if (!$ok) {
    echo json_encode([
      'ok' => false, 'reason' => $reason, 'usedAt' => $t['usedAt'], 'name' => $t['name'],
      'usesLeft' => ticket_uses_left($t), 'maxUses' => ticket_max_uses($t),
    ]);
    exit;
  }
  echo json_encode([
    'ok' => true, 'name' => $t['name'], 'index' => $t['index'], 'of' => $t['of'],
    'entry' => count(ticket_uses($t)), 'maxUses' => ticket_max_uses($t),
    'usesLeft' => ticket_uses_left($t),
  ]);
  exit;
}

$token = isset($_GET['t']) ? $_GET['t'] : '';
$tid = ticket_verify($token);
$t = $tid ? ticket_load($tid) : null;
if (!$t) { echo json_encode(['valid' => false, 'reason' => 'invalid_or_forged']); exit; }
$ev = heavy_event($t['eventId']) ?: [];
echo json_encode([
  'valid'  => true,
  'used'   => ticket_uses_left($t) <= 0,
  'usedToday' => ticket_used_today($t),
  'usedAt' => $t['usedAt'] ?? null,
  'name'   => $t['name'], 'index' => $t['index'], 'of' => $t['of'],
  'type'   => $t['typeName'] ?? '', 'days' => (int)($t['days'] ?? 1),
  'usesLeft' => ticket_uses_left($t), 'maxUses' => ticket_max_uses($t),
  'event'  => ['name' => $ev['name'] ?? '', 'date' => $ev['date'] ?? '', 'time' => $ev['time'] ?? '', 'venue' => $ev['venue'] ?? ''],
]);
