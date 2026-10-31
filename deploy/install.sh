#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────────────────────
# TapCard — instalasi server sekali jalan (Ubuntu 22.04 / 24.04, VPS kosong, sebagai root).
#
#   1. Salin & isi konfigurasi:   /root/tapcard-install.env   (contoh: deploy/install.env.example)
#   2. Jalankan:                  sudo bash install.sh /root/tapcard-install.env
#
# Yang dipasang: Nginx, PHP 8.3-FPM (+ ekstensi), Composer, Node 22, Supervisor (worker antrean + scheduler),
# Certbot (SSL), firewall UFW, PostgreSQL 16 (bila DB_MODE=local) — lalu rilis pertama aplikasi, data awal,
# dan akun Pemilik Platform. Aman dijalankan ulang: langkah yang sudah selesai dilewati, .env tidak ditimpa.
# ──────────────────────────────────────────────────────────────────────────────────────────────
set -euo pipefail

ENV_FILE=${1:-/root/tapcard-install.env}
APP=/var/www/tapcard
PHP_VERSION=8.3
NODE_MAJOR=22
SRC=/root/tapcard-src

step() { printf '\n\033[1;33m▶ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Jalankan sebagai root: sudo bash $0 $ENV_FILE"
[[ -f "$ENV_FILE" ]] || die "Konfigurasi $ENV_FILE tidak ditemukan. Salin deploy/install.env.example lalu isi."
chmod 600 "$ENV_FILE"
set -a; # shellcheck source=/dev/null
source "$ENV_FILE"; set +a

# ── validasi ──────────────────────────────────────────────────────────────────────────────────
DOMAIN=${DOMAIN#http*://}; DOMAIN=${DOMAIN%%/*}; DOMAIN=${DOMAIN#www.}
BRANCH=${BRANCH:-main}; DB_MODE=${DB_MODE:-external}; SSL_MODE=${SSL_MODE:-cloudflare}
for v in DOMAIN REPO_URL ADMIN_EMAIL MAIL_HOST MAIL_USERNAME MAIL_PASSWORD; do [[ -n "${!v:-}" ]] || die "$v wajib diisi di $ENV_FILE"; done
if [[ $DB_MODE == external ]]; then
  for v in DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD; do [[ -n "${!v:-}" ]] || die "$v wajib diisi (DB_MODE=external)"; done
fi
[[ $SSL_MODE != cloudflare || -n "${CLOUDFLARE_API_TOKEN:-}" ]] || die "CLOUDFLARE_API_TOKEN wajib untuk SSL_MODE=cloudflare"
. /etc/os-release
[[ ${ID:-} == ubuntu && ${VERSION_ID:-} =~ ^(22.04|24.04)$ ]] || warn "Diuji di Ubuntu 22.04/24.04 — sistem ini $PRETTY_NAME"
export DEBIAN_FRONTEND=noninteractive

# ── 1. paket sistem ───────────────────────────────────────────────────────────────────────────
step "Paket sistem"
apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg git unzip ufw supervisor nginx cron logrotate software-properties-common >/dev/null
if ! apt-cache policy "php${PHP_VERSION}-fpm" | grep -q 'Candidate: [0-9]'; then
  add-apt-repository -y ppa:ondrej/php >/dev/null && apt-get update -qq
fi
apt-get install -y -qq "php${PHP_VERSION}"-{fpm,cli,pgsql,intl,gd,mbstring,bcmath,xml,curl,zip,opcache,readline} >/dev/null
ok "PHP $(php -r 'echo PHP_VERSION;')"
if ! node -v 2>/dev/null | grep -q "^v${NODE_MAJOR}\."; then
  curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - >/dev/null
  apt-get install -y -qq nodejs >/dev/null
fi
ok "Node $(node -v)"
if ! command -v composer >/dev/null; then
  EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  [[ "$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" == "$EXPECTED" ]] || die "Checksum installer Composer tidak cocok"
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer && rm -f /tmp/composer-setup.php
fi
ok "$(composer --version 2>/dev/null | head -1)"
apt-get install -y -qq certbot >/dev/null
[[ $SSL_MODE == cloudflare ]] && apt-get install -y -qq python3-certbot-dns-cloudflare >/dev/null
ok "Nginx, Supervisor, Certbot"

# ── 2. database ───────────────────────────────────────────────────────────────────────────────
if [[ $DB_MODE == local ]]; then
  step "PostgreSQL 16 lokal"
  if ! command -v psql >/dev/null || ! psql --version | grep -q ' 16\.'; then
    install -d /usr/share/postgresql-common/pgdg
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list
    apt-get update -qq && apt-get install -y -qq postgresql-16 >/dev/null
  fi
  DB_HOST=127.0.0.1; DB_PORT=5432; DB_DATABASE=${DB_DATABASE:-tapcard}; DB_USERNAME=${DB_USERNAME:-tapcard}; DB_SSLMODE=disable; DB_EMULATE_PREPARES=false
  if [[ ! -f "$APP/shared/.env" ]]; then
    DB_PASSWORD=${DB_PASSWORD:-$(openssl rand -hex 20)}
    sudo -u postgres psql -qtAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USERNAME'" | grep -q 1 \
      || sudo -u postgres psql -qc "CREATE ROLE \"$DB_USERNAME\" LOGIN PASSWORD '$DB_PASSWORD'"
    sudo -u postgres psql -qtAc "SELECT 1 FROM pg_database WHERE datname='$DB_DATABASE'" | grep -q 1 \
      || sudo -u postgres createdb -O "$DB_USERNAME" "$DB_DATABASE"
  fi
  ok "Database $DB_DATABASE"
fi

# ── 3. akses repo (deploy key untuk repo privat) ──────────────────────────────────────────────
step "Akses repo $REPO_URL"
KEY=/root/.ssh/tapcard_deploy
install -d -m 700 /root/.ssh
if [[ $REPO_URL == git@* || $REPO_URL == ssh://* ]]; then
  [[ -f $KEY ]] || ssh-keygen -q -t ed25519 -N '' -C "tapcard-deploy@$(hostname)" -f "$KEY"
  export GIT_SSH_COMMAND="ssh -i $KEY -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
  until git ls-remote --heads "$REPO_URL" >/dev/null 2>&1; do
    echo
    echo "  Tambahkan deploy key ini di GitHub → repo → Settings → Deploy keys → Add deploy key (read-only):"
    echo
    sed 's/^/    /' "$KEY.pub"
    echo
    read -r -p "  Tekan Enter setelah ditambahkan… " _ </dev/tty
  done
fi
rm -rf "$SRC" && git clone --quiet --depth 1 --branch "$BRANCH" "$REPO_URL" "$SRC"
ok "Repo terbaca (branch $BRANCH)"

# ── 4. folder aplikasi & .env ─────────────────────────────────────────────────────────────────
step "Folder aplikasi & konfigurasi"
install -d -o www-data -g www-data "$APP" "$APP/releases" "$APP/shared" "$APP/.cache"
for d in app/public app/private framework/cache/data framework/sessions framework/views framework/testing logs; do
  install -d -o www-data -g www-data "$APP/shared/storage/$d"
done
install -d -o www-data -g www-data /var/log/tapcard
install -d /var/log/php-fpm /var/www/letsencrypt

ENV="$APP/shared/.env"
OWNER_PASSWORD=""
set_env() { # set_env KEY VALUE → ganti baris yang ada atau tambahkan
  local k=$1 v=$2
  [[ $v =~ [[:space:]\#\"] ]] && v="\"${v//\"/\\\"}\""
  if grep -q "^$k=" "$ENV"; then
    KV="$k=$v" perl -pi -e 'BEGIN { $kv = $ENV{KV}; ($k) = $kv =~ /^([^=]+)=/ } $_ = "$kv\n" if /^\Q$k\E=/' "$ENV"
  else
    printf '%s=%s\n' "$k" "$v" >> "$ENV"
  fi
}
if [[ -f $ENV ]]; then
  ok ".env sudah ada — tidak diubah ($ENV)"
else
  cp "$SRC/.env.production.example" "$ENV"
  OWNER_PASSWORD=$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 16)
  set_env APP_KEY "base64:$(openssl rand -base64 32)"
  set_env APP_URL "https://app.$DOMAIN"
  set_env TAPCARD_BASE_DOMAIN "$DOMAIN"
  set_env TAPCARD_RESOLVER_URL "https://tap.$DOMAIN"
  set_env TAPCARD_UID_PEPPER "$(openssl rand -hex 32)"
  set_env TAPCARD_IP_PEPPER "$(openssl rand -hex 32)"
  set_env ALTCHA_HMAC_KEY "$(openssl rand -hex 32)"
  set_env SEED_PLATFORM_OWNER_EMAIL "$ADMIN_EMAIL"
  set_env SEED_PLATFORM_OWNER_PASSWORD "$OWNER_PASSWORD"   # dihapus lagi setelah akun dibuat
  for k in DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_SSLMODE DB_EMULATE_PREPARES \
           MAIL_HOST MAIL_PORT MAIL_SCHEME MAIL_USERNAME MAIL_PASSWORD \
           PAYMENT_GATEWAY_NAME PAYMENT_GATEWAY_CODE PAYMENT_API_URL PAYMENT_API_KEY PAYMENT_METHOD \
           PAYMENT_WEBHOOK_VERIFY PAYMENT_WEBHOOK_SECRET GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET; do
    # isian kosong di install.env tidak menimpa nilai bawaan template (mis. nama metode, path API)
    if [[ -n ${!k:-} ]]; then set_env "$k" "${!k}"; fi
  done
  set_env MAIL_FROM_ADDRESS "${MAIL_FROM_ADDRESS:-noreply@$DOMAIN}"
  set_env PAYMENT_DRIVER link
  set_env GOOGLE_REDIRECT_URI "https://app.$DOMAIN/auth/google/callback"
  ok ".env dibuat (kunci aplikasi & pepper acak)"
fi
chown root:www-data "$ENV" && chmod 640 "$ENV"
cat > "$APP/shared/deploy.conf" <<EOF
REPO_URL=$REPO_URL
BRANCH=$BRANCH
GIT_SSH_KEY=$KEY
PHP_VERSION=$PHP_VERSION
EOF

# ── 5. PHP-FPM & OPcache ──────────────────────────────────────────────────────────────────────
step "PHP-FPM"
POOLS=/etc/php/$PHP_VERSION/fpm/pool.d
RAM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
CHILDREN=${FPM_MAX_CHILDREN:-$(( RAM_MB / 2 / 50 ))}; (( CHILDREN < 5 )) && CHILDREN=5; (( CHILDREN > 80 )) && CHILDREN=80
sed "s/^pm.max_children = 20/pm.max_children = $CHILDREN/" "$SRC/deploy/php/fpm-pools.conf" > "$POOLS/tapcard.conf"
[[ -f $POOLS/www.conf ]] && mv "$POOLS/www.conf" "$POOLS/www.conf.disabled"
cp "$SRC/deploy/php/opcache-production.ini" "/etc/php/$PHP_VERSION/fpm/conf.d/99-tapcard-opcache.ini"
"php-fpm$PHP_VERSION" -t >/dev/null 2>&1 || die "Konfigurasi PHP-FPM tidak valid (php-fpm$PHP_VERSION -t)"
systemctl enable --now "php$PHP_VERSION-fpm" >/dev/null && systemctl restart "php$PHP_VERSION-fpm"
ok "Pool tapcard (max_children $CHILDREN untuk RAM ${RAM_MB} MB) + pool SSE"

# ── 6. sertifikat SSL ─────────────────────────────────────────────────────────────────────────
step "Sertifikat SSL ($SSL_MODE)"
LIVE=/etc/letsencrypt/live/$DOMAIN
if [[ -f $LIVE/fullchain.pem ]] && ! grep -q 'TAPCARD SELF-SIGNED' "$LIVE/.tapcard" 2>/dev/null; then
  ok "Sertifikat sudah ada ($LIVE)"
else
  case $SSL_MODE in
    cloudflare)
      install -d -m 700 /root/.secrets
      printf 'dns_cloudflare_api_token = %s\n' "$CLOUDFLARE_API_TOKEN" > /root/.secrets/cloudflare.ini && chmod 600 /root/.secrets/cloudflare.ini
      rm -rf "$LIVE"
      certbot certonly --non-interactive --agree-tos -m "$ADMIN_EMAIL" --cert-name "$DOMAIN" \
        --dns-cloudflare --dns-cloudflare-credentials /root/.secrets/cloudflare.ini --dns-cloudflare-propagation-seconds 30 \
        -d "$DOMAIN" -d "*.$DOMAIN" ;;
    http)
      NAMES=(-d "$DOMAIN"); for s in www app tap panel; do getent ahosts "$s.$DOMAIN" >/dev/null && NAMES+=(-d "$s.$DOMAIN"); done
      cat > /etc/nginx/sites-available/tapcard-acme.conf <<EOF
server { listen 80 default_server; listen [::]:80 default_server; server_name _;
  location ^~ /.well-known/acme-challenge/ { root /var/www/letsencrypt; } location / { return 404; } }
EOF
      rm -f /etc/nginx/sites-enabled/*; ln -sfn /etc/nginx/sites-available/tapcard-acme.conf /etc/nginx/sites-enabled/
      systemctl reload nginx || systemctl restart nginx
      rm -rf "$LIVE"
      certbot certonly --non-interactive --agree-tos -m "$ADMIN_EMAIL" --cert-name "$DOMAIN" --webroot -w /var/www/letsencrypt "${NAMES[@]}"
      rm -f /etc/nginx/sites-enabled/tapcard-acme.conf
      warn "Tanpa wildcard: subdomain workspace perusahaan ({slug}.$DOMAIN) belum tercakup SSL. Pakai SSL_MODE=cloudflare untuk itu." ;;
    none)
      install -d "$LIVE"
      openssl req -x509 -nodes -newkey rsa:2048 -days 30 -subj "/CN=$DOMAIN" -addext "subjectAltName=DNS:$DOMAIN,DNS:*.$DOMAIN" \
        -keyout "$LIVE/privkey.pem" -out "$LIVE/fullchain.pem" 2>/dev/null
      echo 'TAPCARD SELF-SIGNED' > "$LIVE/.tapcard"
      warn "Sertifikat sementara (self-signed) — browser akan memberi peringatan." ;;
  esac
  ok "Sertifikat siap"
fi
install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

# ── 7. Nginx ──────────────────────────────────────────────────────────────────────────────────
step "Nginx"
sed "s/TAPCARD_DOMAIN/$DOMAIN/g" "$SRC/deploy/nginx/tapcard.conf" > /etc/nginx/sites-available/tapcard.conf
rm -f /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/tapcard.conf /etc/nginx/sites-enabled/tapcard.conf
# nginx -t membutuhkan root yang ada; rilis pertama dibuat di langkah berikutnya
[[ -e $APP/current ]] || { install -d "$APP/releases/placeholder/public"; ln -sfn "$APP/releases/placeholder" "$APP/current"; }
nginx -t 2>/dev/null || { nginx -t; die "Konfigurasi Nginx tidak valid"; }
systemctl enable --now nginx >/dev/null && systemctl reload nginx
ok "server_name $DOMAIN *.$DOMAIN"

# ── 8. firewall ───────────────────────────────────────────────────────────────────────────────
step "Firewall"
ufw allow OpenSSH >/dev/null; ufw allow 'Nginx Full' >/dev/null; ufw --force enable >/dev/null
ok "Terbuka: SSH, HTTP, HTTPS"

# ── 9. rilis pertama ──────────────────────────────────────────────────────────────────────────
step "Rilis aplikasi"
bash "$SRC/deploy/deploy.sh" "$BRANCH"
rm -rf "$APP/releases/placeholder"
if [[ -n $OWNER_PASSWORD ]]; then
  # akun sudah dibuat → password jangan tersimpan di .env
  sed -i '/^SEED_PLATFORM_OWNER_PASSWORD=/c\SEED_PLATFORM_OWNER_PASSWORD=' "$ENV"
  runuser -u www-data -- php "$APP/current/artisan" config:cache >/dev/null
fi

# ── 10. worker antrean & scheduler ────────────────────────────────────────────────────────────
step "Worker antrean & scheduler"
cp "$SRC/deploy/supervisor/tapcard.conf" /etc/supervisor/conf.d/tapcard.conf
systemctl enable --now supervisor >/dev/null
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
sleep 2; supervisorctl status | grep tapcard | sed 's/^/  /'

# ── 11. rotasi log & backup ───────────────────────────────────────────────────────────────────
cat > /etc/logrotate.d/tapcard <<'EOF'
/var/log/tapcard/*.log /var/log/php-fpm/*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
EOF
if [[ $DB_MODE == local ]]; then
  install -d -m 700 -o postgres /var/backups/tapcard
  cat > /etc/cron.d/tapcard-backup <<EOF
# Backup database harian 02.30, simpan 14 hari. Salin juga ke luar server (object storage) secara berkala.
30 2 * * * postgres pg_dump -Fc $DB_DATABASE > /var/backups/tapcard/tapcard-\$(date +\%F).dump && find /var/backups/tapcard -name '*.dump' -mtime +14 -delete
EOF
  ok "Backup database harian → /var/backups/tapcard"
fi
rm -rf "$SRC"

# ── 12. pemeriksaan akhir ─────────────────────────────────────────────────────────────────────
step "Pemeriksaan"
CURL=(curl -s -o /dev/null -w '%{http_code}' --max-time 15); [[ $SSL_MODE == none ]] && CURL+=(-k)
for h in "$DOMAIN" "app.$DOMAIN" "panel.$DOMAIN"; do
  code=$("${CURL[@]}" --resolve "$h:443:127.0.0.1" "https://$h/") || code=000
  [[ $code =~ ^(200|302)$ ]] && ok "https://$h → $code" || warn "https://$h → $code (cek DNS / log: /var/www/tapcard/shared/storage/logs)"
done
runuser -u www-data -- php "$APP/current/artisan" tapcard:mail-test 2>&1 | tail -1 | sed 's/^/  /' || true   # SMTP boleh menyusul; jangan hentikan ringkasan akhir

cat <<EOF

────────────────────────────────────────────────────────────────────
 ✓ TapCard terpasang

   Landing page     https://$DOMAIN
   Aplikasi         https://app.$DOMAIN
   Panel pemilik    https://panel.$DOMAIN
   Resolver kartu   https://tap.$DOMAIN
EOF
if [[ -n $OWNER_PASSWORD ]]; then
cat <<EOF

   Login panel      $ADMIN_EMAIL
   Password         $OWNER_PASSWORD   ← simpan sekarang, lalu ganti di panel → Akun & password
EOF
fi
cat <<EOF

   Rilis berikutnya sudo bash $APP/current/deploy/deploy.sh
   Kembali ke rilis sudo bash $APP/current/deploy/rollback.sh
   Konfigurasi      $ENV
────────────────────────────────────────────────────────────────────
EOF
