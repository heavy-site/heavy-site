/* ════════════════════════════════════════════════════════════
   Meta Conversions API (CAPI) backend  +  static site server
   ----------------------------------------------------------------
   - Serves index.html (and assets) on /
   - Exposes POST /capi which forwards browser events to Meta's
     Graph API server-side, deduplicated against the browser Pixel
     via a shared event_id.

   The CAPI ACCESS TOKEN lives ONLY here (server-side) — it must
   never be placed in the HTML. Configure it via .env (see
   .env.example).

   Graph API version: v23.0 (current as of 2026).
   Docs: https://developers.facebook.com/docs/marketing-api/conversions-api/
═══════════════════════════════════════════════════════════════ */

const express    = require('express');
const crypto     = require('crypto');
const path       = require('path');
const fs         = require('fs');
const QRCode     = require('qrcode');
const nodemailer = require('nodemailer');
const jwt        = require('jsonwebtoken');
require('dotenv').config();

const app  = express();
const PORT = process.env.PORT || 3456;
// Bind to loopback by default so the backend is never directly exposed —
// Nginx is the only public entry point. Override with HOST=0.0.0.0 for local dev.
const HOST = process.env.HOST || '127.0.0.1';

// ── Meta config (from environment) ───────────────────────────
const PIXEL_ID        = process.env.META_PIXEL_ID;          // a.k.a. dataset ID
const ACCESS_TOKEN    = process.env.META_CAPI_TOKEN;        // CAPI access token
const TEST_EVENT_CODE = process.env.META_TEST_EVENT_CODE;   // optional, for Test Events tab
const GRAPH_VERSION   = 'v23.0';

// Capture the RAW request body alongside JSON parsing — the Monobank webhook
// signature (X-Sign) must be verified against the exact raw bytes.
app.use(express.json({ verify: (req, res, buf) => { req.rawBody = buf; } }));

// Healthcheck — used by systemd/monitoring and the deploy script.
// Returns 200 with minimal JSON; no secrets, no external calls.
app.get('/healthz', (req, res) => {
  res.json({ ok: true, service: 'jorasite', ts: Math.floor(Date.now() / 1000) });
});

// Parse cookies manually (no extra dependency) so we can read _fbp / _fbc.
function parseCookies(req) {
  const header = req.headers.cookie || '';
  const out = {};
  header.split(';').forEach(part => {
    const idx = part.indexOf('=');
    if (idx > -1) {
      out[part.slice(0, idx).trim()] = decodeURIComponent(part.slice(idx + 1).trim());
    }
  });
  return out;
}

// SHA-256 lowercase-trimmed hash, required by Meta for PII (email, phone, etc.)
function hash(value) {
  if (!value) return undefined;
  return crypto
    .createHash('sha256')
    .update(String(value).trim().toLowerCase())
    .digest('hex');
}

// Get the real client IP even behind a proxy.
function clientIp(req) {
  const fwd = req.headers['x-forwarded-for'];
  if (fwd) return fwd.split(',')[0].trim();
  return req.socket.remoteAddress;
}

/* ════════════════════════════════════════════════════════════
   POST /capi
   Body expected from the browser:
   {
     event_name: "InitiateCheckout" | "Purchase" | "ViewContent" | ...,
     event_id:   "<shared id, same one passed to fbq eventID>",
     event_source_url: "https://...",
     custom_data: { currency, value, content_name, ... },
     user_data:   { em }          // optional plaintext email; hashed here
   }
═══════════════════════════════════════════════════════════════ */
app.post('/capi', async (req, res) => {
  if (!PIXEL_ID || !ACCESS_TOKEN) {
    return res.status(500).json({ error: 'Server not configured: set META_PIXEL_ID and META_CAPI_TOKEN.' });
  }

  try {
    const body    = req.body || {};
    const cookies = parseCookies(req);

    // Assemble user_data — matching signals Meta uses to attribute the event.
    const user_data = {
      client_ip_address: clientIp(req),
      client_user_agent: req.headers['user-agent'],
    };
    if (cookies._fbp) user_data.fbp = cookies._fbp;        // Pixel browser ID
    if (cookies._fbc) user_data.fbc = cookies._fbc;        // click ID
    if (body.user_data && body.user_data.em) {
      user_data.em = [hash(body.user_data.em)];            // hashed email (array)
    }
    if (body.user_data && body.user_data.ph) {
      user_data.ph = [hash(body.user_data.ph)];            // hashed phone (array)
    }

    const eventData = {
      event_name:       body.event_name,
      event_time:       Math.floor(Date.now() / 1000),     // Unix seconds
      event_id:         body.event_id,                     // ← shared with the Pixel → dedup
      action_source:    'website',
      event_source_url: body.event_source_url,
      user_data,
      custom_data:      body.custom_data || {},
    };

    const payload = { data: [eventData] };
    if (TEST_EVENT_CODE) payload.test_event_code = TEST_EVENT_CODE;

    const url = `https://graph.facebook.com/${GRAPH_VERSION}/${PIXEL_ID}/events?access_token=${ACCESS_TOKEN}`;

    const fbRes  = await fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    });
    const result = await fbRes.json();

    if (!fbRes.ok) {
      console.error('CAPI error:', result);
      return res.status(502).json({ error: 'Meta CAPI rejected the event', detail: result });
    }

    return res.json({ ok: true, result });
  } catch (err) {
    console.error('CAPI exception:', err);
    return res.status(500).json({ error: 'Internal error', detail: String(err) });
  }
});

