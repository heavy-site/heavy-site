<?php
/* event.php — public event info for the checkout modal / detail view. */
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/_event.php';

$ev = heavy_event(isset($_GET['id']) ? $_GET['id'] : '');
if (!$ev) { http_response_code(404); echo json_encode(['error' => 'Unknown event']); exit; }

echo json_encode([
  'id'            => $ev['id'],
  'name'          => $ev['name'],
  'date'          => $ev['date'],
  'time'          => $ev['time'],
  'venue'         => $ev['venue'],
  'address'       => $ev['address'],
  'description'   => $ev['description'],
  'descriptionUa' => isset($ev['descriptionUa']) ? $ev['descriptionUa'] : '',
  'mapUrl'        => $ev['mapUrl'],
  'priceUah'      => $ev['priceUah'],
  'maxQty'        => $ev['maxQty'],
  'lineup'        => isset($ev['lineup']) ? $ev['lineup'] : [],
]);
