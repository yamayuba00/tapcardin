# Setup VPS manual — TapCard

Panduan langkah demi langkah memasang TapCard di VPS **Ubuntu 22.04 / 24.04** secara manual, tanpa `install.sh`.
Hasil akhirnya sama dengan installer otomatis: Nginx + PHP 8.3-FPM, worker antrean & scheduler (Supervisor), SSL,
dan rilis tanpa downtime di `/var/www/tapcard`.

Stack yang dipakai di panduan ini: **database PostgreSQL Sumopod** (lewat PgBouncer, port 6432), **SMTP Sumopod**
(port 465), dan **pembayaran QRIS** lewat payment service (`PAYMENT_*`).

> Jalankan semua perintah sebagai **root** (`sudo -i`). Setiap bagian diakhiri **✓ Cek** — jangan lanjut sebelum ceknya lolos.

---

## 0. Yang perlu disiapkan

| Item | Contoh / catatan |
|---|---|
| VPS | Ubuntu 22.04 atau 24.04, min. 2 GB RAM, akses root |
| Domain | `tapcard.id` (ganti dengan domain Anda di seluruh panduan) |
| DNS | Record **A** `@` dan **A** `*` → IP VPS (Cloudflare: *DNS only* / awan abu-abu) |
| Repo | `git@github.com:yamayuba00/tapcardin.git` |
| `.env.production` | Ada di laptop (folder proyek) — berisi koneksi DB & SMTP Sumopod |
| Email Anda | untuk akun Pemilik Platform & kontak Let's Encrypt |
| (Disarankan) Cloudflare API token | izin *Zone → DNS → Edit* untuk sertifikat wildcard |

Cek DNS dari laptop sebelum mulai — keduanya harus menjawab IP VPS:

```bash
nslookup app.tapcard.id
nslookup apa-saja.tapcard.id
```

Di VPS, simpan domain di variabel agar perintah berikutnya bisa langsung disalin:

```bash
export DOMAIN=tapcard.id
export APP=/var/www/tapcard
export GIT_SSH_COMMAND="ssh -i /root/.ssh/tapcard_deploy -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"
# perintah aplikasi (artisan, composer, npm) selalu dijalankan sebagai www-data
as_app() { sudo -u www-data env HOME=$APP/.cache COMPOSER_HOME=$APP/.cache/composer npm_config_cache=$APP/.cache/npm "$@"; }
```

> Blok ini hilang saat Anda keluar SSH — **tempel ulang setiap kali login** sebelum melanjutkan langkah mana pun.

---

## 1. Paket sistem

```bash
apt update && apt upgrade -y
apt install -y ca-certificates curl gnupg git unzip ufw supervisor nginx cron logrotate software-properties-common
```

**PHP 8.3** — Ubuntu 24.04 sudah punya; Ubuntu 22.04 perlu PPA dulu:

```bash
# hanya Ubuntu 22.04:
add-apt-repository -y ppa:ondrej/php && apt update

apt install -y php8.3-{fpm,cli,pgsql,intl,gd,mbstring,bcmath,xml,curl,zip,opcache,readline}
```

**Node 22** (untuk build aset) dan **Composer**:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs

EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
[ "$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" = "$EXPECTED" ] && \
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer; rm -f /tmp/composer-setup.php
```

**Certbot** (SSL):

```bash
apt install -y certbot python3-certbot-dns-cloudflare
```

**✓ Cek**

```bash
php -v | head -1          # PHP 8.3.x
php -m | grep -E 'pdo_pgsql|intl|gd|bcmath'   # keempatnya muncul
node -v                   # v22.x
composer --version
```

---

## 2. Firewall

```bash
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
```

**✓ Cek** — `ufw status` menampilkan OpenSSH dan Nginx Full **ALLOW**.

> Pastikan `OpenSSH` sudah di-*allow* sebelum `enable`, agar SSH Anda tidak terputus.

---

## 3. Akses repo GitHub (repo privat)

Buat *deploy key* khusus server ini:

```bash
ssh-keygen -t ed25519 -N '' -C "tapcard-deploy" -f /root/.ssh/tapcard_deploy
cat /root/.ssh/tapcard_deploy.pub
```

Salin isi `.pub`, lalu di GitHub: **repo → Settings → Deploy keys → Add deploy key** (centang *read-only*).

```bash
git ls-remote git@github.com:yamayuba00/tapcardin.git HEAD    # memakai GIT_SSH_COMMAND dari langkah 0
```

**✓ Cek** — perintah terakhir mencetak satu baris hash commit (bukan `Permission denied`).

---

## 4. Folder aplikasi

```bash
install -d -o www-data -g www-data $APP $APP/releases $APP/shared $APP/.cache
for d in app/public app/private framework/cache/data framework/sessions framework/views framework/testing logs; do
  install -d -o www-data -g www-data $APP/shared/storage/$d
