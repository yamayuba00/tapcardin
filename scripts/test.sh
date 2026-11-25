#!/usr/bin/env bash
# Menjalankan PHPUnit dengan kunci agar dua proses tidak memakai tapcard_test bersamaan.
# Pemakaian:
#   bash scripts/test.sh [argumen phpunit]
#   bash scripts/test.sh --clock          # jalankan dengan jam rawan WIB (TEST_CLOCK)
#   bash scripts/test.sh --clock=2026-11-08T01:30:00+07:00 tests/Feature/X.php
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GIT_DIR="$(cd "$ROOT" && git rev-parse --absolute-git-dir 2>/dev/null || echo "$ROOT/.git")"
LOCK="$GIT_DIR/tapcard-test.lock"
WAIT_MAX=900   # 15 menit
STALE=1800     # 30 menit
DEFAULT_CLOCK='2026-11-08T01:30:00+07:00'

clock=''
args=()
for a in "$@"; do
    case "$a" in
        --clock) clock="$DEFAULT_CLOCK" ;;
        --clock=*) clock="${a#--clock=}" ;;
        *) args+=("$a") ;;
    esac
done

locked=0
release() {
    if [ "$locked" = 1 ]; then
        rm -rf "$LOCK"
        locked=0
    fi
}
trap release EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

holder() {
    if [ -f "$LOCK/owner" ]; then
        echo "pemegang kunci: $(cat "$LOCK/owner")"
    else
        echo "pemegang kunci: tidak diketahui"
    fi
}

waited=0
while ! mkdir "$LOCK" 2>/dev/null; do
    if [ "$waited" -ge "$WAIT_MAX" ]; then
        echo "Gagal: kunci tes masih dipegang setelah 15 menit. $(holder)" >&2
        echo "Jika proses itu sudah mati, hapus folder: $LOCK" >&2
        exit 1
    fi
    if [ "$waited" -eq 0 ] || [ $((waited % 60)) -eq 0 ]; then
        echo "Menunggu kunci tes ($(holder))..." >&2
    fi
    if [ -d "$LOCK" ]; then
        age=$(( $(date +%s) - $(date -r "$LOCK" +%s 2>/dev/null || date +%s) ))
        if [ "$age" -gt "$STALE" ]; then
            echo "Peringatan: kunci sudah $((age / 60)) menit, $(holder). Periksa apakah proses itu masih berjalan." >&2
        fi
    fi
    sleep 5
    waited=$((waited + 5))
done
locked=1
echo "$(whoami)@$(hostname) pid $$ sejak $(date '+%Y-%m-%d %H:%M:%S')" > "$LOCK/owner"

cd "$ROOT" || exit 1
if [ -n "$clock" ]; then
    export TEST_CLOCK="$clock"
    echo "TEST_CLOCK=$clock"
fi
php vendor/bin/phpunit "${args[@]}"
