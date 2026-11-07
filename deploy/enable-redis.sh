#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────────────────────
# TapCard — pindahkan ANTREAN & CACHE ke Redis (server yang sudah berjalan). Aman dijalankan ulang.
#
#   sudo bash /var/www/tapcard/current/deploy/enable-redis.sh [--maxmemory 256mb] [--yes]
#   sudo bash /var/www/tapcard/current/deploy/enable-redis.sh --off      # kembali ke database
#
# Yang dilakukan: pasang redis-server (repositori Ubuntu, lisensi BSD) + ekstensi php-redis → Redis hanya mendengar di
# 127.0.0.1 dengan password acak, disimpan ke disk tiap detik (AOF), batas memori dengan kebijakan volatile-lru (hanya
# cache yang ber-TTL yang dibuang; job antrean tidak pernah) → uji koneksi dari Laravel → .env: QUEUE_CONNECTION &
# CACHE_STORE=redis → restart worker → job lama di tabel `jobs` dihabiskan. Bila uji gagal, .env dikembalikan.
#
# SESI LOGIN SENGAJA TETAP DI DATABASE: panel memakai tabel `sessions` untuk "blokir pengguna" & "keluarkan dari semua
# perangkat" — di Redis kedua fitur itu diam-diam berhenti bekerja.
# ──────────────────────────────────────────────────────────────────────────────────────────────
set -euo pipefail

APP=/var/www/tapcard
ENV=$APP/shared/.env
CONF=/etc/redis/redis.conf
QUEUES=taps,notify,leads,default,render   # sama dengan deploy/supervisor/tapcard.conf
STAMP=$(date +%Y%m%d%H%M%S)

step() { printf '\n\033[1;33m▶ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

MAXMEM=256mb OFF=0 YES=0
while [[ $# -gt 0 ]]; do
  case $1 in
    --maxmemory) MAXMEM=${2:-}; shift 2 ;;
    --maxmemory=*) MAXMEM=${1#*=}; shift ;;
    --off) OFF=1; shift ;;
    --yes|-y) YES=1; shift ;;
    -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
    *) die "Opsi tidak dikenal: $1" ;;
  esac
done
[[ $MAXMEM =~ ^[0-9]+(mb|gb)$ ]] || die "--maxmemory harus seperti 256mb atau 1gb"

[[ $EUID -eq 0 ]] || die "Jalankan sebagai root: sudo bash $0"
[[ -f $ENV ]] || die "TapCard belum terpasang ($ENV tidak ada). Jalankan deploy/install.sh dulu."
# shellcheck source=/dev/null
source "$APP/shared/deploy.conf"   # PHP_VERSION
PHP_VERSION=${PHP_VERSION:-8.3}

