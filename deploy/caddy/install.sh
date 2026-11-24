#!/usr/bin/env bash
# TapCard — SSL otomatis untuk domain kustom: Caddy (on-demand TLS) di depan, Nginx pindah ke 127.0.0.1:8081 (HTTP).
# Pemakaian (sebagai root, di server yang sudah terpasang deploy/install.sh):
#   sudo TAPCARD_DOMAIN=tapcardin.my.id bash /var/www/tapcard/current/deploy/caddy/install.sh [--dry-run]
# Idempotent: aman dijalankan ulang. Konfigurasi Nginx lama dicadangkan, bukan dihapus.
set -euo pipefail

DRY=0
[[ ${1:-} == --dry-run ]] && DRY=1
DOMAIN=${TAPCARD_DOMAIN:-}
APP=/var/www/tapcard/current
SRC=$(cd "$(dirname "$0")" && pwd)
STAMP=$(date +%Y%m%d%H%M%S)

die() { echo "✗ $*" >&2; exit 1; }
say() { echo "→ $*"; }
run() { if ((DRY)); then echo "  + $*"; else "$@"; fi; }

[[ $EUID -eq 0 ]] || ((DRY)) || die "jalankan sebagai root (sudo)"
[[ $DOMAIN =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "TAPCARD_DOMAIN belum benar (contoh: tapcardin.my.id)"
[[ -f $SRC/Caddyfile && -f $SRC/nginx-internal.conf ]] || die "berkas deploy/caddy tidak ditemukan di $SRC"
command -v nginx >/dev/null || die "nginx belum terpasang (jalankan deploy/install.sh dulu)"

# 1. Caddy dari repositori resmi (https://caddyserver.com/docs/install#debian-ubuntu-raspbian)
if command -v caddy >/dev/null; then
  say "Caddy sudah terpasang: $(caddy version 2>/dev/null | head -1)"
else
  say "memasang Caddy dari repositori resmi"
  run apt-get install -y -qq debian-keyring debian-archive-keyring apt-transport-https curl gnupg
  run bash -c "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg"
  run bash -c "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' > /etc/apt/sources.list.d/caddy-stable.list"
  run apt-get update -qq
  run apt-get install -y -qq caddy
fi

# 2. Nginx: cadangkan site lama, pasang versi internal (HTTP, 127.0.0.1:8081)
say "memindahkan Nginx ke 127.0.0.1:8081"
if [[ -e /etc/nginx/sites-available/tapcard.conf ]]; then
  run cp -a /etc/nginx/sites-available/tapcard.conf "/etc/nginx/sites-available/tapcard.conf.bak-$STAMP"
fi
run rm -f /etc/nginx/sites-enabled/tapcard.conf /etc/nginx/sites-enabled/tapcard-acme.conf
run cp "$SRC/nginx-internal.conf" /etc/nginx/sites-available/tapcard-internal.conf
run ln -sfn /etc/nginx/sites-available/tapcard-internal.conf /etc/nginx/sites-enabled/tapcard-internal.conf
if ((!DRY)); then
  if ! nginx -t 2>/tmp/tapcard-nginx-t.log; then
    echo "✗ konfigurasi Nginx tidak valid, dikembalikan:" >&2; cat /tmp/tapcard-nginx-t.log >&2
    rm -f /etc/nginx/sites-enabled/tapcard-internal.conf
    [[ -e /etc/nginx/sites-available/tapcard.conf.bak-$STAMP ]] && ln -sfn /etc/nginx/sites-available/tapcard.conf /etc/nginx/sites-enabled/tapcard.conf
    exit 1
  fi
fi
run systemctl reload nginx

# 3. Caddy: Caddyfile dengan domain terisi, validasi, lalu jalankan
say "menyiapkan Caddyfile untuk $DOMAIN"
if ((DRY)); then echo "  + sed TAPCARD_DOMAIN=$DOMAIN $SRC/Caddyfile > /etc/caddy/Caddyfile"; else
  install -d /etc/caddy
  sed "s/TAPCARD_DOMAIN/$DOMAIN/g" "$SRC/Caddyfile" > /etc/caddy/Caddyfile
  caddy validate --config /etc/caddy/Caddyfile
fi
run systemctl enable --now caddy
run systemctl reload caddy

# 4. Certbot tidak lagi dipakai Nginx (TLS pindah ke Caddy); sertifikat lama dibiarkan di /etc/letsencrypt
run systemctl disable --now certbot.timer

cat <<EOF

✓ Selesai. Langkah berikutnya:
  1. Cek aplikasi: curl -I https://$DOMAIN/   (harus 200 dan sertifikat valid)
  2. Cek realtime: curl -I https://ws.$DOMAIN/ (boleh 404 dari Reverb, yang penting TLS berhasil)
  3. Domain kustom pelanggan: arahkan CNAME ke app.$DOMAIN, lalu verifikasi di panel.
     Sertifikat terbit otomatis saat domain pertama kali diakses (bisa beberapa detik).
  4. Pantau: journalctl -u caddy -f
EOF
