# Deploy BoothPOS to Oracle Cloud (Always Free) + DuckDNS + SSL

This is a personal/demo deployment guide — putting **this specific repo**
online using Oracle Cloud's permanently-free VM tier, a free DuckDNS
subdomain, and free Let's Encrypt SSL via Caddy. It builds on the
production-shaped Docker path already in this repo
(`docker-compose.store.yml`, `docker/store/`, from
`specs/016-docker-store-deployment/`) — nothing here is a new deployment
mechanism, just wiring that existing path up to a real public host.

Config templates referenced below live in `deploy/oracle-cloud/`:
`.env.example`, `Caddyfile`, `backup-cron.sh`.

**Not covered here**: multi-store/multi-tenant hosting. This app is a
single-store install per CLAUDE.md's own framing — one VM = one store.

## 1. Provision the VM

1. Sign up for Oracle Cloud (a credit card is required for identity
   verification, but you won't be charged as long as you stay within the
   Always Free shapes below).
2. **Compute → Instances → Create Instance**:
   - Image: **Ubuntu 24.04** (or 22.04)
   - Shape: **Ampere A1 (ARM)** — the Always Free allowance covers up to
     **4 OCPU / 24GB RAM** on this shape, far roomier than the AMD micro
     alternative.
   - Enable **Assign a public IPv4 address**.
   - Attach/generate an SSH key pair during creation — save the private
     key locally.
3. **Networking → Virtual Cloud Networks → your VCN → Security Lists**:
   add **Ingress Rules** for TCP **80** and **443** from `0.0.0.0/0`.
   Port 22 (SSH) is open by default.
4. Note the instance's **Public IP** — it's static for the life of the
   instance and is what DuckDNS will point at.

## 2. SSH in and install Docker

```bash
ssh -i /path/to/private-key.pem ubuntu@<PUBLIC_IP>

curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
newgrp docker
docker compose version   # confirms the compose plugin is present
```

## 3. Push the repo to a private GitHub repo, then clone it on the VM

CLAUDE.md currently notes this repo has **no git remote configured** —
this step changes that. Do it once, from your own machine:

```bash
# from your local machine, inside the repo
gh repo create boothpos --private --source=. --remote=origin
# (or manually: create an empty private repo on github.com, then
#  git remote add origin git@github.com:<you>/boothpos.git)
git push -u origin main
```

On the VM, clone over SSH using a **deploy key** — a repo-scoped,
read-only key that never needs your personal GitHub credentials and
can't push, which is the right shape for a server that only ever pulls:

```bash
# on the VM
ssh-keygen -t ed25519 -C "boothpos-oracle-vm" -f ~/.ssh/boothpos_deploy_key -N ""
cat ~/.ssh/boothpos_deploy_key.pub
```

Copy that public key into **GitHub → your repo → Settings → Deploy keys
→ Add deploy key** (leave "Allow write access" **unchecked**). Then:

```bash
# on the VM
cat >> ~/.ssh/config <<'EOF'
Host github.com-boothpos
    HostName github.com
    User git
    IdentityFile ~/.ssh/boothpos_deploy_key
    IdentitiesOnly yes
EOF

git clone github.com-boothpos:<you>/boothpos.git ~/boothpos
```

**To deploy updates later**, from this point on it's always:

```bash
cd ~/boothpos
git pull
docker compose -f docker-compose.store.yml up -d --build
```

(`.env` itself is git-ignored — `git pull` never touches it, so your
filled-in production values in step 4 are safe across updates.)

## 4. Configure `.env`

```bash
ssh ubuntu@<PUBLIC_IP>
cd ~/boothpos
cp deploy/oracle-cloud/.env.example .env
nano .env
```

Fill in every `GANTI-INI` placeholder — domain, DB password, and the
license public key from step 6. Leave `APP_KEY` blank; the store image's
entrypoint generates it on first boot and persists it back into this
same `.env` file.

```bash
sudo mkdir -p /mnt/backup   # must match BACKUP_EXTERNAL_PATH in .env
```

