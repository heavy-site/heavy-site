#!/usr/bin/env bash
# ── HEAVY / jorasite — deploy from your Mac to the server ──
# Pushes the local project over SSH and runs the remote update.
# Auth is 2FA (SSH key passphrase + Linux password) — you'll be
# prompted. Tip: `ssh-add ~/.ssh/jora_ed25519` first to cache the
# passphrase so you only type the password.
set -euo pipefail

KEY="${KEY:-$HOME/.ssh/jora_ed25519}"
HOSTSPEC="${HOSTSPEC:-jora@192.168.2.21}"
BASE="/opt/jorasite"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"      # project root (parent of deploy/)
SSH_CMD="ssh -i $KEY -o IdentitiesOnly=yes"

echo "→ Syncing application code → $BASE/app"
rsync -az --delete --omit-dir-times --no-perms \
  --exclude '.git' --exclude 'node_modules' --exclude '.env' \
  --exclude '.DS_Store' --exclude '.claude' --exclude 'deploy' \
  --exclude 'nginx' --exclude 'README.md' --exclude '.env.example' \
  -e "$SSH_CMD" "$ROOT/" "$HOSTSPEC:$BASE/app/"

echo "→ Syncing deploy scripts, nginx config, docs"
rsync -az --omit-dir-times --no-perms -e "$SSH_CMD" "$ROOT/deploy/"       "$HOSTSPEC:$BASE/deploy/"
rsync -az --omit-dir-times --no-perms -e "$SSH_CMD" "$ROOT/deploy/nginx/" "$HOSTSPEC:$BASE/nginx/"
rsync -az --omit-dir-times --no-perms -e "$SSH_CMD" "$ROOT/README.md" "$ROOT/.env.example" "$HOSTSPEC:$BASE/"

echo "→ Running remote update (npm ci + restart + healthcheck)"
$SSH_CMD "$HOSTSPEC" "bash $BASE/deploy/update.sh"

echo "✓ Deploy complete → https://jorasite.girafi.keenetic.name"
