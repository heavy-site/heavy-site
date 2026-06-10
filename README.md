# HEAVY / jorasite

Event/rave site for **jorasite.girafi.keenetic.name** — a Node/Express app
(static brutalist front-end + Meta Conversions API proxy + pCloud photo proxy)
served behind Nginx in an unprivileged Ubuntu 24.04 LXC container.

## Architecture

```
Internet
  → Keenetic router (TLS termination + Let's Encrypt, reverse proxy)
    → LXC container 192.168.2.21
      → Nginx :80            (single public entry point)
        → Node app 127.0.0.1:3000   (systemd service, user: jorasite)
```

- External HTTPS terminates on **Keenetic**; inside the container Nginx speaks HTTP.
- The Node backend binds to **127.0.0.1 only** — never reachable directly.
- `X-Forwarded-Proto` / `X-Forwarded-For` are passed through; the app treats the
  original scheme as HTTPS.

## Layout

```
/opt/jorasite/
  app/            # application code (rsynced; runtime user: jorasite)
  deploy/         # provisioning + deploy/update scripts, systemd unit, logrotate, sudoers
  nginx/          # nginx site + http-level config
  .env            # secrets (chmod 600, NOT in git)
  .env.example    # template
  README.md
```

## Users & privileges

- **jorasite** — system user, no login shell, runs the app. **No sudo.**
- **jora** — admin/deploy user (SSH). Has general sudo, plus scoped NOPASSWD
  rules (`/etc/sudoers.d/jorasite`) for `systemctl {start,stop,restart,reload} jorasite`,
  `nginx -t`, and `systemctl reload/restart nginx` so deploys need no password.
- `jora` is in the `jorasite` group so it can rsync into `/opt/jorasite`
  (setgid, group-writable). Docker is **not** used — avoids the docker-group=root risk.

## First-time setup (already done; here for reference)

```bash
# On the server, as a sudoer:
sudo bash /opt/jorasite/deploy/01-provision.sh   # user, dirs, Node 22, Nginx
# (rsync the code up — see Deploy below)
sudo bash /opt/jorasite/deploy/02-configure.sh   # systemd unit, nginx, sudoers, logrotate, start
```

Secrets live in `/opt/jorasite/.env` (chmod 600, owner jorasite). Never commit it.

## Deploy / update the code

From your Mac (project root):

```bash
ssh-add ~/.ssh/jora_ed25519     # optional: cache key passphrase
./deploy/deploy.sh              # rsync + remote npm ci + restart + healthcheck
```

`deploy.sh` pushes the app to `/opt/jorasite/app`, refreshes deploy/nginx files,
then runs `update.sh` on the server.

To update **only** on the server (e.g. after editing on the box):

```bash
ssh jora@192.168.2.21 'bash /opt/jorasite/deploy/update.sh'
```

### Change a background video / media
Media (`background.mp4`, `welcome-bg.mp4`, posters, `logo.png`, artist photos)
live in `app/`. Replace locally and re-run `./deploy/deploy.sh`.

### Change the Nginx config
Edit `deploy/nginx/*.conf`, then on the server:
```bash
sudo bash /opt/jorasite/deploy/02-configure.sh   # re-installs config + reloads
```

## Service control

```bash
sudo systemctl status jorasite
sudo systemctl restart jorasite
journalctl -u jorasite -f          # app logs (journald)
```

Autostart on boot is enabled for both `jorasite` and `nginx`.

## Healthcheck

```bash
curl -fsS http://127.0.0.1:3000/healthz        # backend (local only)
curl -fsS -H 'Host: jorasite.girafi.keenetic.name' http://127.0.0.1/healthz   # via Nginx
```
External: open `https://jorasite.girafi.keenetic.name/healthz`.

## Logs

- Nginx: `/var/log/nginx/jorasite.access.log`, `jorasite.error.log`
  (rotated weekly, 8 kept — `/etc/logrotate.d/jorasite`).
- App: `journalctl -u jorasite` (journald, size-capped).

## Firewall (ufw, default-deny inbound)

- `22/tcp`  ← `192.168.2.0/24` (trusted LAN only)
- `80/tcp`  ← `192.168.2.0/24` (Keenetic / LAN only)
- Everything else (app `:3000`, Postgres `:5432`, etc.) is **not** reachable
  from outside the LAN.

```bash
sudo ufw status verbose
```

## Verify a healthy deploy

```bash
sudo ss -tulpn                 # 3000 should be 127.0.0.1 only; 80 via nginx
curl -fsS http://127.0.0.1:3000/healthz
curl -I  -H 'Host: jorasite.girafi.keenetic.name' http://127.0.0.1/
systemctl is-enabled jorasite nginx
```
