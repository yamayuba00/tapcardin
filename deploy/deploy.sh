#!/usr/bin/env bash
# TapCard — rilis baru tanpa downtime (pola releases/ + symlink `current`).
#   /var/www/tapcard/{releases/<waktu>, shared/{.env,storage,deploy.conf}, current -> releases/<waktu>}
#
# Pemakaian (sebagai root):   sudo bash /var/www/tapcard/current/deploy/deploy.sh [branch|tag]
# Pertama kali dijalankan otomatis oleh deploy/install.sh. Rilis gagal di tengah jalan tidak pernah
# menggantikan rilis aktif — `current` baru dipindah setelah build, migrasi, dan cache berhasil.
set -euo pipefail

APP=/var/www/tapcard
CONF="$APP/shared/deploy.conf"
[[ $EUID -eq 0 ]] || { echo "Jalankan sebagai root: sudo bash $0" >&2; exit 1; }
[[ -f "$CONF" ]] || { echo "Belum terpasang ($CONF tidak ada). Jalankan deploy/install.sh dulu." >&2; exit 1; }
# shellcheck source=/dev/null
source "$CONF"   # REPO_URL, BRANCH, GIT_SSH_KEY, PHP_VERSION

REF=${1:-$BRANCH}
KEEP=5
NEW="$APP/releases/$(date +%Y%m%d%H%M%S)"
CACHE="$APP/.cache"   # cache composer/npm milik www-data (di luar rilis)
as_app() { runuser -u www-data -- env HOME="$CACHE" COMPOSER_HOME="$CACHE/composer" npm_config_cache="$CACHE/npm" "$@"; }

echo "→ Mengambil $REF dari $REPO_URL"
GIT_SSH_COMMAND="ssh -i ${GIT_SSH_KEY:-/root/.ssh/id_ed25519} -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new" \
  git clone --quiet --depth 1 --branch "$REF" "$REPO_URL" "$NEW"
mkdir -p "$CACHE"
chown -R www-data:www-data "$NEW" "$CACHE"
cd "$NEW"

ln -sfn "$APP/shared/.env" .env
rm -rf storage && ln -sfn "$APP/shared/storage" storage

echo "→ Dependensi & build aset"
as_app composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative --no-interaction --no-progress
as_app npm ci --no-audit --no-fund --loglevel=error
as_app npm run build
rm -rf node_modules

echo "→ Migrasi database & cache"
as_app php artisan migrate --force
if [[ ! -f "$APP/shared/.seeded" ]]; then
  # sekali saja: peran & izin, paket, slug terlarang, jenis kontak, akun Pemilik Platform (SEED_PLATFORM_OWNER_EMAIL)
  as_app php artisan db:seed --force
  touch "$APP/shared/.seeded"
fi
as_app php artisan storage:link --force
# Wajib di produksi. Setelah config:cache, env() hanya terbaca di config/*.php.
as_app php artisan config:cache
as_app php artisan route:cache
as_app php artisan view:cache
as_app php artisan event:cache

echo "→ Mengaktifkan rilis"
ln -sfn "$NEW" "$APP/current.tmp" && mv -Tf "$APP/current.tmp" "$APP/current"

# OPcache validate_timestamps=0 → reload agar kode baru terbaca; worker antrean mulai ulang setelah job berjalan selesai.
systemctl reload "php${PHP_VERSION:-8.3}-fpm"
as_app php "$APP/current/artisan" queue:restart
supervisorctl restart tapcard-schedule >/dev/null 2>&1 || true
supervisorctl restart tapcard-reverb >/dev/null 2>&1 || true   # realtime: muat kode & config baru

ls -1dt "$APP"/releases/* | tail -n +$((KEEP + 1)) | xargs -r rm -rf
echo "✓ Rilis aktif: $NEW"
