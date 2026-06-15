<?php
/* Canonical event info — single source for event.php (modal) AND the
   ticket email (api/_tickets.php). Keep in sync with the site. */
function heavy_event($id) {
  $E = [
    'alter-ego' => [
      'name'        => 'Alter Ego — Part 2',
      'date'        => 'June 28, 2026',
      'time'        => '18:00 – 22:00',
      'venue'       => 'Gurtok',
      'address'     => 'Нижньоюрківська 31, Київ',
      'geo'         => '50.466564192974495,30.499941806080255', // map pin; address shown as-is
      'description' => 'The first of the continuation of the Alter Ego event series, in which we continue to immerse ourselves in heavy electronic music.',
      'descriptionUa' => 'Перша подія продовження серії Alter Ego, в якій ми продовжуємо занурюватись у важку електронну музику.',
      'priceUah'    => 300,
      'lineup'      => [
        ['name' => 'Mad Cult',   'time' => '18:00 – 19:00', 'instagram' => 'https://www.instagram.com/mad_cvlt/'],
        ['name' => 'Artem',      'time' => '19:00 – 20:00', 'instagram' => 'https://www.instagram.com/internetkiddd_/'],
        ['name' => 'Kanzyug',    'time' => '20:00 – 21:00', 'instagram' => 'https://www.instagram.com/kanzyug'],
        ['name' => 'Smolyakov',  'time' => '21:00 – 22:00', 'instagram' => 'https://www.instagram.com/smolyakovevgeny/'],
      ],
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
