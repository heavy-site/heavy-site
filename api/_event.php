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
      'geo'         => '50.466564192974495,30.499941806080255', // map pin; address shown as-is
      'description' => '',                        // hidden while empty
      'priceUah'    => 300,                       // UI display price
    ],
  ];
  if (!isset($E[$id])) return null;
  $ev = $E[$id];
  $ev['id'] = $id;
  $geoQuery = !empty($ev['geo']) ? $ev['geo'] : $ev['address'];
  $ev['mapUrl'] = $geoQuery !== ''
    ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($geoQuery)
    : '';
  $ev['maxQty'] = 10;
  return $ev;
}
