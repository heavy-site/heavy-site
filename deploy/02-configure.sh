#!/usr/bin/env bash
# ── HEAVY / jorasite — install service + nginx config (run as root) ──
# Run AFTER the code has been rsynced to /opt/jorasite. Idempotent.
set -euo pipefail
BASE=/opt/jorasite
APP_USER="jorasite"

echo "→ Installing systemd unit…"
install -m 0644 "$BASE/deploy/jorasite.service" /etc/systemd/system/jorasite.service
systemctl daemon-reload
systemctl enable jorasite >/dev/null

echo "→ Installing scoped sudoers for deploy user…"
install -m 0440 "$BASE/deploy/sudoers-jorasite" /etc/sudoers.d/jorasite
visudo -c >/dev/null

echo "→ Installing logrotate config…"
install -m 0644 "$BASE/deploy/logrotate-jorasite" /etc/logrotate.d/jorasite

echo "→ Ensuring self-signed TLS cert for the backend (Keenetic re-encrypts to :443)…"
# Keenetic terminates public TLS then connects to this backend over HTTPS.
# A self-signed cert is sufficient (the router does not validate it).
if [ ! -s /etc/nginx/ssl/jorasite.crt ]; then
    mkdir -p /etc/nginx/ssl
    openssl req -x509 -nodes -newkey rsa:2048 \
        -keyout /etc/nginx/ssl/jorasite.key \
        -out    /etc/nginx/ssl/jorasite.crt \
        -days 825 -subj "/CN=jorasite.girafi.keenetic.name" \
        -addext "subjectAltName=DNS:jorasite.girafi.keenetic.name"
    chmod 600 /etc/nginx/ssl/jorasite.key
fi

echo "→ Installing Nginx config (single entry point)…"
install -m 0644 "$BASE/nginx/jorasite-http.conf" /etc/nginx/conf.d/jorasite-http.conf
install -m 0644 "$BASE/nginx/jorasite.conf"      /etc/nginx/sites-available/jorasite
ln -sf /etc/nginx/sites-available/jorasite /etc/nginx/sites-enabled/jorasite
rm -f /etc/nginx/sites-enabled/default        # disable default site
nginx -t

echo "→ Installing production dependencies (as $APP_USER)…"
cd "$BASE/app"
sudo -u "$APP_USER" npm ci --omit=dev

echo "→ Starting app + reloading Nginx…"
systemctl restart jorasite
systemctl reload nginx

echo "→ Health check…"
sleep 1
curl -fsS http://127.0.0.1:3000/healthz && echo "  ✓ app healthy"
echo "✓ Configuration complete."