done
install -d -o www-data -g www-data /var/log/tapcard
install -d /var/log/php-fpm /var/www/letsencrypt
```

Konfigurasi rilis (dipakai `deploy.sh` untuk rilis berikutnya):

```bash
cat > $APP/shared/deploy.conf <<'EOF'
REPO_URL=git@github.com:yamayuba00/tapcardin.git
BRANCH=main
GIT_SSH_KEY=/root/.ssh/tapcard_deploy
PHP_VERSION=8.3
EOF
```

---

## 5. File `.env` produksi

Kirim `.env.production` dari **laptop** (jalankan di folder proyek, bukan di VPS):

```bash
scp .env.production root@IP-VPS:/var/www/tapcard/shared/.env
```

Lalu di VPS, sesuaikan nilai yang bergantung domain dan buat rahasia baru:

```bash
nano $APP/shared/.env
```

| Kunci | Isi |
|---|---|
| `APP_URL` | `https://app.tapcard.id` |
| `TAPCARD_BASE_DOMAIN` | `tapcard.id` |
| `TAPCARD_RESOLVER_URL` | `https://tap.tapcard.id` |
| `GOOGLE_REDIRECT_URI` | `https://app.tapcard.id/auth/google/callback` (bila memakai login Google) |
| `MAIL_FROM_ADDRESS` | alamat di domain yang **sudah diverifikasi** di Sumopod SMTP, mis. `noreply@tapcard.id` |
| `SEED_PLATFORM_OWNER_EMAIL` | email Anda (akun Pemilik Platform) |
| `SEED_PLATFORM_OWNER_PASSWORD` | password sementara untuk login panel pertama kali (dikosongkan lagi di langkah 7) |
| `PAYMENT_API_URL` | base URL API pembayaran **produksi** dari Sumopod (yang terisi saat ini masih *sandbox*) |
| `PAYMENT_API_KEY` | API key pembayaran dari dashboard Sumopod |
| `PAYMENT_WEBHOOK_SECRET` | secret webhook (`whsec_…`) dari dashboard Sumopod |

Yang **sudah benar** dari `.env.production` dan tidak perlu diubah: `APP_ENV=production`, `APP_DEBUG=false`,
`DB_*` (Sumopod, `DB_PORT=6432`, `DB_SSLMODE=require`, `DB_EMULATE_PREPARES=true`), `MAIL_*` (`smtp.sumopod.com`, 465,
`MAIL_SCHEME=smtps`), `SESSION_LIFETIME=120`, dan `PAYMENT_DRIVER=link`.

`APP_KEY` dan pepper sudah terisi acak. **Jangan pernah mengganti `APP_KEY`, `TAPCARD_UID_PEPPER`, dan
`TAPCARD_IP_PEPPER` setelah aplikasi live** — data terenkripsi dan hash pengunjung bergantung pada nilainya.

Amankan file:

```bash
chown root:www-data $APP/shared/.env && chmod 640 $APP/shared/.env
```

**✓ Cek** — tidak ada lagi `tapcard.id` yang salah domain (bila domain Anda berbeda):

```bash
grep -nE 'APP_URL|BASE_DOMAIN|RESOLVER_URL|MAIL_FROM' $APP/shared/.env
```

---

## 6. PHP-FPM & OPcache

Ambil file konfigurasi dari repo:

```bash
git clone --depth 1 git@github.com:yamayuba00/tapcardin.git /root/tapcard-src
```

Pool `tapcard` (worker utama) + `tapcard-stream` (notifikasi realtime). Atur `pm.max_children` ≈ RAM(MB) ÷ 2 ÷ 50
(contoh: VPS 2 GB → 20, 4 GB → 40):

```bash
cp /root/tapcard-src/deploy/php/fpm-pools.conf /etc/php/8.3/fpm/pool.d/tapcard.conf
nano /etc/php/8.3/fpm/pool.d/tapcard.conf         # sesuaikan pm.max_children pool [tapcard]
mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.disabled
cp /root/tapcard-src/deploy/php/opcache-production.ini /etc/php/8.3/fpm/conf.d/99-tapcard-opcache.ini
php-fpm8.3 -t && systemctl restart php8.3-fpm
```

**✓ Cek** — `ls /run/php/` menampilkan `tapcard.sock` dan `tapcard-stream.sock`.

---

## 7. Rilis pertama