/* ════════════════════════════════════════════════════════════
   GET /api/photos?code=<pCloud public link code>
   ----------------------------------------------------------------
   Resolves a pCloud PUBLIC FOLDER link into a list of images.
   Accepts either the raw publink code (e.g. "XZabc123") or a full
   public URL (e.g. https://u.pcloud.link/publink/show?code=XZabc123).

   PERFORMANCE STRATEGY (keep pCloud, make it fast):
     1. /api/photos resolves ONLY thumbnail URLs for the grid — one
        pCloud call per image instead of two (full-size is deferred).
     2. /api/photo-full?code=&fileid= resolves a single full-size URL
        on demand, the moment the lightbox opens.
     3. Both responses are cached in-memory per (code) / (code,fileid)
        so repeat views are instant. pCloud direct links stay valid a
        few hours; we cache for 1 hour to stay safely fresh.
   Tries the US API first, then the EU API.
═══════════════════════════════════════════════════════════════ */
// EU host first (links are e.pcloud.link → eapi), US host as fallback.
const PCLOUD_APIS = ['https://eapi.pcloud.com', 'https://api.pcloud.com'];
const CACHE_TTL_MS = 60 * 60 * 1000; // 1 hour
const photoCache = new Map(); // key -> { at: <ms>, value }

function cacheGet(key) {
  const hit = photoCache.get(key);
  if (hit && (nowMs() - hit.at) < CACHE_TTL_MS) return hit.value;
  if (hit) photoCache.delete(key);
  return null;
}
function cacheSet(key, value) { photoCache.set(key, { at: nowMs(), value }); }
// Date.now via a tiny indirection so it's easy to reason about; safe in server.
function nowMs() { return Date.now(); }

function extractCode(input) {
  if (!input) return '';
  const m = String(input).match(/[?&]code=([^&]+)/);
  return decodeURIComponent(m ? m[1] : input).trim();
}

// Resolve the image list with ONE pCloud call (the folder listing).
// pCloud's getpubthumb endpoint serves the JPEG directly, so we point
// the browser <img> straight at it — no per-image API calls at all.
async function pcloudGetThumbs(apiBase, code) {
  const metaRes = await fetch(`${apiBase}/showpublink?code=${encodeURIComponent(code)}&iconformat=id`)
    .then(r => r.json());
  if (metaRes.result !== 0) {
    const err = new Error(metaRes.error || 'pCloud showpublink failed');
    err.pcloudResult = metaRes.result;
    throw err;
  }

  const md = metaRes.metadata || {};
  const files = md.isfolder ? (md.contents || []) : [md];
  const images = files.filter(f => f && !f.isfolder && f.category === 1); // category 1 = image

  return images.map(f => ({
    name: f.name,
    fileid: f.fileid,
    // Direct thumbnail endpoints — the browser loads them cross-origin with no
    // extra round-trip and no IP-bound token (unlike getpublinkdownload).
    //   thumb  → grid card preview
    //   large  → lightbox display (sharp but far smaller than the original)
    thumb: `${apiBase}/getpubthumb?code=${encodeURIComponent(code)}&fileid=${f.fileid}&size=600x600&crop=0`,
    large: `${apiBase}/getpubthumb?code=${encodeURIComponent(code)}&fileid=${f.fileid}&size=2048x2048&crop=0&type=auto`,
  }));
}

