# Deploy produksi TapCard

`php artisan serve` hanya untuk lokal: di Windows ia satu-thread, jadi satu request lambat (mis. SSE) menahan
semua request lain. Produksi memakai **Nginx + PHP-FPM**, worker antrean via Supervisor, dan cache Laravel.

| File | Tujuan di server |
|---|---|
| `nginx/tapcard.conf` | `/etc/nginx/sites-available/tapcard.conf` (+ symlink ke `sites-enabled`) |
| `php/fpm-pools.conf` | `/etc/php/8.3/fpm/pool.d/tapcard.conf` — pool utama + pool SSE terpisah |
| `php/opcache-production.ini` | `/etc/php/8.3/fpm/conf.d/99-tapcard-opcache.ini` |
| `supervisor/tapcard.conf` | `/etc/supervisor/conf.d/tapcard.conf` — 2 worker antrean + scheduler |
| `deploy.sh` | skrip rilis (releases/ + symlink `current`, cache, reload FPM, `queue:restart`) |
| `../.env.production.example` | salin ke `/var/www/tapcard/shared/.env` lalu isi rahasia |

## Persiapan sekali

1. DNS: `A tapcard.id` dan `A *.tapcard.id` ke server. Sertifikat wildcard butuh tantangan DNS-01.
2. PHP 8.3 + ekstensi `pgsql`, `intl`, `gd`, `mbstring`, `bcmath`, `opcache` (dan `redis` bila memakai Redis).
3. `mkdir -p /var/www/tapcard/{releases,shared/storage}`; salin `storage/` kosong ke `shared/`, isi `shared/.env`,
   `php artisan key:generate` sekali.
4. Seed akun pemilik platform: `php artisan db:seed --class=PlatformOwnerSeeder --force`.
   **Jangan** menjalankan `DummyAccountSeeder`/`DemoSeeder` di produksi, dan biarkan `DEV_LOGIN_CODE` kosong
   (kode login dev hanya aktif saat `APP_ENV=local`).

## Kapan pindah ke Redis

Default `database` untuk cache/antrean/sesi sudah cukup untuk awal. Ganti ke Redis
(`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`) bila salah satu terjadi:

- tap > ~20/detik pada jam sibuk, atau tabel `jobs` sering > 1.000 baris menunggu;
- p95 resolver `/c/{token}` > 300 ms padahal query kartu sudah ter-cache;
- pengguna notifikasi serentak > ~150 (sekalian pindah SSE ke Reverb/WebSocket).

Setelah ganti driver: `php artisan config:cache` dan `supervisorctl restart tapcard-queue:*`.
