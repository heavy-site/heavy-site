<?php
/* Canonical event info — single source for event.php (modal) AND the
   ticket email (api/_tickets.php). Keep in sync with the site. */
function heavy_event($id) {
  $E = [
    'alter-ego' => [
      'name'        => 'Alter Ego',
      'date'        => 'June 28, 2026',
      'time'        => '18:00 – 22:00',
      'venue'       => 'Gurtok',
      'address'     => 'Нижньоюрківська 31, Київ',
      'description' => '',                        // hidden while empty
      'priceUah'    => 1,                         // UI display price
    ],
  ];
  if (!isset($E[$id])) return null;
  $ev = $E[$id];
  $ev['id'] = $id;
  $ev['mapUrl'] = $ev['address'] !== ''
    ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($ev['address'])
    : '';
  $ev['maxQty'] = 10;
  return $ev;
}