// Grid: thumbnails only (cached per album).
app.get('/api/photos', async (req, res) => {
  const code = extractCode(req.query.code);
  if (!code) return res.status(400).json({ error: 'Missing pCloud "code" parameter.' });

  const cached = cacheGet('list:' + code);
  if (cached) return res.json({ ok: true, photos: cached, cached: true });

  let lastErr;
  for (const apiBase of PCLOUD_APIS) {
    try {
      const photos = await pcloudGetThumbs(apiBase, code);
      cacheSet('list:' + code, photos);
      return res.json({ ok: true, photos });
    } catch (err) { lastErr = err; }
  }
  console.error('pCloud error:', lastErr);
  return res.status(502).json({ error: 'Could not load pCloud album', detail: String(lastErr) });
});

// Shared: resolve the direct file URL for one public-link file.
// NOTE: getpublinkdownload returns a TIME-LIMITED, single-generation token —
// pCloud invalidates a file's previous token when a new one is issued, and the
// link expires. So we must NOT cache it: always resolve fresh at request time,
// or stale clicks get an HTTP 410 (the image blanks). Always https.
async function pcloudFullUrl(code, fileid) {
  let lastErr;
  for (const apiBase of PCLOUD_APIS) {
    try {
      const dl = await fetch(`${apiBase}/getpublinkdownload?code=${encodeURIComponent(code)}&fileid=${encodeURIComponent(fileid)}`)
        .then(r => r.json());
      if (dl.result === 0 && dl.hosts) {
        return `https://${dl.hosts[0]}${dl.path}`;
      }
      lastErr = new Error(dl.error || 'getpublinkdownload failed');
    } catch (err) { lastErr = err; }
  }
  throw lastErr || new Error('Could not resolve image');
}

function safeName(name) {
  return String(name || 'photo').replace(/[^\w.\- ]+/g, '_').slice(0, 120) || 'photo';
}

// Stream the full-resolution file THROUGH our origin.
// CRITICAL: pCloud's getpublinkdownload token is bound to the IP that
// resolved it. If the browser loaded the pCloud URL directly it would get
// HTTP 410 (different IP). By resolving AND fetching here, the same server
// IP does both, so the link is valid — then we relay the bytes to the client.
//   disposition 'inline'     → <img> display (lightbox / covers)
//   disposition 'attachment' → Download button (saves with a filename)
async function streamPcloudFile(req, res, disposition) {
  const code = extractCode(req.query.code);
  const fileid = req.query.fileid;
  if (!code || !fileid) return res.status(400).json({ error: 'Missing code or fileid.' });
  try {
    const full = await pcloudFullUrl(code, fileid);     // resolved on this server…
    const upstream = await fetch(full);                  // …and fetched from the SAME IP → valid
    if (!upstream.ok) return res.status(502).json({ error: 'Upstream fetch failed', status: upstream.status });

    res.setHeader('Content-Type', upstream.headers.get('content-type') || 'image/jpeg');
    const len = upstream.headers.get('content-length');
    if (len) res.setHeader('Content-Length', len);
    res.setHeader('Cache-Control', 'public, max-age=3600');
    if (disposition === 'attachment') {
      const filename = safeName(req.query.name) + (/\.\w{2,4}$/.test(req.query.name || '') ? '' : '.jpg');
      res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);
    } else {
      res.setHeader('Content-Disposition', 'inline');
    }
    return res.send(Buffer.from(await upstream.arrayBuffer()));
  } catch (err) {
    console.error('pCloud stream error:', err);
    return res.status(502).json({ error: 'Could not fetch image', detail: String(err) });
  }
}

// Lightbox / full-res image, displayed inline (same-origin proxy).
app.get('/api/photo',    (req, res) => streamPcloudFile(req, res, 'inline'));
// Download button — same bytes, forced as an attachment with a filename.
app.get('/api/download', (req, res) => streamPcloudFile(req, res, 'attachment'));

