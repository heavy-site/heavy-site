<?php
/* notify.php — protected one-shot announcement to ticket buyers (e.g. an
   event cancellation / postponement). Reads the order store, sends ONE email
   per paid buyer via Resend (from the verified he4vy.com domain), and records
   who was notified so re-runs never double-send.

   DRY RUN (default): shows recipients + the rendered email, sends nothing.
       https://he4vy.com/api/notify.php?key=SECRET
   SEND for real:
       https://he4vy.com/api/notify.php?key=SECRET&send=1

   Gated by the same ORDERS_KEY as the orders page.                         */
require __DIR__ . '/_mail.php';
header('X-Robots-Tag: noindex, nofollow');

if (!defined('ORDERS_KEY')) define('ORDERS_KEY', '_8I_d1d8HrmVVs9u');

// Which campaign this is (used to name the dedup log — change it to re-send
// a *different* announcement later without being blocked by this one's log).
define('NOTIFY_CAMPAIGN', 'heavy-cancel-2026-10');
// Only notify buyers of THIS event — never people who bought a past event.
define('NOTIFY_EVENT', 'heavy');
// Replies land here (buyers reply to request refunds). Override in config.
if (!defined('NOTIFY_REPLYTO')) define('NOTIFY_REPLYTO', 'mad.cvlt666@gmail.com');

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!hash_equals(ORDERS_KEY, $key)) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo 'Forbidden'; exit; }

$doSend  = isset($_GET['send']);
$sentLog = MONO_DATA_DIR . '/notify_' . NOTIFY_CAMPAIGN . '.json';
$already = is_file($sentLog) ? (json_decode(@file_get_contents($sentLog), true) ?: []) : [];

// ── Collect unique PAID buyers (first order per email wins for name/lang). ──
$recips = [];
foreach (glob(MONO_DATA_DIR . '/*.json') ?: [] as $f) {
  if ($f === $sentLog) continue;
  $o = json_decode(@file_get_contents($f), true);
  if (!is_array($o) || empty($o['paid']) || empty($o['email'])) continue;
  if (($o['ticket'] ?? '') !== NOTIFY_EVENT) continue;   // this event only
  $email = strtolower(trim($o['email']));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
  if (!isset($recips[$email])) {
    $recips[$email] = [
      'email' => trim($o['email']),
      'name'  => trim((string)($o['firstName'] ?? '')),
      'lang'  => (isset($o['lang']) && $o['lang'] === 'en') ? 'en' : 'ua',
    ];
  }
}

// ── Message (UA primary / EN) ──
function notify_subject($lang) {
  return $lang === 'en'
    ? 'Heavy — important update about your ticket'
    : 'Heavy — важлива інформація про твій квиток';
}
function notify_html($name, $lang) {
  $hi = $name !== '' ? htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : ($lang === 'en' ? 'there' : 'друже');
  if ($lang === 'en') {
    $p = [
      'Hey ' . $hi . ',',
      'Unfortunately we have to cancel Heavy at n&oslash;x on the planned date. We can&rsquo;t confirm a new date yet, so the event is postponed.',
      'Your ticket stays valid for our event next month — as soon as we lock a date, you&rsquo;ll be the first to know.',
      'If you can&rsquo;t make it or would like a refund, just reply to this email and we&rsquo;ll process it.',
      'Thanks for being with us.',
    ];
  } else {
    $p = [
      'Привіт, ' . $hi . '!',
      'На жаль, ми змушені скасувати Heavy у n&oslash;x на заплановану дату. Поки що ми не можемо підтвердити нову дату, тож подія відкладається.',
      'Твій квиток залишається дійсним на наш івент у наступному місяці — щойно визначимось із датою, одразу напишемо тобі першим.',
      'Якщо не зможеш прийти або захочеш повернути кошти — просто дай відповідь на цей лист, і ми зробимо повернення.',
      'Дякуємо, що ти з нами.',
    ];
  }
  $body = '';
  foreach ($p as $line) $body .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.5">' . $line . '</p>';
  return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#111">'
       . '<h1 style="font-size:26px;text-transform:uppercase;letter-spacing:.04em;margin:0 0 18px">HEAVY</h1>'
       . $body
       . '<p style="margin:18px 0 0;font-size:15px;color:#555">— HEAVY</p></div>';
}

