#!/usr/bin/env sh
# TRD §15 / DBD §3.4 — aturan desain tanpa paket: gagal jika ada gradien, bayangan, atau hex di luar tokens.css.
# Pengecualian eksplisit: ikon aplikasi & manifest (tidak bisa membaca CSS) dan template email (warna dari token).
set -u
cd "$(dirname "$0")/.."
fail=0
paths="resources public mobile/lib"
exclude='--exclude-dir=build --exclude-dir=fonts --exclude=*.woff2'

if grep -rnE 'gradient|Gradient|<linearGradient|<radialGradient|<meshgradient' $exclude $paths 2>/dev/null \
    | grep -vE 'tokens\.css:|check-design'; then
  echo "✗ Gradien ditemukan (DBD §3.4)"; fail=1
fi

if grep -rnE '(box-shadow|text-shadow)\s*:\s*[^n;]' $exclude $paths 2>/dev/null | grep -vE ':\s*none'; then
  echo "✗ Bayangan selain none ditemukan (DBD §3.4)"; fail=1
fi

if grep -rnE '#[0-9A-Fa-f]{6}\b|#[0-9A-Fa-f]{3}\b' $exclude --include=*.css --include=*.vue --include=*.js --include=*.blade.php --include=*.svg $paths 2>/dev/null \
    | grep -vE 'resources/css/tokens\.css:|/__tests__/|resources/views/mail/|public/favicon|public/manifest|public/build/|&#[0-9]+;|#hatch|href="#|#main'; then
  echo "✗ Hex warna di luar tokens.css (DBD §3.4)"; fail=1
fi

# Setiap <button> mentah wajib menyatakan cursor-pointer (Tailwind v4 tidak memberi pointer bawaan).
PY=$(command -v python || command -v python3)
if ! "$PY" scripts/check-buttons.py; then
  echo "✗ <button> tanpa cursor-pointer"; fail=1
fi

if [ -d mobile/lib ] && grep -rnE 'elevation:\s*[1-9]' mobile/lib; then
  echo "✗ elevation > 0 di Flutter"; fail=1
fi

[ $fail -eq 0 ] && echo "✓ Aturan desain lolos"
exit $fail
