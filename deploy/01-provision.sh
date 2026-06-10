#!/usr/bin/env bash
# ── HEAVY / jorasite — one-time base provisioning (run as root) ──
# Creates the runtime user, directory layout, and installs Node + Nginx.
# Idempotent: safe to re-run.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

DEPLOY_USER="jora"          # existing SSH/admin user that performs deploys
APP_USER="jorasite"         # unprivileged runtime user (no login)
BASE=/opt/jorasite

echo "→ Creating runtime user '$APP_USER' (no login)…"
id "$APP_USER" &>/dev/null || useradd --system --user-group \
    --home-dir "$BASE" --shell /usr/sbin/nologin "$APP_USER"

echo "→ Adding deploy user '$DEPLOY_USER' to group '$APP_USER' (for rsync writes)…"
usermod -aG "$APP_USER" "$DEPLOY_USER"

echo "→ Creating directory layout under $BASE…"
mkdir -p "$BASE"/{app,deploy,nginx}
chown -R "$APP_USER:$APP_USER" "$BASE"
# setgid + group-writable so the deploy user can rsync, files inherit the group
chmod -R 2775 "$BASE"

echo "→ Installing Node.js 22 LTS (NodeSource) if missing…"
if ! command -v node &>/dev/null; then
    apt-get update -y
    apt-get install -y ca-certificates curl gnupg
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
fi
node --version

echo "→ Installing Nginx if missing…"
command -v nginx &>/dev/null || { apt-get update -y && apt-get install -y nginx; }
nginx -v

echo "✓ Base provisioning complete."