```bash
REL=$APP/releases/$(date +%Y%m%d%H%M%S)
git clone --depth 1 --branch main git@github.com:yamayuba00/tapcardin.git $REL
chown -R www-data:www-data $REL
cd $REL
ln -sfn $APP/shared/.env .env
rm -rf storage && ln -sfn $APP/shared/storage storage

as_app composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative --no-interaction
as_app npm ci --no-audit --no-fund
as_app npm run build
rm -rf node_modules
```

**Database** — buat tabel yang belum ada, lalu isi data awal (peran & izin, paket, jenis kontak, akun Pemilik Platform):

```bash
as_app php artisan migrate --force
as_app php artisan db:seed --force
touch $APP/shared/.seeded       # tanda bahwa data awal sudah diisi (deploy.sh tidak mengulanginya)
```

> `db:seed` hanya **sekali**. Menjalankannya lagi akan mengembalikan harga paket ke nilai awal.

Kosongkan lagi password Pemilik Platform di `.env` — akunnya sudah dibuat:

```bash
sed -i 's/^SEED_PLATFORM_OWNER_PASSWORD=.*/SEED_PLATFORM_OWNER_PASSWORD=/' $APP/shared/.env
```

**Cache & aktifkan rilis:**

```bash
as_app php artisan storage:link --force
as_app php artisan config:cache
as_app php artisan route:cache
as_app php artisan view:cache
as_app php artisan event:cache
ln -sfn $REL $APP/current
systemctl reload php8.3-fpm
```

**✓ Cek**

```bash
as_app php artisan migrate:status | tail -3        # semua "Ran"
as_app php artisan tapcard:mail-test               # "SMTP: tersambung & login berhasil."
ls -la $APP/current                                # menunjuk ke releases/<waktu>
```

---

## 8. Sertifikat SSL

Pilih **salah satu**.

### A. Wildcard lewat Cloudflare (disarankan)

Mencakup `tapcard.id` dan semua subdomain, termasuk subdomain workspace perusahaan.

```bash
install -d -m 700 /root/.secrets
echo "dns_cloudflare_api_token = ISI-TOKEN-ANDA" > /root/.secrets/cloudflare.ini
chmod 600 /root/.secrets/cloudflare.ini

certbot certonly --non-interactive --agree-tos -m email@anda.com --cert-name $DOMAIN \
  --dns-cloudflare --dns-cloudflare-credentials /root/.secrets/cloudflare.ini --dns-cloudflare-propagation-seconds 30 \
  -d $DOMAIN -d "*.$DOMAIN"
```

### B. Tanpa Cloudflare (per subdomain)

Hanya `domain`, `www`, `app`, `tap`, `panel` — subdomain workspace perusahaan belum tercakup.

```bash
cat > /etc/nginx/sites-available/acme.conf <<'EOF'
server { listen 80 default_server; listen [::]:80 default_server; server_name _;
  location ^~ /.well-known/acme-challenge/ { root /var/www/letsencrypt; } location / { return 404; } }
EOF
rm -f /etc/nginx/sites-enabled/* && ln -s /etc/nginx/sites-available/acme.conf /etc/nginx/sites-enabled/
systemctl reload nginx

certbot certonly --non-interactive --agree-tos -m email@anda.com --cert-name $DOMAIN \
  --webroot -w /var/www/letsencrypt \
  -d $DOMAIN -d www.$DOMAIN -d app.$DOMAIN -d tap.$DOMAIN -d panel.$DOMAIN

rm -f /etc/nginx/sites-enabled/acme.conf
```

Reload Nginx otomatis setiap kali sertifikat diperpanjang:

```bash
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
```

**✓ Cek** — `ls /etc/letsencrypt/live/$DOMAIN/` berisi `fullchain.pem` dan `privkey.pem`.

---

## 9. Nginx

```bash
sed "s/TAPCARD_DOMAIN/$DOMAIN/g" /root/tapcard-src/deploy/nginx/tapcard.conf > /etc/nginx/sites-available/tapcard.conf
rm -f /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/tapcard.conf /etc/nginx/sites-enabled/tapcard.conf
nginx -t && systemctl reload nginx
```

**✓ Cek** — dari laptop, ketiga alamat terbuka tanpa peringatan sertifikat:

- `https://tapcard.id` → landing page
- `https://app.tapcard.id/login` → halaman masuk
- `https://panel.tapcard.id/login` → login panel

---

## 10. Worker antrean & scheduler

Tanpa ini tap kartu tidak tercatat, notifikasi tidak terkirim, dan pengingat/tagihan tidak berjalan.

```bash
cp /root/tapcard-src/deploy/supervisor/tapcard.conf /etc/supervisor/conf.d/tapcard.conf
systemctl enable --now supervisor
supervisorctl reread && supervisorctl update
```

Rotasi log worker:

```bash
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
```