/* ════════════════════════════════════════════════════════════
   MONOBANK ACQUIRING (Plata by mono)
   ----------------------------------------------------------------
   Docs:
     create: monobank.ua/api-docs/acquiring/methods/ia/post--api--merchant--invoice--create
     status: monobank.ua/api-docs/acquiring/methods/ia/get--api--merchant--invoice--status
   SECURITY:
     - MONOBANK_TOKEN lives ONLY here (env). Never sent to the browser.
     - All Monobank calls happen here, server-side.
     - The AMOUNT is decided by the server from TICKETS — never trust the client.
═══════════════════════════════════════════════════════════════ */
const MONOBANK_TOKEN = process.env.MONOBANK_TOKEN;
const MONO_API       = 'https://api.monobank.ua';
// Public origin used for redirect + webhook URLs (must be internet-reachable).
const PUBLIC_BASE_URL = (process.env.PUBLIC_BASE_URL || 'https://jorasite.girafi.keenetic.name').replace(/\/$/, '');
// Optional: override the charge while testing (e.g. 10000 = 100 UAH). Leave unset in prod.
const MONO_TEST_AMOUNT = process.env.MONOBANK_TEST_AMOUNT ? parseInt(process.env.MONOBANK_TEST_AMOUNT, 10) : null;

// Server-authoritative catalog. The client only names the ticket id.
const TICKETS = {
  'alter-ego': {
    amount: 30000,            // UNIT price: 300.00 UAH, in kopiykas (server-decided)
    ccy: 980,                 // UAH
    destination: 'Ticket: Alter Ego, 28.06.2026, Gurtok',
  },
};

// ── Ticketing config ─────────────────────────────────────────
// QR tickets are JWTs signed with an EC (ES256) keypair. The PRIVATE key
// signs (server-only); the PUBLIC key verifies at the door. Keys load from
// files (systemd-friendly — multi-line PEM doesn't fit EnvironmentFile) with
// a fallback to inline env (literal "\n" allowed).
function loadKey(inlineVal, fileVal) {
  if (inlineVal && inlineVal.trim()) return inlineVal.includes('\\n') ? inlineVal.replace(/\\n/g, '\n') : inlineVal;
  try { if (fileVal && fs.existsSync(fileVal)) return fs.readFileSync(fileVal, 'utf8'); } catch (_) {}
  return '';
}
const TICKET_PRIVATE_KEY = loadKey(process.env.TICKET_PRIVATE_KEY, process.env.TICKET_PRIVATE_KEY_FILE);
const TICKET_PUBLIC_KEY  = loadKey(process.env.TICKET_PUBLIC_KEY,  process.env.TICKET_PUBLIC_KEY_FILE);
const TICKET_TTL = process.env.TICKET_TTL || '180d';
const MAX_QTY = 10;
const SMTP = {
  host: process.env.SMTP_HOST,
  port: parseInt(process.env.SMTP_PORT || '587', 10),
  user: process.env.SMTP_USER,
  pass: process.env.SMTP_PASS,
  from: process.env.MAIL_FROM || process.env.SMTP_USER,
};