env_get() { { grep -m1 "^$1=" "$ENV" || true; } | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
set_env() { # set_env KEY VALUE → ganti baris yang ada atau tambahkan
  local k=$1 v=$2
  [[ $v =~ [[:space:]\#\"] ]] && v="\"${v//\"/\\\"}\""
  if grep -q "^$k=" "$ENV"; then
    KV="$k=$v" perl -pi -e 'BEGIN { $kv = $ENV{KV}; ($k) = $kv =~ /^([^=]+)=/ } $_ = "$kv\n" if /^\Q$k\E=/' "$ENV"
  else
    printf '%s=%s\n' "$k" "$v" >> "$ENV"
  fi
}
as_app() { runuser -u www-data -- php "$APP/current/artisan" "$@"; }
BACKUP=$APP/shared/.env.before-redis-$STAMP
restore_env() {
  warn "Mengembalikan .env dari $BACKUP"
  cp -a "$BACKUP" "$ENV"
  as_app config:cache >/dev/null 2>&1 || true
}
apply() { # muat config baru ke PHP-FPM, worker, scheduler, Reverb
  systemctl reload "php$PHP_VERSION-fpm"
  as_app queue:restart >/dev/null
  supervisorctl restart tapcard-schedule tapcard-reverb >/dev/null 2>&1 || true
}
drain() { # drain <koneksi>: kerjakan sisa job di koneksi lama sampai kosong
  local left
  left=$(timeout 900 runuser -u www-data -- php "$APP/current/artisan" queue:work "$1" --queue="$QUEUES" \
    --stop-when-empty --tries=3 --max-time=840 2>&1 | grep -c ' DONE' || true)
  ok "Sisa job di $1 dikerjakan: $left"
}

# ── kembali ke database ───────────────────────────────────────────────────────────────────────
if [[ $OFF == 1 ]]; then
  step "Antrean & cache kembali ke database"
  [[ $(env_get QUEUE_CONNECTION) == redis || $(env_get CACHE_STORE) == redis ]] || { ok "Sudah memakai database"; exit 0; }
  cp -a "$ENV" "$BACKUP"
  set_env QUEUE_CONNECTION database
  set_env CACHE_STORE database
  chown root:www-data "$ENV" && chmod 640 "$ENV"
  as_app config:cache >/dev/null || { restore_env; die "config:cache gagal"; }
  apply
  ok "QUEUE_CONNECTION=database, CACHE_STORE=database (cadangan: $BACKUP)"
  drain redis
  warn "Redis tetap terpasang tapi tidak dipakai. Hapus bila mau: apt-get remove redis-server"
  exit 0
fi

if [[ $YES != 1 ]]; then
  cat <<EOF

  Antrean & cache TapCard akan dipindah ke Redis (memori maks $MAXMEM). Sesi login tetap di database.
  Cache mulai kosong (beberapa menit sedikit lebih lambat); job yang sedang menunggu tetap dikerjakan.
EOF
  printf '  Lanjutkan? [y/N] '
  read -r answer; [[ ${answer,,} == y ]] || die "Dibatalkan"
fi

# ── 1. paket ──────────────────────────────────────────────────────────────────────────────────
step "Paket"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y -qq redis-server "php$PHP_VERSION-redis" >/dev/null || { apt-get update -qq && apt-get install -y -qq redis-server "php$PHP_VERSION-redis" >/dev/null; }
phpenmod -v "$PHP_VERSION" redis 2>/dev/null || true
php -m | grep -qx redis || die "Ekstensi php-redis tidak aktif (php -m)"
ok "$(redis-server --version | grep -o 'v=[0-9.]*' | sed 's/v=/Redis /') + php$PHP_VERSION-redis"

# ── 2. konfigurasi Redis ──────────────────────────────────────────────────────────────────────
step "Konfigurasi Redis"
PASS=$(env_get REDIS_PASSWORD)
[[ -n $PASS && $PASS != null ]] || PASS=$(openssl rand -hex 24)
[[ -f $CONF.tapcard-orig ]] || cp -a "$CONF" "$CONF.tapcard-orig"
KEYS='bind|protected-mode|port|requirepass|maxmemory|maxmemory-policy|appendonly|appendfsync'
sed -i -E "/^# >>> TapCard/,/^# <<< TapCard/d; /^($KEYS) /d" "$CONF"
cat >> "$CONF" <<EOF
# >>> TapCard (deploy/enable-redis.sh) — jangan buka ke internet; password juga ada di $ENV
bind 127.0.0.1 -::1
protected-mode yes
port 6379
requirepass $PASS
maxmemory $MAXMEM
# hanya kunci ber-TTL (cache) yang dibuang saat penuh — job antrean tidak pernah dibuang
maxmemory-policy volatile-lru
# simpan ke disk tiap detik: Redis/VPS restart → job antrean paling banyak hilang 1 detik
appendonly yes
appendfsync everysec
# <<< TapCard
EOF
chown redis:redis "$CONF" && chmod 640 "$CONF"
systemctl enable redis-server >/dev/null 2>&1 || true
systemctl restart redis-server
for _ in $(seq 1 20); do
  [[ $(REDISCLI_AUTH=$PASS redis-cli -h 127.0.0.1 -p 6379 ping 2>/dev/null) == PONG ]] && break
  sleep 0.5
done
[[ $(REDISCLI_AUTH=$PASS redis-cli -h 127.0.0.1 -p 6379 ping 2>/dev/null) == PONG ]] || die "Redis tidak menjawab (journalctl -u redis-server). .env belum diubah."
[[ $(redis-cli -h 127.0.0.1 -p 6379 ping 2>&1) == *NOAUTH* ]] || die "Redis bisa diakses tanpa password — periksa $CONF. .env belum diubah."
ok "127.0.0.1:6379, password, maxmemory $MAXMEM (volatile-lru), AOF tiap detik"

# ── 3. .env & uji dari Laravel ────────────────────────────────────────────────────────────────
step ".env"
cp -a "$ENV" "$BACKUP"
set_env REDIS_CLIENT phpredis
set_env REDIS_HOST 127.0.0.1
set_env REDIS_PORT 6379
set_env REDIS_PASSWORD "$PASS"
set_env QUEUE_CONNECTION redis
set_env CACHE_STORE redis
chown root:www-data "$ENV" && chmod 640 "$ENV"
as_app config:cache >/dev/null || { restore_env; die "config:cache gagal — .env dikembalikan."; }
HEALTH=$(as_app tapcard:health 2>&1 || true)
if ! grep -q 'Memori Redis' <<<"$HEALTH" || grep -qE 'Koneksi Redis|Antrean \(redis\)' <<<"$HEALTH"; then
  printf '%s\n' "$HEALTH" | sed 's/^/    /'
  restore_env; die "Laravel tidak bisa memakai Redis — .env dikembalikan, aplikasi tetap memakai database."
fi
ok "QUEUE_CONNECTION=redis, CACHE_STORE=redis, SESSION_DRIVER tetap $(env_get SESSION_DRIVER) (cadangan: $BACKUP)"

# ── 4. terapkan ───────────────────────────────────────────────────────────────────────────────
step "Menerapkan"
apply
ok "PHP-FPM, worker antrean, scheduler, Reverb"
drain database   # job yang masuk sebelum pindah tetap dikerjakan
sleep 2; supervisorctl status 2>/dev/null | grep tapcard | sed 's/^/  /' || true

step "Pemeriksaan"
as_app tapcard:health 2>&1 | grep -E 'Redis|Job|Antrean' | sed 's/^/  /' || true

cat <<EOF

────────────────────────────────────────────────────────────────────
 ✓ Antrean & cache TapCard memakai Redis

   Pantau      sudo -u www-data php $APP/current/artisan tapcard:health   (atau panel → Sistem)
   Memori      redis-cli -a '<REDIS_PASSWORD>' info memory | grep used_memory_human
   Batalkan    sudo bash $APP/current/deploy/enable-redis.sh --off
────────────────────────────────────────────────────────────────────
EOF
