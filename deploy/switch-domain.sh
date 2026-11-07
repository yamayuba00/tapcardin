#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────────────────────
# TapCard — pindah ke domain baru tanpa mematikan kartu yang sudah tercetak.
#
#   sudo bash /var/www/tapcard/current/deploy/switch-domain.sh <domain-baru> [--ssl cloudflare|http|none] [--dry-run] [--yes]
#
# Chip NFC & QR berisi alamat tetap https://tap.<domain-lama>/c/… yang tidak bisa diubah setelah dicetak, jadi domain
# lama TIDAK dimatikan: semua alamatnya dialihkan permanen (308, path & query tetap) ke domain baru —
# tap.lama.com/c/AbC → tap.baru.com/c/AbC, {slug}.lama.com → {slug}.baru.com. Domain lama harus tetap Anda miliki
# dan tetap mengarah ke VPS ini selama kartu lama masih beredar.
#
# Urutan: cek DNS → sertifikat domain baru → Nginx (domain baru + pengalihan domain lama, diuji `nginx -t`) → .env →
# cache & restart → pemeriksaan. Bila sertifikat atau Nginx gagal, .env & konfigurasi lama tidak berubah / dipulihkan.
#   --ssl      cloudflare (wildcard, butuh token yang berizin untuk zona domain baru) | http | none. Bawaan: cloudflare
#              bila /root/.secrets/cloudflare.ini ada, selain itu http. Token lain: CLOUDFLARE_API_TOKEN=… sudo -E bash …
#   --dry-run  tampilkan rencana tanpa mengubah apa pun
#   --yes      tanpa konfirmasi
# ──────────────────────────────────────────────────────────────────────────────────────────────
set -euo pipefail

APP=/var/www/tapcard
ENV=$APP/shared/.env
SITES=/etc/nginx/sites-available
STAMP=$(date +%Y%m%d%H%M%S)

step() { printf '\n\033[1;33m▶ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

NEW='' SSL_MODE='' DRY=0 YES=0
while [[ $# -gt 0 ]]; do
  case $1 in
    --ssl) SSL_MODE=${2:-}; shift 2 ;;
    --ssl=*) SSL_MODE=${1#*=}; shift ;;
    --dry-run) DRY=1; shift ;;
    --yes|-y) YES=1; shift ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    -*) die "Opsi tidak dikenal: $1" ;;
    *) NEW=$1; shift ;;
  esac
done

