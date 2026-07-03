<?php
/* ───────────────────────────────────────────────────────────────
   _mail.php — shared Resend email sender used by every form on the
   site (artist booking, DJ applications, …). Reuses the same config
   (RESEND_API_KEY, MAIL_FROM) loaded by _mono.php from the protected
   config file outside the web root, plus wlog() for error logging.
─────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/_mono.php';

// Destination inbox for form submissions. Override with a MAIL_TO
// constant in monobank_config.php; falls back to the site owner.
if (!defined('MAIL_TO')) define('MAIL_TO', 'e.pyvovar@gmail.com');

/* Escape a value for safe inclusion in the HTML email body. */
function mail_esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* Build a clean labeled table from [label => value] pairs.
   Values may contain pre-built HTML when $rawHtmlKeys lists their label. */
function mail_rows(array $fields, array $rawHtmlKeys = []) {
  $rows = '';
  foreach ($fields as $label => $value) {
    if ($value === '' || $value === null) continue;
    $cell = in_array($label, $rawHtmlKeys, true) ? $value : mail_esc($value);
    $rows .= '<tr>'
           . '<td style="padding:6px 16px 6px 0;color:#888;font:600 13px Arial,sans-serif;white-space:nowrap;vertical-align:top">' . mail_esc($label) . '</td>'
           . '<td style="padding:6px 0;color:#111;font:14px Arial,sans-serif;vertical-align:top">' . $cell . '</td>'
           . '</tr>';
  }
  return '<table style="border-collapse:collapse;width:100%;max-width:560px">' . $rows . '</table>';
}

/* Wrap labeled rows in a titled email shell. */
function mail_shell($title, $rowsHtml) {
  return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#111">'
       . '<h1 style="font-size:22px;text-transform:uppercase;letter-spacing:.02em;margin:0 0 4px">HEAVY</h1>'
       . '<p style="color:#555;margin:0 0 18px;font-size:15px">' . mail_esc($title) . '</p>'
       . '<hr style="border:none;border-top:1px solid #eee;margin:0 0 18px"/>'
       . $rowsHtml
       . '</div>';
}

/* Send an email via Resend.
   Returns [ ok(bool), httpCode(int), responseBody(string) ].
   Logs status code + Resend response on every call. */
function resend_send($subject, $html, $replyTo = '', $to = null) {
  $to = $to ?: MAIL_TO;

  if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '' || RESEND_API_KEY === 'REPLACE_WITH_YOUR_RESEND_API_KEY') {
    error_log('[mail] RESEND_API_KEY not configured — email NOT sent: ' . $subject);
    if (function_exists('wlog')) wlog('MAIL ABORT: RESEND_API_KEY not configured (subject="' . $subject . '")');
    return [false, 0, 'RESEND_API_KEY not configured'];
  }

  $from = (defined('MAIL_FROM') && MAIL_FROM !== '') ? MAIL_FROM : 'HEAVY <onboarding@resend.dev>';
  $payload = [
    'from'    => $from,
    'to'      => [$to],
    'subject' => $subject,
    'html'    => $html,
  ];
  if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
    $payload['reply_to'] = $replyTo;
  }

  $ch = curl_init('https://api.resend.com/emails');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 25,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  $ok = $code >= 200 && $code < 300;
  error_log('[mail] Resend HTTP ' . $code . ($err ? ' curlerr=' . $err : '') . ': ' . substr((string)$resp, 0, 500));
  if (function_exists('wlog')) wlog('MAIL to=' . $to . ' subj="' . $subject . '" HTTP ' . $code . ($err ? ' curlerr=' . $err : '') . ' body=' . substr((string)$resp, 0, 300));
  return [$ok, $code, (string)$resp];
}
