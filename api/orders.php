<?php
/* orders.php — protected, read-only buyer list. Reads the file-based order
   store (MONO_DATA_DIR) and renders a table in the browser. Link-gated by a
   secret key; shows paid orders by default, ?all=1 shows every order.

   Open: https://he4vy.com/api/orders.php?key=SECRET
   Override the key with an ORDERS_KEY constant in monobank_config.php.     */
require __DIR__ . '/_mono.php';
header('X-Robots-Tag: noindex, nofollow');

if (!defined('ORDERS_KEY')) define('ORDERS_KEY', '_8I_d1d8HrmVVs9u');

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!hash_equals(ORDERS_KEY, $key)) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Forbidden';
  exit;
}

$showAll = isset($_GET['all']);

// Load every order file (named by invoiceId) from the store.
$orders = [];
foreach (glob(MONO_DATA_DIR . '/*.json') ?: [] as $f) {
  $o = json_decode(@file_get_contents($f), true);
  if (is_array($o) && (isset($o['email']) || isset($o['invoiceId']))) $orders[] = $o;
}
// Newest first.
usort($orders, function ($a, $b) { return (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0); });

// Optional filter by event (ticket id). Distinct events for the filter UI.
$eventFilter = isset($_GET['event']) ? trim((string)$_GET['event']) : '';
$events = [];
foreach ($orders as $o) { $t = (string)($o['ticket'] ?? ''); if ($t !== '') $events[$t] = true; }
$events = array_keys($events);

$paidCount = 0; $paidTickets = 0; $revenue = 0;
foreach ($orders as $o) {
  if ($eventFilter !== '' && (string)($o['ticket'] ?? '') !== $eventFilter) continue;
  if (!empty($o['paid'])) {
    $paidCount++;
    $paidTickets += (int)($o['quantity'] ?? 1);
    $revenue += (int)($o['amount'] ?? 0);
  }
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" />
<title>HEAVY — Orders</title>
<style>
  :root { --bg:#0D0D0D; --fg:#F2F2F2; --muted:#8C8C8C; --line:rgba(140,140,140,0.22); --ok:#7CFC9B; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--fg); font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif; padding:24px; }
  h1 { font-size:1.4rem; margin:0 0 4px; text-transform:uppercase; letter-spacing:.04em; }
  .sub { color:var(--muted); margin-bottom:18px; font-size:.85rem; }
  .stats { display:flex; gap:28px; flex-wrap:wrap; margin-bottom:22px; padding:16px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
  .stat .n { font-size:1.8rem; font-weight:700; }
  .stat .l { color:var(--muted); font-size:.72rem; text-transform:uppercase; letter-spacing:.12em; }
  .toggle { margin-bottom:14px; font-size:.85rem; }
  .toggle a { color:var(--fg); }
  table { width:100%; border-collapse:collapse; font-size:.85rem; }
  th, td { text-align:left; padding:9px 12px; border-bottom:1px solid var(--line); white-space:nowrap; }
  th { color:var(--muted); font-size:.68rem; text-transform:uppercase; letter-spacing:.1em; font-weight:600; }
  td.email { font-family:ui-monospace,Menlo,Consolas,monospace; }
  tr.unpaid { opacity:.45; }
  .pill { display:inline-block; padding:2px 8px; border:1px solid var(--line); border-radius:99px; font-size:.7rem; letter-spacing:.06em; text-transform:uppercase; }
  .pill.paid { color:var(--ok); border-color:rgba(124,252,155,.4); }
  .muted { color:var(--muted); }
  @media (max-width:640px){ body{padding:14px;} th,td{padding:7px 8px;} }
</style>
</head>
<body>
  <h1>HEAVY — Orders</h1>
  <div class="sub"><?= h($showAll ? 'All orders' : 'Paid orders') ?><?= $eventFilter !== '' ? ' · event: ' . h($eventFilter) : '' ?> · generated <?= h(gmdate('Y-m-d H:i')) ?> UTC</div>

  <?php if (count($events) > 1): ?>
  <div class="toggle" style="margin-bottom:6px">
    Event:
    <a href="?key=<?= h($key) ?><?= $showAll ? '&all=1' : '' ?>"<?= $eventFilter === '' ? ' style="color:#7CFC9B"' : '' ?>>All</a>
    <?php foreach ($events as $ev): ?>
      · <a href="?key=<?= h($key) ?>&event=<?= h(urlencode($ev)) ?><?= $showAll ? '&all=1' : '' ?>"<?= $eventFilter === $ev ? ' style="color:#7CFC9B"' : '' ?>><?= h($ev) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="n"><?= $paidCount ?></div><div class="l">Paid orders</div></div>
    <div class="stat"><div class="n"><?= $paidTickets ?></div><div class="l">Tickets sold</div></div>
    <div class="stat"><div class="n"><?= number_format($revenue / 100, 0, '.', ' ') ?> ₴</div><div class="l">Revenue</div></div>
  </div>

  <div class="toggle">
    <?php $evq = $eventFilter !== '' ? '&event=' . urlencode($eventFilter) : ''; ?>
    <?php if ($showAll): ?>
      <a href="?key=<?= h($key) . $evq ?>">▸ Show paid only</a>
    <?php else: ?>
      <a href="?key=<?= h($key) ?>&all=1<?= $evq ?>">▸ Show all orders (incl. unpaid)</a>
    <?php endif; ?>
  </div>

  <table>
    <thead>
      <tr><th>Date (UTC)</th><th>Name</th><th>Email</th><th>Event</th><th>Type</th><th>Qty</th><th>Amount</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php
    $shown = 0;
    foreach ($orders as $o):
      $paid = !empty($o['paid']);
      if (!$showAll && !$paid) continue;
      if ($eventFilter !== '' && (string)($o['ticket'] ?? '') !== $eventFilter) continue;
      $shown++;
      $name = trim(($o['firstName'] ?? '') . ' ' . ($o['lastName'] ?? ''));
      $amount = (int)($o['amount'] ?? 0);
    ?>
      <tr class="<?= $paid ? 'paid-row' : 'unpaid' ?>">
        <td class="muted"><?= h($o['ts'] ? gmdate('Y-m-d H:i', (int)$o['ts']) : '—') ?></td>
        <td><?= h($name !== '' ? $name : '—') ?></td>
        <td class="email"><?= h($o['email'] ?? '—') ?></td>
        <td><?= h($o['ticket'] ?? '—') ?></td>
        <td><?= h($o['typeName'] ?? '—') ?></td>
        <td><?= h($o['quantity'] ?? 1) ?></td>
        <td><?= $amount ? h(number_format($amount / 100, 0, '.', ' ')) . ' ₴' : '—' ?></td>
        <td><?php if ($paid): ?><span class="pill paid">paid</span><?php else: ?><span class="pill"><?= h($o['status'] ?? 'created') ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($shown === 0): ?>
      <tr><td colspan="8" class="muted" style="padding:20px 12px;">No orders yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
