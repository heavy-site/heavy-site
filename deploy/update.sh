#!/usr/bin/env bash
# ── HEAVY / jorasite — update & restart (run ON the server as 'jora') ──
# Installs deps, restarts the app, reloads Nginx, verifies health.
# Uses only the scoped NOPASSWD sudo rules in /etc/sudoers.d/jorasite.
set -euo pipefail
BASE=/opt/jorasite

echo "→ Installing production dependencies…"
cd "$BASE/app"
npm ci --omit=dev

echo "→ Restarting app…"
sudo systemctl restart jorasite

echo "→ Validating + reloading Nginx…"
sudo nginx -t && sudo systemctl reload nginx

echo "→ Health check…"
sleep 1
if curl -fsS http://127.0.0.1:3000/healthz >/dev/null; then
    echo "✓ Update complete — app healthy."
else
    echo "✗ Healthcheck FAILED — check: journalctl -u jorasite -n 50"
    exit 1
fi