**✓ Cek** — `supervisorctl status` menampilkan `tapcard-queue:…_00`, `tapcard-queue:…_01`, dan `tapcard-schedule` **RUNNING**.

---

## 11. Uji menyeluruh

1. **Panel**: buka `https://panel.tapcard.id`, masuk dengan `SEED_PLATFORM_OWNER_EMAIL` + password sementara dari langkah 5,
   lalu **langsung ganti password** di *Akun & password*.
2. **Daftar pengguna**: buka `https://app.tapcard.id/register`, daftar dengan email lain → kode verifikasi harus
   sampai di email (cek folder spam) → masuk → Beranda menampilkan kartu digital.
3. **Tap**: buka tautan kartu digital (menu *Bagikan*) dari ponsel → bio tampil → di Beranda/analitik tap bertambah
   (menandakan worker antrean berjalan).
4. **Pembayaran**: di *Paket & tagihan* tertulis "Pembayaran aman lewat QRIS". Lakukan satu checkout uji
   (pakai *sandbox* dulu bila tersedia) → halaman bayar terbuka → setelah bayar, invoice berubah **Lunas**.

---

## 12. Webhook pembayaran

Di dashboard pembayaran Sumopod, daftarkan URL webhook:

```
https://tapcard.id/webhooks/payment
```

Isi `PAYMENT_WEBHOOK_SECRET` (`whsec_…`) di `.env` sesuai yang diberikan dashboard, lalu muat ulang konfigurasi:

```bash
cd $APP/current && as_app php artisan config:cache && systemctl reload php8.3-fpm
```

Webhook bukan satu-satunya jalur konfirmasi: aplikasi juga menanyakan status ke API saat pengguna kembali dari
halaman bayar, dan mencocokkan invoice terbuka tiap 5 menit (`tapcard:payments-reconcile`).

---

## 13. Rilis berikutnya & rollback

Setelah ada perubahan di branch `main`:

```bash
sudo bash /var/www/tapcard/current/deploy/deploy.sh
```

Skrip ini mengulang langkah 7 secara otomatis di folder rilis baru, menjalankan migrasi, lalu memindahkan `current`
**hanya bila semua langkah berhasil** — rilis yang gagal tidak mengganggu versi yang sedang berjalan.

Kembali ke rilis sebelumnya:

```bash
sudo bash /var/www/tapcard/current/deploy/rollback.sh
```

Setelah mengubah `.env`: jalankan rilis ulang (cara paling aman), atau cukup
`cd /var/www/tapcard/current && as_app php artisan config:cache && systemctl reload php8.3-fpm`.

Bersihkan salinan repo sementara bila sudah tidak dipakai: `rm -rf /root/tapcard-src`.

---

## 14. Pemecahan masalah

| Gejala | Periksa |
|---|---|
| `502 Bad Gateway` | `systemctl status php8.3-fpm`; socket ada di `/run/php/tapcard.sock`? |
| Error 500 | `tail -50 /var/www/tapcard/shared/storage/logs/laravel-$(date +%F).log` |
| `could not connect to server` (DB) | `.env`: `DB_PORT=6432`, `DB_SSLMODE=require`; IP VPS diizinkan di Sumopod? |
| `prepared statement … does not exist` | `DB_EMULATE_PREPARES=true` lalu `config:cache` |
| Kode daftar / lupa password tidak sampai | `as_app php artisan tapcard:mail-test email@anda.com`; domain `MAIL_FROM_ADDRESS` terverifikasi (SPF/DKIM)? |
| Tap / notifikasi tidak tercatat | `supervisorctl status`; `tail /var/log/tapcard/queue.log` |
| "Pembayaran online sedang disiapkan" | `PAYMENT_DRIVER=link`, `PAYMENT_API_URL` & `PAYMENT_API_KEY` terisi, lalu `config:cache` |
| Perubahan `.env` tidak berlaku | konfigurasi di-cache: jalankan `config:cache` + reload PHP-FPM |
| `nginx: [emerg] unknown directive "http2"` | memakai config lama — salin ulang `deploy/nginx/tapcard.conf` terbaru |
| Sertifikat gagal (B) | DNS subdomain sudah mengarah ke VPS? Port 80 terbuka? |

## 15. Setelah live

- **Ganti password database & SMTP Sumopod** (pernah dibagikan di luar server), lalu perbarui `.env` + `config:cache`.
- Ganti `PAYMENT_API_URL` dari *sandbox* ke URL produksi setelah uji pembayaran berhasil.
- Pastikan backup database aktif di dashboard Sumopod.
- Simpan `/var/www/tapcard/shared/.env` di tempat aman (password manager) — tanpa `APP_KEY` yang sama, data terenkripsi tidak bisa dibaca.