// ── Partition recipients into pending vs already-notified ──
$pending = []; $done = [];
foreach ($recips as $email => $r) {
  if (isset($already[md5($email)])) $done[] = $r; else $pending[] = $r;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

// ── SEND ──
$results = [];
if ($doSend) {
  @set_time_limit(0);
  foreach ($pending as $r) {
    list($ok, $code, $resp) = resend_send(notify_subject($r['lang']), notify_html($r['name'], $r['lang']), NOTIFY_REPLYTO, $r['email']);
    if ($ok) { $already[md5(strtolower($r['email']))] = time(); }
    $results[] = ['email' => $r['email'], 'ok' => $ok, 'code' => $code];
  }
  @file_put_contents($sentLog, json_encode($already, JSON_UNESCAPED_UNICODE));
}

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$previewLang = !empty($pending) ? $pending[0]['lang'] : 'ua';
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex, nofollow" /><title>HEAVY — Notify buyers</title>
<style>
  body{margin:0;background:#0D0D0D;color:#F2F2F2;font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px}
  h1{font-size:1.3rem;margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em}
  .muted{color:#8C8C8C}
  .bar{display:flex;gap:26px;flex-wrap:wrap;margin:16px 0;padding:14px 0;border-top:1px solid rgba(140,140,140,.22);border-bottom:1px solid rgba(140,140,140,.22)}
  .bar .n{font-size:1.6rem;font-weight:700}.bar .l{color:#8C8C8C;font-size:.7rem;text-transform:uppercase;letter-spacing:.1em}
  .btn{display:inline-block;margin:6px 0 18px;padding:12px 20px;background:#F2F2F2;color:#0D0D0D;text-decoration:none;font-weight:700;letter-spacing:.04em}
  .btn.danger{background:#ff5a5a;color:#fff}
  .preview{background:#fff;color:#111;padding:20px;max-width:600px;margin:10px 0 24px}
  table{width:100%;border-collapse:collapse;font-size:.85rem;max-width:720px}
  td,th{text-align:left;padding:7px 10px;border-bottom:1px solid rgba(140,140,140,.22)}
  th{color:#8C8C8C;font-size:.68rem;text-transform:uppercase;letter-spacing:.1em}
  .ok{color:#7CFC9B}.err{color:#ff6b6b}
  .warn{color:#ffd166}
</style></head><body>
  <h1>HEAVY — Notify buyers</h1>
  <div class="muted">Event: <strong><?= esc(NOTIFY_EVENT) ?></strong> · Campaign: <?= esc(NOTIFY_CAMPAIGN) ?> · from <?= esc(defined('MAIL_FROM') ? MAIL_FROM : 'he4vy.com') ?> · reply-to <?= esc(NOTIFY_REPLYTO) ?></div>

  <div class="bar">
    <div><div class="n"><?= count($recips) ?></div><div class="l">Paid buyers</div></div>
    <div><div class="n"><?= count($pending) ?></div><div class="l">To notify</div></div>
    <div><div class="n"><?= count($done) ?></div><div class="l">Already sent</div></div>
  </div>

  <?php if ($doSend): ?>
    <h3>Send results</h3>
    <?php $okN = 0; foreach ($results as $r) if ($r['ok']) $okN++; ?>
    <p class="<?= $okN === count($results) ? 'ok' : 'warn' ?>">Sent <?= $okN ?> / <?= count($results) ?>.</p>
    <table><tr><th>Email</th><th>Result</th></tr>
    <?php foreach ($results as $r): ?>
      <tr><td><?= esc($r['email']) ?></td><td class="<?= $r['ok'] ? 'ok' : 'err' ?>"><?= $r['ok'] ? 'sent' : ('failed (HTTP ' . esc($r['code']) . ')') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$results): ?><tr><td colspan="2" class="muted">Nothing to send — everyone was already notified.</td></tr><?php endif; ?>
    </table>
    <p style="margin-top:18px"><a class="btn" href="?key=<?= esc($key) ?>">◂ Back</a></p>
  <?php else: ?>
    <h3>Email preview <span class="muted">(<?= $previewLang === 'en' ? 'EN' : 'UA' ?> · “<?= esc(notify_subject($previewLang)) ?>”)</span></h3>
    <div class="preview"><?= notify_html($pending[0]['name'] ?? '', $previewLang) ?></div>

    <h3>Recipients to notify (<?= count($pending) ?>)</h3>
    <table><tr><th>Email</th><th>Name</th><th>Lang</th></tr>
    <?php foreach ($pending as $r): ?>
      <tr><td><?= esc($r['email']) ?></td><td><?= esc($r['name'] ?: '—') ?></td><td><?= esc(strtoupper($r['lang'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$pending): ?><tr><td colspan="3" class="muted">No pending recipients (all paid buyers already notified, or none yet).</td></tr><?php endif; ?>
    </table>

    <?php if ($pending): ?>
      <p style="margin-top:18px">This is a <strong>dry run</strong> — nothing has been sent.</p>
      <a class="btn danger" href="?key=<?= esc($key) ?>&send=1" onclick="return confirm('Send this email to <?= count($pending) ?> buyers now?');">Send to <?= count($pending) ?> buyers now</a>
    <?php endif; ?>
  <?php endif; ?>
</body></html>