// Public event display info — SINGLE SOURCE OF TRUTH for the checkout modal,
// the QR tickets, and the email. Fill in the <<PLACEHOLDER>> values.
const EVENTS_INFO = {
  'alter-ego': {
    name:        'Alter Ego',
    date:        'June 28, 2026',
    time:        '18:00 – 22:00',
    venue:       'Gurtok',
    address:     'Нижньоюрківська 31, Київ',
    lat:         null,                                     // ← optional: exact coords for a precise pin
    lng:         null,                                     // (map link works from the address meanwhile)
    description: '<<PLACEHOLDER: event description>>',     // ← still needed
  },
};
function eventMapUrl(ev) {
  if (!ev) return '';
  if (ev.lat != null && ev.lng != null) return `https://www.google.com/maps/search/?api=1&query=${ev.lat},${ev.lng}`;
  if (ev.address && ev.address.indexOf('<<') !== 0) return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(ev.address)}`;
  return '';
}
function eventPublic(id) {
  const ev = EVENTS_INFO[id]; if (!ev) return null;
  const t = TICKETS[id];
  return {
    id, name: ev.name, date: ev.date, time: ev.time, venue: ev.venue,
    address: ev.address, description: ev.description, mapUrl: eventMapUrl(ev),
    priceUah: t ? t.amount / 100 : null, maxQty: MAX_QTY,
  };
}

/* ── Persistent JSON store ────────────────────────────────────
   Orders + issued tickets survive restarts (the systemd unit grants
   ReadWritePaths=/opt/jorasite/data). Single Node process → writes are
   serialized; atomic via tmp-file + rename.
   TODO: swap for Postgres (already on the box) if volume grows.        */
const DATA_DIR = process.env.DATA_DIR || '/opt/jorasite/data';
function loadStore(file, fallback) {
  try { return JSON.parse(fs.readFileSync(path.join(DATA_DIR, file), 'utf8')); }
  catch (_) { return fallback; }
}
function saveStore(file, obj) {
  try {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    const tmp = path.join(DATA_DIR, file + '.tmp');
    fs.writeFileSync(tmp, JSON.stringify(obj, null, 2));
    fs.renameSync(tmp, path.join(DATA_DIR, file));
  } catch (err) { console.error('Store save failed', file, err); }
}
const orders  = loadStore('orders.json', {});   // reference -> order
const tickets = loadStore('tickets.json', {});   // ticketId  -> ticket
const persistOrders  = () => saveStore('orders.json', orders);
const persistTickets = () => saveStore('tickets.json', tickets);
const invoiceToRef = {};                          // invoiceId -> reference (rebuilt on boot)
for (const ref in orders) { if (orders[ref].invoiceId) invoiceToRef[orders[ref].invoiceId] = ref; }

function monoReady(res) {
  if (!MONOBANK_TOKEN) { res.status(500).json({ error: 'Payments not configured (MONOBANK_TOKEN missing).' }); return false; }
  return true;
}
function validEmail(e) {
  return typeof e === 'string' && e.length <= 200 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
}

// Public event info (for the checkout modal / detail view).
app.get('/api/event', (req, res) => {
  const ev = eventPublic(String(req.query.id || ''));
  if (!ev) return res.status(404).json({ error: 'Unknown event' });
  res.json(ev);
});

// ── 1) Create invoice → validate buyer + qty, SERVER computes amount,
//       SAVE the order (with buyer info) BEFORE paying, return pageUrl ──
app.post('/api/create-invoice', async (req, res) => {
  if (!monoReady(res)) return;
  const b = req.body || {};
  const ticketId = b.ticket || '';
  const t = TICKETS[ticketId];
  if (!t) return res.status(400).json({ error: 'Unknown ticket' });

  const qty = parseInt(b.quantity, 10);
  if (!Number.isInteger(qty) || qty < 1 || qty > MAX_QTY) {
    return res.status(400).json({ error: `Quantity must be between 1 and ${MAX_QTY}` });
  }
  const email     = String(b.email || '').trim();
  const firstName = String(b.firstName || '').trim();
  const lastName  = String(b.lastName || '').trim();
  const lang      = b.lang === 'en' ? 'en' : 'ua';   // buyer's language for the ticket email (UA default)
  if (!validEmail(email))                       return res.status(400).json({ error: 'A valid email is required' });
  if (!firstName || firstName.length > 100)     return res.status(400).json({ error: 'First name is required' });
  if (!lastName  || lastName.length  > 100)     return res.status(400).json({ error: 'Last name is required' });

  const unit   = MONO_TEST_AMOUNT || t.amount;  // kopiykas per ticket (server-decided)
  const amount = unit * qty;                     // SERVER computes the total — never the client
  const reference = crypto.randomUUID();

  // Persist the order BEFORE payment so buyer info survives the round-trip.
  orders[reference] = {
    reference, invoiceId: null, ticket: ticketId, quantity: qty,
    unit, amount, email, firstName, lastName, lang,
    status: 'pending', paid: false, issued: false, createdAt: Date.now(),
  };
  persistOrders();

  const payload = {
    amount, ccy: t.ccy,
    merchantPaymInfo: { reference, destination: `${t.destination} ×${qty}` },
    redirectUrl: `${PUBLIC_BASE_URL}/payment-result?ref=${reference}`,
    webHookUrl:  `${PUBLIC_BASE_URL}/api/monobank-webhook`,
    validity: 3600, paymentType: 'debit',
  };
  try {
    const r = await fetch(`${MONO_API}/api/merchant/invoice/create`, {
      method: 'POST',
      headers: { 'X-Token': MONOBANK_TOKEN, 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await r.json();
    if (!r.ok || !data.invoiceId) {
      console.error('Monobank create error:', data);
      orders[reference].status = 'create_failed'; persistOrders();
      return res.status(502).json({ error: 'Could not create invoice' });
    }
    orders[reference].invoiceId = data.invoiceId;
    orders[reference].status = 'created';
    persistOrders();
    invoiceToRef[data.invoiceId] = reference;
    return res.json({ pageUrl: data.pageUrl, reference });
  } catch (err) {
    console.error('Monobank create exception:', err);
    orders[reference].status = 'create_failed'; persistOrders();
    return res.status(502).json({ error: 'Could not create invoice' });
  }
});

// ── 2) Check status (the result page calls this; never trusts the redirect) ──
app.get('/api/check-status', async (req, res) => {
  if (!monoReady(res)) return;
  let invoiceId = req.query.invoiceId;
  const order = req.query.ref ? orders[String(req.query.ref)] : null;
  if (!invoiceId && order) invoiceId = order.invoiceId;
  if (!invoiceId) return res.status(400).json({ error: 'Missing invoiceId or ref' });
  try {
    const r = await fetch(`${MONO_API}/api/merchant/invoice/status?invoiceId=${encodeURIComponent(invoiceId)}`, {
      headers: { 'X-Token': MONOBANK_TOKEN },
    });
    const data = await r.json();
    if (!r.ok) return res.status(502).json({ error: 'Status check failed' });
    // `issued` tells the result page the QR tickets were emailed.
    return res.json({ status: data.status, amount: data.amount, ccy: data.ccy, issued: !!(order && order.issued) });
  } catch (err) {
    return res.status(502).json({ error: 'Status check failed' });
  }
});

// ── 3) Webhook — the trustworthy paid trigger (ECDSA-SHA256 signature check) ──
let monoPubKeyPem = null;
async function fetchMonoPubKey() {
  const r = await fetch(`${MONO_API}/api/merchant/pubkey`, { headers: { 'X-Token': MONOBANK_TOKEN } });
  const data = await r.json();
  if (!data.key) throw new Error('pubkey fetch failed');
  monoPubKeyPem = Buffer.from(data.key, 'base64').toString('utf8'); // base64 → PEM
  return monoPubKeyPem;
}
function monoSignValid(rawBody, xSignB64, pem) {
  const v = crypto.createVerify('SHA256');
  v.update(rawBody);
  v.end();
  return v.verify(pem, xSignB64, 'base64'); // EC key → ECDSA; sig is DER, base64
}

app.post('/api/monobank-webhook', async (req, res) => {
  if (!MONOBANK_TOKEN) return res.status(500).end();
  const xSign = req.headers['x-sign'];
  const raw = req.rawBody;
  if (!xSign || !raw) return res.status(400).end();

  try {
    if (!monoPubKeyPem) await fetchMonoPubKey();
    let ok = false;
    try { ok = monoSignValid(raw, xSign, monoPubKeyPem); } catch (_) { ok = false; }
    if (!ok) {                       // key may have rotated → refetch once, retry
      await fetchMonoPubKey();
      try { ok = monoSignValid(raw, xSign, monoPubKeyPem); } catch (_) { ok = false; }
    }
    if (!ok) { console.warn('Monobank webhook: INVALID signature — rejected'); return res.status(403).end(); }

    const body = req.body || {};
    const ref = invoiceToRef[body.invoiceId];
    const order = ref && orders[ref];
    if (order) {
      order.status = body.status; persistOrders();
      if (body.status === 'success' && !order.issued) {
        order.paid = true; persistOrders();
        console.log(`✓ Order ${ref} PAID (invoice ${body.invoiceId}, ${order.amount} kop, ×${order.quantity})`);
        // Verified + paid → issue signed QR tickets and email them.
        // Run async; don't block the webhook ack. issueTickets is idempotent.
        issueTickets(order).catch(err => console.error('issueTickets error:', err));
      }
    } else {
      console.warn('Monobank webhook: valid signature, unknown invoice', body.invoiceId);
    }
    return res.status(200).end(); // ack
  } catch (err) {
    console.error('Monobank webhook error:', err);
    return res.status(500).end(); // 5xx → Monobank will retry
  }
});

/* ════════════════════════════════════════════════════════════
   TICKET ISSUANCE — unique, JWT/ES256-signed, stored, QR + emailed.
   Triggered ONLY from the verified webhook on status === success.
═══════════════════════════════════════════════════════════════ */
// JWT signed with the EC PRIVATE key (ES256). Compact payload (tid+eid) so the
// QR stays small. Door verifies with the PUBLIC key — can't be forged.
function createTicketToken(ticket) {
  return jwt.sign(
    { tid: ticket.id, eid: ticket.eventId },   // keep QR compact
    TICKET_PRIVATE_KEY,
    { algorithm: 'ES256', expiresIn: TICKET_TTL },
  );
}
// Returns the decoded payload ({ tid, eid, iat, exp }) or null if invalid/expired/forged.
function verifyTicketToken(token) {
  if (!TICKET_PUBLIC_KEY || !token) return null;
  try { return jwt.verify(token, TICKET_PUBLIC_KEY, { algorithms: ['ES256'] }); }
  catch (_) { return null; }
}

// QR encodes the verify URL (scannable by any phone camera). Returns a PNG
// Buffer — handy to attach straight into the email.
async function ticketQrBuffer(token) {
  const url = `${PUBLIC_BASE_URL}/verify?t=${encodeURIComponent(token)}`;
  return QRCode.toBuffer(url, { errorCorrectionLevel: 'M', margin: 2, width: 400 });
}

function smtpReady() { return !!(SMTP.host && SMTP.user && SMTP.pass); }

// Email copy in the buyer's language (UA default — Kyiv audience).
const MAIL_I18N = {
  ua: {
    subject: n => `Ваші квитки — ${n}`,
    intro:  (name, qty, ev) => `Дякуємо, ${name}! Ваші квитки (${qty} шт.) на «${ev}» нижче.`,
    date: 'Дата', time: 'Час', venue: 'Місце', map: 'Мапа', openMap: 'Відкрити в картах ↗',
    ticket: (i, of) => `КВИТОК ${i} / ${of}`,
    footer: 'Покажіть кожен QR-код на вході. Кожен квиток дійсний один раз.',
  },
  en: {
    subject: n => `Your tickets — ${n}`,
    intro:  (name, qty, ev) => `Thanks, ${name}! Your ${qty} ticket(s) for ${ev} are below.`,
    date: 'Date', time: 'Time', venue: 'Venue', map: 'Map', openMap: 'Open location in maps ↗',
    ticket: (i, of) => `TICKET ${i} / ${of}`,
    footer: 'Show each QR at the entrance. Each ticket is valid once.',
  },
};
// Hide unfilled <<PLACEHOLDER>> values so they never appear in the email.
function shown(v) { return (v && String(v).indexOf('<<') !== 0) ? String(v) : ''; }

async function sendTicketEmail(order, ticketRecs, ev) {
  if (!smtpReady()) {
    console.warn('SMTP not configured — email skipped. Tickets stored & valid:', ticketRecs.map(t => t.id).join(', '));
    return;
  }
  const T = MAIL_I18N[order.lang === 'en' ? 'en' : 'ua'];  // default Ukrainian
  const transporter = nodemailer.createTransport({
    host: SMTP.host, port: SMTP.port, secure: SMTP.port === 465,
    auth: { user: SMTP.user, pass: SMTP.pass },
  });
  const mapUrl = eventMapUrl(ev);
  const date = shown(ev.date), time = shown(ev.time), venue = shown(ev.venue),
        address = shown(ev.address), description = shown(ev.description);
  const attachments = [];
  let qrBlocks = '';
  for (const t of ticketRecs) {
    const png = await ticketQrBuffer(t.token);
    const cid = `qr-${t.id}@jorasite`;
    attachments.push({ filename: `ticket-${t.index}.png`, content: png, cid });
    qrBlocks += `<div style="margin:22px 0;text-align:center">
      <div style="font:600 13px monospace;color:#666;letter-spacing:.1em">${T.ticket(t.index, t.of)}</div>
      <img src="cid:${cid}" width="240" height="240" alt="${T.ticket(t.index, t.of)}" style="margin-top:8px;border:1px solid #eee"/>
    </div>`;
  }
  const row = (k, v) => v ? `<tr><td style="padding:3px 10px 3px 0;color:#888">${k}</td><td>${v}</td></tr>` : '';
  const html = `<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#111">
    <h1 style="font-size:30px;text-transform:uppercase;letter-spacing:.02em;margin:0 0 4px">${ev.name || ''}</h1>
    <p style="margin:0 0 18px;color:#555">${T.intro(order.firstName, order.quantity, ev.name || '')}</p>
    <table style="font-size:14px;color:#333;border-collapse:collapse">
      ${row(T.date, date)}
      ${row(T.time, time)}
      ${row(T.venue, venue + (address ? ' · ' + address : ''))}
      ${mapUrl ? `<tr><td style="padding:3px 10px 3px 0;color:#888">${T.map}</td><td><a href="${mapUrl}">${T.openMap}</a></td></tr>` : ''}
    </table>
    ${description ? `<p style="font-size:14px;line-height:1.5;color:#444;margin-top:14px">${description}</p>` : ''}
    <hr style="border:none;border-top:1px solid #eee;margin:22px 0"/>
    ${qrBlocks}
    <p style="font-size:12px;color:#999;text-align:center">${T.footer}</p>
  </div>`;
  await transporter.sendMail({
    from: SMTP.from, to: order.email,
    subject: T.subject(ev.name || 'Event'),
    html, attachments,
  });
  console.log(`✉  Emailed ${ticketRecs.length} ticket(s) to ${order.email} [${order.lang || 'ua'}]`);
}

async function issueTickets(order) {
  if (!TICKET_PRIVATE_KEY) { console.error('TICKET_PRIVATE_KEY not set — cannot issue tickets for', order.reference); return; }
  if (order.issued || order.issuing) return;       // idempotent (webhook may retry)
  order.issuing = true; persistOrders();
  try {
    const ev = EVENTS_INFO[order.ticket] || {};
    const made = [];
    for (let i = 0; i < order.quantity; i++) {
      const ticketId = crypto.randomUUID();
      const token = createTicketToken({ id: ticketId, eventId: order.ticket });
      tickets[ticketId] = {
        id: ticketId, token, orderRef: order.reference, eventId: order.ticket,
        email: order.email, name: `${order.firstName} ${order.lastName}`,
        index: i + 1, of: order.quantity,
        status: 'valid', issuedAt: Date.now(), usedAt: null,
      };
      made.push(tickets[ticketId]);
    }
    persistTickets();
    order.issued = true; order.issuing = false; persistOrders();
    console.log(`✓ Issued ${made.length} ticket(s) for order ${order.reference}`);
    await sendTicketEmail(order, made, ev);        // tickets remain valid even if email fails
  } catch (err) {
    order.issuing = false; persistOrders();
    console.error('issueTickets failed for', order.reference, err);
  }
}

// ── Door verification ──
// GET = check signature + status (no side effects).
app.get('/api/verify-ticket', (req, res) => {
  const payload = verifyTicketToken(String(req.query.t || ''));
  const t = payload && tickets[payload.tid];
  if (!t) return res.json({ valid: false, reason: payload ? 'unknown' : 'invalid_or_forged' });
  const ev = eventPublic(t.eventId) || {};
  return res.json({
    valid: true, used: t.status === 'used', usedAt: t.usedAt,
    name: t.name, index: t.index, of: t.of,
    event: { name: ev.name, date: ev.date, time: ev.time, venue: ev.venue },
  });
});
// POST = check in (marks used once; prevents reuse).
app.post('/api/verify-ticket/use', (req, res) => {
  const payload = verifyTicketToken(String((req.body && req.body.t) || ''));
  const t = payload && tickets[payload.tid];
  if (!t) return res.json({ ok: false, reason: payload ? 'unknown' : 'invalid_or_forged' });
  if (t.status === 'used') return res.json({ ok: false, reason: 'already_used', usedAt: t.usedAt, name: t.name });
  t.status = 'used'; t.usedAt = Date.now(); persistTickets();
  return res.json({ ok: true, name: t.name, index: t.index, of: t.of });
});

// Payment result page (Monobank redirects the user here after paying).
app.get('/payment-result', (req, res) => res.sendFile(path.join(__dirname, 'payment-result.html')));
// Door-scan verification page (QR codes point here).
app.get('/verify', (req, res) => res.sendFile(path.join(__dirname, 'verify.html')));

// ── Serve the static site ────────────────────────────────────
app.use(express.static(path.join(__dirname)));

app.listen(PORT, HOST, () => {
  console.log(`▶ Ticket site + CAPI running on http://${HOST}:${PORT}`);
  if (!PIXEL_ID || !ACCESS_TOKEN) {
    console.warn('⚠  META_PIXEL_ID / META_CAPI_TOKEN not set — /capi will return 500 until configured (see .env.example).');
  }
});