## 5. Build and start the stack

```bash
docker compose -f docker-compose.store.yml up -d --build
docker compose -f docker-compose.store.yml logs -f app   # watch migrations succeed, then Ctrl-C
curl -I http://localhost:8000                            # expect HTTP/1.1 200 from the VM itself
```

## 6. Activate the license

The global license gate (feature 018) returns `423` on every route until
activated. `license:dev-activate` **refuses to run** once
`APP_ENV=production`, so for a personal deployment (you're both "vendor"
and "customer" here), generate your own Ed25519 keypair once:

```bash
docker compose -f docker-compose.store.yml exec app php artisan tinker --execute="
\$kp = sodium_crypto_sign_keypair();
echo 'PUBLIC: ' . base64_encode(sodium_crypto_sign_publickey(\$kp)) . PHP_EOL;
echo 'PRIVATE: ' . base64_encode(sodium_crypto_sign_secretkey(\$kp)) . PHP_EOL;
"
```

- Put the **PUBLIC** key into `.env`'s `LICENSE_PUBLIC_KEY`, then:
  `docker compose -f docker-compose.store.yml restart app`
- Keep the **PRIVATE** key only on your own laptop — never in any `.env`
  on the server. Use it once, locally, to generate the actual license key:

  ```bash
  LICENSE_SIGNING_PRIVATE_KEY=<PRIVATE key> php artisan license:generate you@email.com "My Store"
  ```

  (Run this against a local copy of the repo with the `sodium` PHP
  extension — it doesn't need to touch the server.)
- Copy the printed `licenseKey` and activate it: `POST /api/v1/license/activate`
  with `{"license_key": "..."}`, or through the app's own `/activate`
  screen once the domain is live (step 8).

## 7. Free domain via DuckDNS

1. Sign in at duckdns.org (GitHub/Google login), create a subdomain, e.g.
   `boothpos-toko`.
2. Set its IP to the VM's **Public IP** from step 1 → the domain becomes
   `boothpos-toko.duckdns.org`. Since Oracle's Always Free public IP is
   static for the instance's lifetime, **no dynamic-update cron is
   needed** — set it once.
3. Update `.env`: `APP_URL=https://boothpos-toko.duckdns.org`, then
   `docker compose -f docker-compose.store.yml restart app`.

## 8. Reverse proxy + free SSL (Caddy)

```bash
sudo apt install -y caddy   # if the apt repo isn't already set up, follow caddyserver.com's install instructions for your distro
sudo cp deploy/oracle-cloud/Caddyfile /etc/caddy/Caddyfile
sudo nano /etc/caddy/Caddyfile   # replace GANTI-INI.duckdns.org with your real subdomain
sudo systemctl restart caddy
```

Caddy obtains and renews the Let's Encrypt certificate automatically and
is the only thing reachable on 80/443 — port 8000 itself is never opened
in the Oracle security list, so the app is only reachable through HTTPS.

## 9. Backups

`routes/console.php` doesn't schedule `app:backup` automatically (a
documented gap — see `docs/RUNBOOK.md` §7). Install the cron wrapper:

```bash
crontab -e
# add:
0 2 * * * /home/ubuntu/boothpos/deploy/oracle-cloud/backup-cron.sh >> /home/ubuntu/boothpos-backup.log 2>&1
```

This runs `php artisan app:backup` daily and prunes local backup
directories older than 30 days (the copy under `BACKUP_EXTERNAL_PATH` is
left untouched — that's the real off-machine copy).

## 10. Before treating this as "live"

- **Change every seeded account's password** (`owner`/`admin`/`kasir01`/
  `kasir02`/`inventory`, all default to `password123` — CLAUDE.md is
  explicit these are "local only" credentials).
- Confirm `docker compose -f docker-compose.store.yml ps` shows `mysql`
  healthy and `app` up before telling anyone the URL.
- Keep the license private key and `.env`'s `DB_PASSWORD` out of any
  screenshot, commit, or chat log.
