<?php
/* event.php — public event info for the checkout modal / detail view.
   Mirrors the Node /api/event (EVENTS_INFO). No token needed (public). */
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
header('Content-Type: application/json; charset=utf-8');

$EVENTS = [
  'alter-ego' => [
    'name'        => 'Alter Ego',
    'date'        => 'May 23, 2026',
    'time'        => '18:00 – 22:00',
    'venue'       => 'Gurtok',
    'address'     => 'Нижньоюрківська 31, Київ',
    'description' => '',                 // fill in later; hidden while empty
    'priceUah'    => 300,                // display price (UI total). Test charge is set server-side.
  ],
];

$id = isset($_GET['id']) ? $_GET['id'] : '';
if (!isset($EVENTS[$id])) { http_response_code(404); echo json_encode(['error' => 'Unknown event']); exit; }

$ev = $EVENTS[$id];
$mapUrl = $ev['address'] !== '' ? ('https://www.google.com/maps/search/?api=1&query=' . rawurlencode($ev['address'])) : '';

echo json_encode([
  'id'          => $id,
  'name'        => $ev['name'],
  'date'        => $ev['date'],
  'time'        => $ev['time'],
  'venue'       => $ev['venue'],
  'address'     => $ev['address'],
  'description' => $ev['description'],
  'mapUrl'      => $mapUrl,
  'priceUah'    => $ev['priceUah'],
  'maxQty'      => 10,
]);
