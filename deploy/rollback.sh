#!/usr/bin/env bash
# TapCard — kembali ke rilis sebelumnya (atau rilis tertentu).
#   sudo bash /var/www/tapcard/current/deploy/rollback.sh            → rilis sebelum yang aktif
#   sudo bash /var/www/tapcard/current/deploy/rollback.sh 20261101…  → nama folder di releases/
# Catatan: migrasi database TIDAK dibatalkan otomatis. Migrasi TapCard hanya menambah kolom/tabel,
# jadi kode lama tetap berjalan di skema baru.
set -euo pipefail

APP=/var/www/tapcard
[[ $EUID -eq 0 ]] || { echo "Jalankan sebagai root: sudo bash $0" >&2; exit 1; }
# shellcheck source=/dev/null
source "$APP/shared/deploy.conf"

CURRENT=$(readlink -f "$APP/current")
if [[ -n ${1:-} ]]; then
  TARGET="$APP/releases/$1"
else
  TARGET=$(ls -1dt "$APP"/releases/* | grep -vx "$CURRENT" | head -1 || true)
fi
[[ -n $TARGET && -d $TARGET ]] || { echo "Tidak ada rilis untuk dikembalikan." >&2; ls -1t "$APP/releases"; exit 1; }

ln -sfn "$TARGET" "$APP/current.tmp" && mv -Tf "$APP/current.tmp" "$APP/current"
systemctl reload "php${PHP_VERSION:-8.3}-fpm"
runuser -u www-data -- php "$APP/current/artisan" queue:restart
supervisorctl restart tapcard-schedule >/dev/null 2>&1 || true
echo "✓ Rilis aktif kembali ke: $TARGET"
