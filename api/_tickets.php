<?php
/* ───────────────────────────────────────────────────────────────
   Ticket issuance: HMAC-signed tickets + QR (endroid) + Resend email.
   Called from monobank-webhook.php on VERIFIED status === "success".
   Secrets (TICKET_SECRET, RESEND_API_KEY) come from the protected
   config loaded by _mono.php (outside the web root).
─────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/_mono.php';
require_once __DIR__ . '/_event.php';
require_once __DIR__ . '/_pdf.php';

define('PUBLIC_BASE', 'https://he4vy.com');

// Signing key: prefer config TICKET_SECRET; else auto-generate + persist (stable).
function ticket_secret() {
  static $cached = null;
  if ($cached !== null) return $cached;
  if (defined('TICKET_SECRET') && TICKET_SECRET !== '' && TICKET_SECRET !== 'REPLACE_WITH_A_LONG_RANDOM_SECRET') {
    return $cached = TICKET_SECRET;
  }
  $f = MONO_DATA_DIR . '/ticket_secret.key';
  if (is_file($f)) return $cached = trim(file_get_contents($f));
  $cached = bin2hex(random_bytes(32));
  @file_put_contents($f, $cached); @chmod($f, 0600);
  return $cached;
}
function b64url($b) { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }

// token = "<ticketId>.<base64url(HMAC-SHA256(ticketId))>"
function ticket_sign($ticketId) {
  return $ticketId . '.' . b64url(hash_hmac('sha256', $ticketId, ticket_secret(), true));
}
function ticket_verify($token) {
  if (!$token || strpos($token, '.') === false) return null;
  list($tid, $sig) = explode('.', $token, 2);
  $expected = b64url(hash_hmac('sha256', $tid, ticket_secret(), true));
  return hash_equals($expected, (string)$sig) ? $tid : null;
}

// Ticket store (JSON files, outside web root).
function tickets_dir() { $d = MONO_DATA_DIR . '/tickets'; @mkdir($d, 0700, true); return $d; }
function ticket_path($id) { return tickets_dir() . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$id) . '.json'; }
function ticket_save($t) { @file_put_contents(ticket_path($t['id']), json_encode($t)); }
function ticket_load($id) { $p = ticket_path($id); return is_file($p) ? json_decode(file_get_contents($p), true) : null; }

// QR PNG bytes for a verify URL (endroid / GD). Returns binary, or null on failure.
function make_qr_png($text) {
  try {
    require_once __DIR__ . '/vendor/autoload.php';
    $qr = new \Endroid\QrCode\QrCode($text);
    $qr->setErrorCorrectionLevel(\Endroid\QrCode\ErrorCorrectionLevel::Medium);
    $qr->setSize(420);
    $qr->setMargin(16);
    return (new \Endroid\QrCode\Writer\PngWriter())->write($qr)->getString();
  } catch (\Throwable $e) {
    error_log('[tickets] QR generation failed: ' . $e->getMessage());
    return null;
  }
}

// Generate <quantity> signed tickets, store them, email them. Idempotent.
function issue_tickets_and_email(&$order) {
  if (!empty($order['issued'])) return;
  $eventId = isset($order['ticket']) ? $order['ticket'] : 'alter-ego';
  $ev  = heavy_event($eventId) ?: heavy_event('alter-ego');
  $qty = max(1, (int)(isset($order['quantity']) ? $order['quantity'] : 1));

  $made = [];
  for ($i = 1; $i <= $qty; $i++) {
    $tid   = bin2hex(random_bytes(16));
    $t = [
      'id' => $tid, 'token' => ticket_sign($tid),
      'invoiceId' => isset($order['invoiceId']) ? $order['invoiceId'] : '',
      'orderRef'  => isset($order['reference']) ? $order['reference'] : '',
      'eventId'   => $eventId,
      'email'     => isset($order['email']) ? $order['email'] : '',
      'name'      => trim((isset($order['firstName']) ? $order['firstName'] : '') . ' ' . (isset($order['lastName']) ? $order['lastName'] : '')),
      'index' => $i, 'of' => $qty, 'status' => 'valid', 'issuedAt' => time(), 'usedAt' => null,
    ];
    ticket_save($t);
    $made[] = $t;
  }
  $order['issued']    = true;
  $order['ticketIds'] = array_map(function ($t) { return $t['id']; }, $made);
  $order['emailed']   = send_ticket_email($order, $made, $ev);
}

function send_ticket_email($order, $tickets, $ev) {
  if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '' || RESEND_API_KEY === 'REPLACE_WITH_YOUR_RESEND_API_KEY') {
    error_log('[tickets] RESEND_API_KEY not set — tickets stored but NOT emailed: ' . implode(',', array_map(function ($t) { return $t['id']; }, $tickets)));
    return false;
  }
  $to = isset($order['email']) ? $order['email'] : '';
  if ($to === '') { error_log('[tickets] no buyer email on order ' . ($order['reference'] ?? '?')); return false; }

  $from = (defined('MAIL_FROM') && MAIL_FROM !== '') ? MAIL_FROM : 'HEAVY <onboarding@resend.dev>';
  $ua   = (isset($order['lang']) && $order['lang'] === 'en') ? false : true;  // default Ukrainian

  $attachments = [];
  $qrBlocks = '';
  foreach ($tickets as $t) {
    $png = make_qr_png(PUBLIC_BASE . '/verify?t=' . rawurlencode($t['token']));
    $label = ($ua ? 'КВИТОК' : 'TICKET') . ' ' . $t['index'] . ' / ' . $t['of'];

    // Printable PDF ticket (event data + buyer info + QR). Attached per ticket.
    try {
      $pdf = make_ticket_pdf($t, $order, $ev, $png);
      if ($pdf !== '' && $pdf !== null) {
        $attachments[] = ['filename' => 'ticket-' . $t['index'] . '.pdf', 'content' => base64_encode($pdf), 'content_type' => 'application/pdf'];
      }
    } catch (\Throwable $e) {
      error_log('[tickets] PDF generation failed for ' . $t['id'] . ': ' . $e->getMessage());
      if (function_exists('wlog')) wlog('PDF FAIL ' . $t['id'] . ': ' . $e->getMessage());
    }

    if ($png !== null) {
      $cid = 'qr-' . $t['id'];
      $attachments[] = ['filename' => 'ticket-' . $t['index'] . '.png', 'content' => base64_encode($png), 'content_type' => 'image/png', 'content_id' => $cid];
      $qrBlocks .= '<div style="margin:22px 0;text-align:center"><div style="font:600 13px monospace;color:#666;letter-spacing:.1em">' . $label . '</div>'
                 . '<img src="cid:' . $cid . '" width="240" height="240" alt="QR" style="margin-top:8px;border:1px solid #eee"/></div>';
    } else {
      $qrBlocks .= '<p style="text-align:center;margin:18px 0"><a href="' . PUBLIC_BASE . '/verify?t=' . rawurlencode($t['token']) . '">' . $label . '</a></p>';
    }
  }

  $L = $ua
    ? ['subj' => 'Ваші квитки — ' . $ev['name'], 'hi' => 'Дякуємо за покупку! Ваші квитки нижче.', 'date' => 'Дата', 'time' => 'Час', 'venue' => 'Місце', 'maplbl' => 'Мапа', 'map' => 'Відкрити в картах ↗', 'pdf' => 'PDF-квиток додано до листа — його можна роздрукувати.', 'foot' => 'Покажіть кожен QR на вході. Кожен квиток дійсний один раз.']
    : ['subj' => 'Your tickets — ' . $ev['name'], 'hi' => 'Thanks for your purchase! Your tickets are below.', 'date' => 'Date', 'time' => 'Time', 'venue' => 'Venue', 'maplbl' => 'Map', 'map' => 'Open in maps ↗', 'pdf' => 'A PDF ticket is attached — you can print it.', 'foot' => 'Show each QR at the entrance. Each ticket is valid once.'];

  $addr = $ev['address'] !== '' ? ' · ' . htmlspecialchars($ev['address']) : '';
  $rows = '<tr><td style="padding:3px 10px 3px 0;color:#888">' . $L['date'] . '</td><td>' . htmlspecialchars($ev['date']) . '</td></tr>'
        . '<tr><td style="padding:3px 10px 3px 0;color:#888">' . $L['time'] . '</td><td>' . htmlspecialchars($ev['time']) . '</td></tr>'
        . '<tr><td style="padding:3px 10px 3px 0;color:#888">' . $L['venue'] . '</td><td>' . htmlspecialchars($ev['venue']) . $addr . '</td></tr>'
        . ($ev['mapUrl'] ? '<tr><td style="padding:3px 10px 3px 0;color:#888">' . $L['maplbl'] . '</td><td><a href="' . $ev['mapUrl'] . '">' . $L['map'] . '</a></td></tr>' : '');

  $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#111">'
        . '<h1 style="font-size:28px;text-transform:uppercase;letter-spacing:.02em;margin:0 0 6px">' . htmlspecialchars($ev['name']) . '</h1>'
        . '<p style="color:#555;margin:0 0 16px">' . $L['hi'] . '</p>'
        . '<table style="font-size:14px;color:#333;border-collapse:collapse">' . $rows . '</table>'
        . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0"/>' . $qrBlocks
        . '<p style="font-size:12px;color:#999;text-align:center">' . $L['pdf'] . '</p>'
        . '<p style="font-size:12px;color:#999;text-align:center">' . $L['foot'] . '</p></div>';

  $payload = ['from' => $from, 'to' => [$to], 'subject' => $L['subj'], 'html' => $html, 'attachments' => $attachments];
  if (function_exists('wlog')) wlog('RESEND from=' . $from . ' to=' . $to . ' attachments=' . count($attachments));

  $ch = curl_init('https://api.resend.com/emails');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_TIMEOUT => 25,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  if (function_exists('wlog')) wlog('RESEND HTTP ' . $code . ($err ? ' curlerr=' . $err : '') . ' body=' . substr((string)$resp, 0, 600));
  error_log('[tickets] Resend HTTP ' . $code . ': ' . substr((string)$resp, 0, 500));
  return $code >= 200 && $code < 300;
}