[[ $EUID -eq 0 ]] || die "Jalankan sebagai root: sudo bash $0 <domain-baru>"
[[ -f $ENV ]] || die "TapCard belum terpasang ($ENV tidak ada). Jalankan deploy/install.sh dulu."
[[ -n $NEW ]] || die "Domain baru wajib diisi: sudo bash $0 domainbaru.com"
NEW=${NEW#http*://}; NEW=${NEW%%/*}; NEW=${NEW#www.}; NEW=${NEW,,}
[[ $NEW =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "Domain tidak valid: $NEW"

env_get() { { grep -m1 "^$1=" "$ENV" || true; } | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
OLD=$(env_get TAPCARD_BASE_DOMAIN)
[[ -n $OLD ]] || die "TAPCARD_BASE_DOMAIN kosong di $ENV"
[[ $OLD != "$NEW" ]] || die "Domain sekarang sudah $NEW"
# shellcheck source=/dev/null
source "$APP/shared/deploy.conf"   # PHP_VERSION
PHP_VERSION=${PHP_VERSION:-8.3}
TEMPLATE=$APP/current/deploy/nginx/tapcard.conf
[[ -f $TEMPLATE ]] || die "Template Nginx tidak ditemukan ($TEMPLATE)"
if [[ -z $SSL_MODE ]]; then
  if [[ -f /root/.secrets/cloudflare.ini || -n ${CLOUDFLARE_API_TOKEN:-} ]]; then SSL_MODE=cloudflare; else SSL_MODE=http; fi
fi
[[ $SSL_MODE =~ ^(cloudflare|http|none)$ ]] || die "--ssl harus cloudflare, http, atau none"
ADMIN_EMAIL=$(env_get SEED_PLATFORM_OWNER_EMAIL); ADMIN_EMAIL=${ADMIN_EMAIL:-admin@$NEW}

# domain-domain lama yang sudah dialihkan sebelumnya (pindah kedua kali: semuanya diarahkan langsung ke domain terbaru)
PREVIOUS=()
for f in "$SITES"/tapcard-redirect-*.conf; do
  [[ -e $f ]] || continue
  d=${f##*/tapcard-redirect-}; d=${d%.conf}
  if [[ $d != "$NEW" ]]; then PREVIOUS+=("$d"); fi
done
REDIRECT_FROM=("$OLD")
for d in "${PREVIOUS[@]}"; do if [[ $d != "$OLD" ]]; then REDIRECT_FROM+=("$d"); fi; done

# ── rencana ───────────────────────────────────────────────────────────────────────────────────
step "Rencana pindah domain"
cat <<EOF
  Domain sekarang   $OLD
  Domain baru       $NEW   (SSL: $SSL_MODE)
  Dialihkan (308)   ${REDIRECT_FROM[*]} → $NEW   (path & query tetap — kartu tercetak tetap berfungsi)
  .env diperbarui   APP_URL, TAPCARD_BASE_DOMAIN, TAPCARD_RESOLVER_URL, REVERB_HOST, GOOGLE_REDIRECT_URI
                    (+ REVERB_ALLOWED_ORIGINS / SESSION_DOMAIN / SANCTUM_STATEFUL_DOMAINS bila berisi domain lama)
EOF

step "DNS domain baru"
DNS_OK=1
for h in "$NEW" "app.$NEW" "tap.$NEW" "panel.$NEW" "ws.$NEW"; do
  if ip=$(getent ahostsv4 "$h" | awk 'NR==1 {print $1}') && [[ -n $ip ]]; then ok "$h → $ip"; else warn "$h belum mengarah ke mana pun"; DNS_OK=0; fi
done
[[ $DNS_OK == 1 ]] || warn "Buat record A untuk '$NEW' dan '*.$NEW' ke IP VPS ini (lihat deploy/README.md bagian 1)."
[[ $DNS_OK == 1 || $SSL_MODE != http ]] || die "SSL_MODE=http butuh DNS domain baru sudah mengarah ke VPS ini."

if [[ $DRY == 1 ]]; then
  step "Dry run — tidak ada yang diubah"
  exit 0
fi
if [[ $YES != 1 ]]; then
  printf '\n  Pengguna perlu login ulang setelah pindah domain. Lanjutkan? [y/N] '
  read -r answer; [[ ${answer,,} == y ]] || die "Dibatalkan"
fi

# ── 1. sertifikat domain baru ─────────────────────────────────────────────────────────────────
step "Sertifikat SSL $NEW ($SSL_MODE)"
LIVE=/etc/letsencrypt/live/$NEW
if [[ -f $LIVE/fullchain.pem ]] && ! grep -q 'TAPCARD SELF-SIGNED' "$LIVE/.tapcard" 2>/dev/null; then
  ok "Sertifikat sudah ada ($LIVE)"
else
  case $SSL_MODE in
    cloudflare)
      CF=/root/.secrets/cloudflare.ini
      if [[ -n ${CLOUDFLARE_API_TOKEN:-} ]]; then
        # file terpisah: kredensial domain lama tetap dipakai untuk memperpanjang sertifikat lamanya
        install -d -m 700 /root/.secrets; CF=/root/.secrets/cloudflare-$NEW.ini
        printf 'dns_cloudflare_api_token = %s\n' "$CLOUDFLARE_API_TOKEN" > "$CF" && chmod 600 "$CF"
      fi
      [[ -f $CF ]] || die "Token Cloudflare tidak ada. Jalankan: CLOUDFLARE_API_TOKEN=… sudo -E bash $0 $NEW"
      rm -rf "$LIVE"
      certbot certonly --non-interactive --agree-tos -m "$ADMIN_EMAIL" --cert-name "$NEW" \
        --dns-cloudflare --dns-cloudflare-credentials "$CF" --dns-cloudflare-propagation-seconds 30 \
        -d "$NEW" -d "*.$NEW" || die "Sertifikat gagal — pastikan token berizin Zone → DNS → Edit untuk $NEW. Tidak ada yang diubah."
      ;;
    http)
      # blok port 80 yang ada menjadi default_server dan melayani /.well-known/acme-challenge/ untuk host apa pun
      NAMES=(-d "$NEW"); for s in www app tap panel ws; do getent ahosts "$s.$NEW" >/dev/null && NAMES+=(-d "$s.$NEW"); done
      rm -rf "$LIVE"
      certbot certonly --non-interactive --agree-tos -m "$ADMIN_EMAIL" --cert-name "$NEW" --webroot -w /var/www/letsencrypt "${NAMES[@]}" \
        || die "Sertifikat gagal — pastikan DNS $NEW mengarah ke VPS ini & port 80 terbuka. Tidak ada yang diubah."
      warn "Tanpa wildcard: subdomain workspace perusahaan ({slug}.$NEW) belum tercakup SSL. Pakai --ssl cloudflare untuk itu."
      ;;
    none)
      install -d "$LIVE"
      openssl req -x509 -nodes -newkey rsa:2048 -days 30 -subj "/CN=$NEW" -addext "subjectAltName=DNS:$NEW,DNS:*.$NEW" \
        -keyout "$LIVE/privkey.pem" -out "$LIVE/fullchain.pem" 2>/dev/null
      echo 'TAPCARD SELF-SIGNED' > "$LIVE/.tapcard"
      warn "Sertifikat sementara (self-signed) — browser akan memberi peringatan."
      ;;
  esac
  ok "Sertifikat siap"
fi

# ── 2. Nginx: domain baru + pengalihan domain lama ────────────────────────────────────────────
step "Nginx"
BACKUP=$APP/shared/domain-switch-$STAMP
install -d -m 700 "$BACKUP"
cp -a "$ENV" "$BACKUP/.env"
cp -a "$SITES"/tapcard*.conf "$BACKUP/" 2>/dev/null || true
restore() {
  warn "Memulihkan konfigurasi sebelumnya dari $BACKUP"
  rm -f "$SITES"/tapcard-redirect-*.conf /etc/nginx/sites-enabled/tapcard-redirect-*.conf
  cp -a "$BACKUP"/tapcard*.conf "$SITES/"
  for f in "$SITES"/tapcard-redirect-*.conf; do [[ -e $f ]] && ln -sfn "$f" "/etc/nginx/sites-enabled/${f##*/}"; done
  cp -a "$BACKUP/.env" "$ENV"
  nginx -t >/dev/null 2>&1 && systemctl reload nginx
}

redirect_conf() { # redirect_conf <domain-lama> <domain-baru>
  local from=$1 to=$2 re=${1//./\\.} cert=/etc/letsencrypt/live/$1
  cat <<EOF
# TapCard — $from dialihkan ke $to (dibuat switch-domain.sh $STAMP). JANGAN dihapus selama kartu lama beredar:
# chip NFC & QR berisi https://tap.$from/… . 308 = permanen, metode & body tetap (webhook POST ikut diteruskan).
server {
    listen 80;
    listen [::]:80;
    server_name ~^(?<tc_sub>[a-z0-9-]+\.)?$re\$;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
        default_type text/plain;
    }
    location / {
        return 308 https://\${tc_sub}$to\$request_uri;
    }
}
EOF
  if [[ -f $cert/fullchain.pem ]]; then
    cat <<EOF

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ~^(?<tc_sub>[a-z0-9-]+\.)?$re\$;

    ssl_certificate     $cert/fullchain.pem;
    ssl_certificate_key $cert/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    return 308 https://\${tc_sub}$to\$request_uri;
}
EOF
  fi
}

sed "s/TAPCARD_DOMAIN/$NEW/g" "$TEMPLATE" > "$SITES/tapcard.conf"
rm -f "$SITES/tapcard-redirect-$NEW.conf" "/etc/nginx/sites-enabled/tapcard-redirect-$NEW.conf"   # kembali ke domain lama
for d in "${REDIRECT_FROM[@]}"; do
  redirect_conf "$d" "$NEW" > "$SITES/tapcard-redirect-$d.conf"
  ln -sfn "$SITES/tapcard-redirect-$d.conf" "/etc/nginx/sites-enabled/tapcard-redirect-$d.conf"
  if [[ ! -f /etc/letsencrypt/live/$d/fullchain.pem ]]; then warn "Sertifikat $d tidak ada: hanya http://$d yang dialihkan (QR/NFC https tidak)."; fi
done
if ! nginx -t 2>/dev/null; then nginx -t || true; restore; die "Konfigurasi Nginx tidak valid — semua dikembalikan."; fi
ok "server_name $NEW *.$NEW"
for d in "${REDIRECT_FROM[@]}"; do ok "$d *.$d → 308 $NEW"; done

# ── 3. .env ───────────────────────────────────────────────────────────────────────────────────
step ".env"
set_env() { # set_env KEY VALUE → ganti baris yang ada atau tambahkan
  local k=$1 v=$2
  [[ $v =~ [[:space:]\#\"] ]] && v="\"${v//\"/\\\"}\""
  if grep -q "^$k=" "$ENV"; then
    KV="$k=$v" perl -pi -e 'BEGIN { $kv = $ENV{KV}; ($k) = $kv =~ /^([^=]+)=/ } $_ = "$kv\n" if /^\Q$k\E=/' "$ENV"
  else
    printf '%s=%s\n' "$k" "$v" >> "$ENV"
  fi
}
set_env APP_URL "https://app.$NEW"
set_env TAPCARD_BASE_DOMAIN "$NEW"
set_env TAPCARD_RESOLVER_URL "https://tap.$NEW"
set_env REVERB_HOST "ws.$NEW"
set_env GOOGLE_REDIRECT_URI "https://app.$NEW/auth/google/callback"
for k in REVERB_ALLOWED_ORIGINS SESSION_DOMAIN SANCTUM_STATEFUL_DOMAINS; do
  v=$(env_get "$k")
  if [[ $v == *"$OLD"* ]]; then set_env "$k" "${v//$OLD/$NEW}"; ok "$k disesuaikan"; fi
done
chown root:www-data "$ENV" && chmod 640 "$ENV"   # perl -i membuat file baru milik root; www-data harus tetap bisa membaca
ok "Domain aplikasi → $NEW (cadangan: $BACKUP/.env)"
# sisa nilai berisi domain lama (mis. MAIL_FROM_ADDRESS, host SMTP/DB) sengaja tidak diubah otomatis
LEFT=$(grep -E "^[A-Z0-9_]+=.*${OLD//./\\.}" "$ENV" | cut -d= -f1 | tr '\n' ' ' || true)
[[ -z $LEFT ]] || warn "Masih berisi $OLD (periksa manual): $LEFT"

# ── 4. terapkan ───────────────────────────────────────────────────────────────────────────────
step "Menerapkan"
as_app() { runuser -u www-data -- php "$APP/current/artisan" "$@"; }
# route:cache ikut dibuat ulang: rute host (app./tap./panel./{slug}.) terkunci pada domain saat cache dibuat
if ! { as_app config:cache >/dev/null && as_app route:cache >/dev/null; }; then
  restore; as_app config:cache >/dev/null 2>&1 || true; as_app route:cache >/dev/null 2>&1 || true
  die "Cache Laravel gagal — semua dikembalikan ke $OLD."
fi
systemctl reload "php$PHP_VERSION-fpm"
systemctl reload nginx
as_app queue:restart >/dev/null
supervisorctl restart tapcard-schedule tapcard-reverb >/dev/null 2>&1 || true
ok "Laravel, PHP-FPM, Nginx, antrean, scheduler, Reverb"
printf '%s %s -> %s\n' "$STAMP" "$OLD" "$NEW" >> "$APP/shared/domain-history.log"

# ── 5. pemeriksaan ────────────────────────────────────────────────────────────────────────────
step "Pemeriksaan"
sleep 2   # worker Nginx lama selesai melayani koneksinya dulu setelah reload
CURL=(curl -s -o /dev/null --max-time 15); [[ $SSL_MODE == none ]] && CURL+=(-k)
for h in "$NEW" "app.$NEW" "panel.$NEW"; do
  code=$("${CURL[@]}" -w '%{http_code}' --resolve "$h:443:127.0.0.1" "https://$h/") || code=000
  [[ $code =~ ^(200|302)$ ]] && ok "https://$h → $code" || warn "https://$h → $code (log: $APP/shared/storage/logs)"
done
for d in "${REDIRECT_FROM[@]}"; do
  scheme=https; [[ -f /etc/letsencrypt/live/$d/fullchain.pem ]] || scheme=http
  port=443; [[ $scheme == http ]] && port=80
  got=$("${CURL[@]}" -k -w '%{http_code} %{redirect_url}' --resolve "tap.$d:$port:127.0.0.1" "$scheme://tap.$d/c/CONTOH12345?src=nfc") || got=000
  [[ $got == "308 https://tap.$NEW/c/CONTOH12345?src=nfc" ]] && ok "$scheme://tap.$d/c/… → 308 tap.$NEW (kartu lama aman)" || warn "Pengalihan tap.$d: $got"
done

cat <<EOF

────────────────────────────────────────────────────────────────────
 ✓ TapCard sekarang di $NEW

   Aplikasi         https://app.$NEW
   Panel pemilik    https://panel.$NEW
   Resolver kartu   https://tap.$NEW   (kartu baru tercetak dengan alamat ini)
   Domain lama      ${REDIRECT_FROM[*]} → dialihkan; JANGAN biarkan domain lama kedaluwarsa

 Langkah manual:
   1. Google Cloud Console → OAuth → Authorized redirect URI: https://app.$NEW/auth/google/callback
   2. Dashboard payment gateway → URL webhook: https://$NEW/webhooks/payment
   3. Penyedia SMTP → verifikasi domain $NEW (SPF/DKIM) bila ingin MAIL_FROM_ADDRESS memakai domain baru
   4. Domain kustom pelanggan → CNAME diarahkan ke app.$NEW
   5. Pengguna perlu login ulang; aplikasi PWA dipasang ulang dari https://app.$NEW

 Batalkan / kembali ke domain lama:  sudo bash $APP/current/deploy/switch-domain.sh $OLD
   (sertifikat $OLD masih ada; cadangan .env & Nginx sebelum pindah: $BACKUP)
────────────────────────────────────────────────────────────────────
EOF
