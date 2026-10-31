#!/usr/bin/env bash
# TapCard — rilis tanpa downtime dengan pola releases/ + symlink `current`.
#   /var/www/tapcard/{releases/<waktu>, shared/{.env,storage}, current -> releases/<waktu>}
# Pemakaian: deploy/deploy.sh <git-ref>   (jalankan sebagai www-data atau user deploy)
set -euo pipefail

APP=/var/www/tapcard
REF=${1:-main}
REPO=${TAPCARD_REPO:?set TAPCARD_REPO ke URL git}
FPM_SERVICE=${FPM_SERVICE:-php8.3-fpm}
KEEP=5

NEW="$APP/releases/$(date +%Y%m%d%H%M%S)"
echo "→ rilis $REF ke $NEW"
git clone --depth 1 --branch "$REF" "$REPO" "$NEW"
cd "$NEW"

ln -sfn "$APP/shared/.env" .env
rm -rf storage && ln -sfn "$APP/shared/storage" storage

composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative --no-interaction
npm ci --no-audit --no-fund
npm run build
rm -rf node_modules

php artisan migrate --force
php artisan storage:link --force
# Cache konfigurasi/rute/view/event: wajib di produksi (setiap request tidak membaca ulang file).
# Catatan: setelah config:cache, env() hanya terbaca di config/*.php.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

ln -sfn "$NEW" "$APP/current.tmp" && mv -Tf "$APP/current.tmp" "$APP/current"

# OPcache validate_timestamps=0 → reload agar kode baru terbaca; worker antrean memuat ulang setelah job berjalan.
sudo systemctl reload "$FPM_SERVICE"
php "$APP/current/artisan" queue:restart

ls -1dt "$APP"/releases/* | tail -n +$((KEEP + 1)) | xargs -r rm -rf
echo "✓ rilis aktif: $NEW"
