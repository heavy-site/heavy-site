# HEAVY — he4vy.com

Event/rave site for **he4vy.com** — static front-end + PHP backend
hosted on cPanel (shared hosting).

## Architecture

```
Internet → cPanel (Apache/LiteSpeed, TLS)
             ├── Static files: index.html, payment-result.html, verify.html
             └── PHP backend:  api/*.php
```

## Layout

```
public_html/
  index.html              # main SPA (welcome, tickets, photos, booking)
  payment-result.html     # post-payment status page
  verify.html             # door-check QR verification page
  .htaccess               # rewrites extensionless API paths → PHP
  api/
    _mono.php             # shared Monobank helpers + config loader
    _event.php            # canonical event info
    _tickets.php          # ticket issuance + Resend email
    create-invoice.php    # Monobank invoice creation
    check-status.php      # payment status polling
    monobank-webhook.php  # Monobank payment webhook (ECDSA verified)
    verify-ticket.php     # door QR scan (GET = check, POST = check-in)
    event.php             # event info for checkout modal
    photos.php            # pCloud photo proxy
    download.php          # photo download
    test-resend.php       # Resend integration test
    vendor/               # PHP deps (endroid/qr-code)
    composer.json
  monobank_config.sample.php  # config template (copy outside web root)
```

## Secrets

Secrets live in `monobank_config.php` **outside the web root**:

```
/home3/hevycom/monobank_config.php   (chmod 600)
```

Required constants:
- `MONOBANK_TOKEN` — Monobank acquiring token
- `RESEND_API_KEY` — Resend email API key (`re_...`)
- `MAIL_FROM` — verified sender, e.g. `HEAVY <tickets@he4vy.com>`

Optional:
- `TICKET_SECRET` — HMAC signing key (auto-generated if unset)
- `MONO_DATA_DIR` — order/ticket store path

## Payment flow

1. User fills form → `POST /api/create-invoice` → Monobank invoice
2. User pays on Monobank → webhook `POST /api/monobank-webhook`
3. Webhook verifies ECDSA signature → issues tickets → emails QR codes via Resend
4. User polls `/api/check-status` → sees success
5. Door scan: `/verify?t=<token>` → `GET /api/verify-ticket` → `POST /api/verify-ticket/use`

## DNS (Resend email)

Configured in cPanel Zone Editor for `he4vy.com`:
- `resend._domainkey` TXT (DKIM)
- `send` MX → `feedback-smtp.eu-west-1.amazonses.com`
- `send` TXT SPF `v=spf1 include:amazonses.com ~all`
- `_dmarc` TXT `v=DMARC1; p=none;`

## Deploy

Push to GitHub → pull on cPanel via Git Version Control, or upload via File Manager.

## Testing

Test Resend: `https://he4vy.com/api/test-resend.php?to=your@email.com`
