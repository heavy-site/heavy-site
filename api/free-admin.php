<?php
/* free-admin.php — admin-only: issue a FREE ticket, exactly like a purchase
   but with no payment. Key-gated (same ORDERS_KEY as the orders page). Reuses
   the normal ticket pipeline (signed ticket + QR + PDF + email) and records a
   zero-amount order so it shows in the orders list.

   Open: https://he4vy.com/api/free-admin.php?key=SECRET                      */
require __DIR__ . '/_tickets.php';   // brings _mono, _event, _pdf
header('X-Robots-Tag: noindex, nofollow');

if (!defined('ORDERS_KEY')) define('ORDERS_KEY', '_8I_d1d8HrmVVs9u');

$key = isset($_GET['key']) ? (string)$_GET['key'] : (isset($_POST['key']) ? (string)$_POST['key'] : '');
if (!hash_equals(ORDERS_KEY, $key)) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo 'Forbidden'; exit; }

// Events offered in the dropdown (only those that resolve).
$eventIds = ['heavy', 'alter-ego', 'insane-rave'];
$events = [];
foreach ($eventIds as $id) { $ev = heavy_event($id); if ($ev) $events[$id] = $ev['name']; }

$done = null; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $eventId = isset($_POST['event']) ? trim((string)$_POST['event']) : '';
  $email   = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
  $first   = isset($_POST['firstName']) ? trim((string)$_POST['firstName']) : '';
  $last    = isset($_POST['lastName']) ? trim((string)$_POST['lastName']) : '';
  $qty     = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
  $lang    = (isset($_POST['lang']) && $_POST['lang'] === 'en') ? 'en' : 'ua';
  if ($qty < 1 || $qty > 10) $qty = 1;

  if (!isset($events[$eventId]))                 $error = 'Unknown event.';
  elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Invalid email.';
  elseif ($first === '' || $last === '')         $error = 'First and last name are required.';
  else {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $reference = bin2hex(random_bytes(16));
    $invoiceId = 'free-' . $reference;
    $order = [
      'invoiceId' => $invoiceId, 'reference' => $reference,
      'ticket'    => $eventId, 'quantity' => $qty, 'amount' => 0,
      'email'     => $email, 'firstName' => $first, 'lastName' => $last,
      'lang'      => $lang, 'status' => 'free', 'paid' => true, 'free' => true,
      'ts'        => time(),
    ];
    try {
      issue_tickets_and_email($order);
      order_save($invoiceId, $order);
      $done = $order;
    } catch (\Throwable $e) {
      error_log('[free-admin] ' . $e->getMessage());
      if (function_exists('wlog')) wlog('FREE-ADMIN EXCEPTION: ' . $e->getMessage());
      $error = 'Could not issue the ticket: ' . $e->getMessage();
    }
  }
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" /><title>HEAVY — Free ticket (admin)</title>
<style>
  :root{--bg:#0D0D0D;--fg:#F2F2F2;--muted:#8C8C8C;--line:rgba(140,140,140,0.22);--ok:#7CFC9B}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;
    min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:28px}
  .card{width:100%;max-width:460px;border:1px solid var(--line);padding:28px}
  h1{font-size:1.3rem;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em}
  .sub{color:var(--muted);font-size:.8rem;margin-bottom:22px}
  label{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin:14px 0 6px}
  input,select{width:100%;padding:12px 14px;background:rgba(255,255,255,.04);border:1px solid var(--line);color:var(--fg);
    font:inherit;outline:none;border-radius:0}
  input:focus,select:focus{border-color:var(--fg)}
  .row{display:flex;gap:12px}.row>div{flex:1}
  button{margin-top:22px;width:100%;padding:15px;background:var(--fg);color:#0D0D0D;border:1px solid var(--fg);
    font:600 .85rem/1 inherit;letter-spacing:.14em;text-transform:uppercase;cursor:pointer}
  .msg{margin:16px 0;padding:14px;border:1px solid var(--line);font-size:.9rem}
  .msg.ok{border-color:rgba(124,252,155,.4);color:var(--ok)}
  .msg.err{border-color:rgba(224,36,94,.5);color:#ff6b6b}
  .muted{color:var(--muted)}
  a{color:var(--fg)}
</style></head><body>
<form class="card" method="POST" action="?key=<?= h($key) ?>">
  <h1>Free ticket</h1>
  <div class="sub">Admin · issues a real ticket (QR + PDF, emailed) at no charge</div>

  <?php if ($done): ?>
    <div class="msg ok">
      ✓ <?= (int)$done['quantity'] ?> ticket<?= $done['quantity'] > 1 ? 's' : '' ?> issued for <strong><?= h($events[$done['ticket']]) ?></strong><br>
      Sent to <strong><?= h($done['email']) ?></strong> — <?= !empty($done['emailed']) ? 'email delivered' : '<span class="muted">email NOT sent (check Resend config)</span>' ?>.
    </div>
  <?php elseif ($error): ?>
    <div class="msg err"><?= h($error) ?></div>
  <?php endif; ?>

  <label>Event</label>
  <select name="event">
    <?php foreach ($events as $id => $name): ?>
      <option value="<?= h($id) ?>"<?= $id === 'heavy' ? ' selected' : '' ?>><?= h($name) ?> (<?= h($id) ?>)</option>
    <?php endforeach; ?>
  </select>

  <div class="row">
    <div><label>First name</label><input name="firstName" required value="<?= h($_POST['firstName'] ?? '') ?>" /></div>
    <div><label>Last name</label><input name="lastName" required value="<?= h($_POST['lastName'] ?? '') ?>" /></div>
  </div>

  <label>Email (ticket is sent here)</label>
  <input name="email" type="email" required value="<?= h($_POST['email'] ?? '') ?>" />

  <div class="row">
    <div><label>Quantity</label><input name="quantity" type="number" min="1" max="10" value="<?= h($_POST['quantity'] ?? '1') ?>" /></div>
    <div><label>Email language</label>
      <select name="lang"><option value="ua">UA</option><option value="en"<?= (($_POST['lang'] ?? '') === 'en') ? ' selected' : '' ?>>EN</option></select>
    </div>
  </div>

  <button type="submit">Issue free ticket</button>
  <p class="sub" style="margin-top:16px">Issued free tickets appear in <a href="orders.php?key=<?= h($key) ?>">the orders list</a> (0 ₴).</p>
</form>
</body></html>
