<?php
/* Canonical event info — single source for event.php (modal) AND the
   ticket email (api/_tickets.php). Keep in sync with the site. */
function heavy_event($id) {
  $E = [
    'alter-ego' => [
      'name'        => 'Alter Ego — Part 2',
      'poster'      => '/event-poster.png',
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
    'insane-rave' => [
      'name'        => 'Insane Rave',
      'poster'      => '/insane-poster.png',
      'date'        => 'August 29–30, 2026',
      'time'        => '18:00 – 22:00',
      'venue'       => 'nøx',
      'address'     => 'Нижньоюрківська 31, Київ',
      'geo'         => '50.466564192974495,30.499941806080255', // map pin; address shown as-is
      'description' => 'A new HEAVY series pushing the heaviest end of the spectrum. Two nights, raw sound, no compromise.',
      'descriptionUa' => 'Нова серія від HEAVY з найважчим звучанням. Дві ночі, сирий звук, без компромісів.',
      'priceUah'    => 300,          // "from" price — the cheapest type below
      // Ticket types. Present only on multi-day events; single-day events
      // keep working through the synthetic type built in heavy_event_types().
      'types'       => [
        ['id' => '1day', 'name' => '1 day',  'nameUa' => '1 день',
         'note' => 'Any one night', 'noteUa' => 'Будь-яка одна ніч',
         'priceUah' => 300, 'days' => 1],
        ['id' => '2day', 'name' => '2 days', 'nameUa' => '2 дні',
         'note' => 'Both nights', 'noteUa' => 'Обидві ночі',
         'priceUah' => 500, 'days' => 2],
      ],
      'lineup'      => [],
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
  if (empty($ev['types'])) $ev['types'] = heavy_event_types($ev);
  return $ev;
}

/* Ticket types for an event. An event without an explicit 'types' list sells a
   single one-day ticket at its own price — so older events keep working. */
function heavy_event_types($ev) {
  if (!empty($ev['types']) && is_array($ev['types'])) return $ev['types'];
  return [[
    'id' => 'standard', 'name' => 'Standard', 'nameUa' => 'Стандарт',
    'note' => '', 'noteUa' => '',
    'priceUah' => isset($ev['priceUah']) ? (int)$ev['priceUah'] : 0, 'days' => 1,
  ]];
}

/* Resolve a client-sent type id against the event. Unknown / missing ids fall
   back to the first (cheapest) type, never to a client-chosen price. */
function heavy_event_type($ev, $typeId) {
  $types = heavy_event_types($ev);
  foreach ($types as $t) { if ($t['id'] === $typeId) return $t; }
  return $types[0];
}
