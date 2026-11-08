# Deploy TapCard ke VPS

Satu perintah memasang server kosong sampai aplikasi online: Nginx, PHP 8.3-FPM, Composer, Node 22,
Supervisor (worker antrean + scheduler + Reverb realtime), sertifikat SSL, firewall, rilis pertama, data awal, dan akun Pemilik Platform.

**Kebutuhan:** VPS Ubuntu **22.04 atau 24.04** (min. 2 GB RAM), akses root, domain yang DNS-nya bisa Anda atur,
database PostgreSQL (terkelola, mis. Sumopod — atau dipasang otomatis di VPS), dan akun SMTP untuk email.

## 1. Arahkan DNS ke VPS

| Tipe | Nama | Nilai |
|---|---|---|
| A | `@` (domain utama) | IP VPS |
| A | `*` (semua subdomain: app, tap, panel, ws, workspace perusahaan) | IP VPS |

Di Cloudflare, set kedua record ke **DNS only** (awan abu-abu) saat instalasi.

## 2. Isi konfigurasi di server

```bash
ssh root@IP-VPS
# repo publik: unduh langsung
curl -fsSLO https://raw.githubusercontent.com/<user>/<repo>/main/deploy/install.sh
curl -fsSLO https://raw.githubusercontent.com/<user>/<repo>/main/deploy/install.env.example
# repo privat: kirim dari laptop (jalankan di laptop, di folder proyek)
#   scp deploy/install.sh deploy/install.env.example root@IP-VPS:/root/
cp install.env.example /root/tapcard-install.env && nano /root/tapcard-install.env
```

Yang wajib diisi: `DOMAIN`, `REPO_URL`, `ADMIN_EMAIL`, database (`DB_*`), dan SMTP (`MAIL_*`).
Pembayaran (`PAYMENT_*`) boleh diisi belakangan — sampai diisi, halaman paket menampilkan "pembayaran online sedang disiapkan".
Penjelasan setiap isian ada di [`install.env.example`](install.env.example). File ini berisi rahasia — hanya simpan di server.

**Pilihan SSL (`SSL_MODE`)**

| Mode | Sertifikat | Kapan dipakai |
|---|---|---|
| `cloudflare` (disarankan) | Wildcard `*.domain` lewat DNS Cloudflare — isi `CLOUDFLARE_API_TOKEN` (izin *Zone → DNS → Edit*) | Workspace perusahaan (`{slug}.domain`) ikut aman |
| `http` | `domain`, `www`, `app`, `tap`, `panel`, `ws` | DNS bukan di Cloudflare; subdomain perusahaan belum tercakup |
| `none` | Self-signed 30 hari | Uji coba saja |

## 3. Jalankan

```bash
sudo bash install.sh /root/tapcard-install.env
```

Repo privat dengan URL SSH (`git@github.com:…`): installer membuat *deploy key*, menampilkannya, lalu menunggu Anda
menambahkannya di **GitHub → repo → Settings → Deploy keys** (read-only), kemudian lanjut otomatis.

Di akhir, installer memeriksa ketiga alamat utama dan koneksi SMTP, lalu mencetak:

```
   Aplikasi         https://app.domain
   Panel pemilik    https://panel.domain
   Login panel      email-anda
   Password         ••••••••   ← simpan, lalu ganti di panel → Akun & password
```

Installer aman dijalankan ulang (mis. setelah memperbaiki isian): langkah yang sudah selesai dilewati dan `.env` tidak ditimpa.

## Sehari-hari

| Tugas | Perintah |
|---|---|
| Rilis versi terbaru dari `BRANCH` | `sudo bash /var/www/tapcard/current/deploy/deploy.sh` |
| Rilis tag / branch tertentu | `sudo bash /var/www/tapcard/current/deploy/deploy.sh v1.2.0` |
| Kembali ke rilis sebelumnya | `sudo bash /var/www/tapcard/current/deploy/rollback.sh` |
| Pindah ke domain baru (kartu lama tetap jalan) | `sudo bash /var/www/tapcard/current/deploy/switch-domain.sh domainbaru.com` — lihat [Pindah domain](#pindah-domain) |
| Ubah konfigurasi (SMTP, pembayaran, Google…) | edit `/var/www/tapcard/shared/.env`, lalu jalankan rilis ulang |
| Status worker & scheduler | `sudo supervisorctl status` |
| Pindahkan antrean & cache ke Redis | `sudo bash /var/www/tapcard/current/deploy/enable-redis.sh` — lihat [Kapan pindah ke Redis](#kapan-pindah-ke-redis) |
| Log aplikasi | `/var/www/tapcard/shared/storage/logs/` |
| Log worker / scheduler | `/var/log/tapcard/queue.log`, `/var/log/tapcard/schedule.log` |
| Uji email | `sudo -u www-data php /var/www/tapcard/current/artisan tapcard:mail-test email@anda.com` |
| Cocokkan pembayaran dengan gateway (otomatis tiap 5 menit) | `sudo -u www-data php /var/www/tapcard/current/artisan tapcard:payments-reconcile` |
| Cek kesehatan sistem (sama dengan panel → Sistem) | `sudo -u www-data php /var/www/tapcard/current/artisan tapcard:health` |
| Backup database sekarang (otomatis 02.15 WIB) | `sudo -u www-data php /var/www/tapcard/current/artisan tapcard:backup` — lihat [Backup & alarm](#backup--alarm) |

Rilis tanpa downtime: kode baru di-build di `releases/<waktu>`, dan `current` baru dipindah setelah dependensi, build aset,
migrasi, dan cache berhasil. Rilis yang gagal di tengah jalan tidak menyentuh versi yang sedang berjalan. Lima rilis
terakhir disimpan untuk rollback. Migrasi TapCard hanya menambah tabel/kolom, jadi rollback kode tetap aman.

## Struktur di server

```
/var/www/tapcard/
├── current → releases/20261101093000      versi aktif (root Nginx: current/public)
├── releases/                              5 rilis terakhir
└── shared/
    ├── .env                               konfigurasi produksi (root:www-data, 640)
    ├── storage/                           unggahan, log, cache — bertahan antar-rilis
    └── deploy.conf                        repo, branch, deploy key
```

## Yang dipasang & dijadwalkan

| Komponen | Detail |
|---|---|
| Nginx | [`nginx/tapcard.conf`](nginx/tapcard.conf) — satu server block untuk domain & semua subdomain, HTTP→HTTPS, aset ber-hash di-cache setahun, SSE notifikasi tanpa buffering, `ws.<domain>` diteruskan ke Reverb (WebSocket). Kompatibel Nginx 1.18–1.27 |
| PHP-FPM | [`php/fpm-pools.conf`](php/fpm-pools.conf) — pool utama (jumlah worker dihitung dari RAM) + pool terpisah untuk notifikasi realtime; [`php/opcache-production.ini`](php/opcache-production.ini) |
| Supervisor | [`supervisor/tapcard.conf`](supervisor/tapcard.conf) — 2 worker antrean (`taps`, `notify`, `leads`, `default`, `render`) + scheduler + `tapcard-reverb` (WebSocket realtime di 127.0.0.1:8080) |
| Scheduler | pengingat follow-up & invoice kedaluwarsa (tiap menit), alarm & cek pembayaran (5 menit), statistik kartu (15 menit), pesanan belum dibayar: pengingat lalu batal setelah 3 hari (tiap jam), tagihan & masa tenggang langganan (01.00 WIB), backup (02.15 WIB), partisi & pembersihan data (harian) |
| SSL | Certbot, perpanjang otomatis (timer sistem) + reload Nginx |
| Keamanan | UFW: hanya SSH, 80, 443 terbuka · `.env` tidak terbaca publik · PHP selain `index.php` tidak dieksekusi |
| Backup | `tapcard:backup` harian 02.15 WIB → S3 `backups/` (privat, 30 hari) bila `BACKUP_DISK` diisi. `DB_MODE=local` juga menyimpan salinan harian di `/var/backups/tapcard` (14 hari) |
| Alarm | `tapcard:alert` tiap 5 menit → email ke Pemilik Platform / `ALERT_EMAILS` bila `tapcard:health` gagal, pengingat tiap 6 jam, dan email "pulih" |
| Log | rotasi mingguan untuk log worker & PHP-FPM; log Laravel harian (14 hari) |

## Backup & alarm

**Backup ke luar server.** Isi di `shared/.env` lalu rilis ulang:

```env
BACKUP_DISK=backups      # bucket S3 yang sama (AWS_*), folder backups/ — tidak terbaca publik
BACKUP_KEEP_DAYS=30
BACKUP_DB_PORT=          # isi port langsung PostgreSQL bila DB_PORT = PgBouncer (6432)
```

`pg_dump` harus versi ≥ versi server database. Cek versi server dengan
`sudo -u www-data php /var/www/tapcard/current/artisan db:show`, versi klien dengan `pg_dump --version`. Bila klien lebih lama:

```bash
sudo apt-get install -y postgresql-common && sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
sudo apt-get install -y postgresql-client-17     # samakan dengan versi server
```

Uji sekali: `sudo -u www-data php /var/www/tapcard/current/artisan tapcard:backup`. Memulihkan: unduh file `.dump`
dari bucket (folder `backups/`), lalu `pg_restore --clean --no-owner -d <database> tapcard-YYYYMMDD-HHMMSS.dump`.

**Alarm email.** Aktif otomatis: tiap 5 menit `tapcard:alert` menjalankan pemeriksaan `tapcard:health` dan mengirim
email ke semua staf Pemilik Platform bila ada yang gagal (database mati, antrean macet, job gagal, error server melonjak,
disk hampir penuh, Redis penuh, backup > 26 jam). Masalah sesaat harus muncul 2x berturut-turut dulu; database mati
langsung dikabari. Isi `ALERT_EMAILS=email1,email2` agar alarm tetap terkirim saat database mati. Saran kapasitas
("pasang Redis") tidak memicu alarm.

Alarm berjalan dari scheduler di server — bila VPS-nya sendiri mati, tidak ada yang mengirim. Tambahkan pemantau
dari luar (mis. UptimeRobot gratis) ke `https://app.<domain>/up`.

## Pembayaran (payment service)

Gateway pembayaran tidak terikat penyedia tertentu: semua diatur lewat `PAYMENT_*` di `shared/.env`
(penjelasan lengkap di [`config/payment.php`](../config/payment.php)).

| Isian | Contoh | Fungsi |
|---|---|---|
| `PAYMENT_DRIVER` | `link` | `link` = gateway payment link via REST; `simulator` hanya untuk lokal |
| `PAYMENT_GATEWAY_NAME` / `PAYMENT_GATEWAY_CODE` | `QRIS` / `qris` | nama yang dilihat pengguna / kode penyedia di invoice |
| `PAYMENT_API_URL`, `PAYMENT_API_KEY`, `PAYMENT_API_KEY_HEADER` | `https://…/api/v1`, `…`, `X-Api-Key` | alamat & kunci API gateway |
| `PAYMENT_CREATE_PATH`, `PAYMENT_STATUS_PATH` | `/payments`, `/payments/{id}` | endpoint buat pembayaran & cek status |
| `PAYMENT_METHOD`, `PAYMENT_METHOD_LABEL` | `QRIS`, `QRIS — semua e-wallet…` | metode yang diminta & teks untuk pengguna |
| `PAYMENT_WEBHOOK_VERIFY` + `PAYMENT_WEBHOOK_SECRET` / `…_TOKEN` | `signature` + `whsec_…` | verifikasi webhook (`signature`, `token`, atau `none`) |

Daftarkan URL webhook **`https://domain/webhooks/payment`** di dashboard gateway. Pembayaran dikonfirmasi tiga jalur
sekaligus — webhook bertanda tangan sah, cek status ke API saat pengguna kembali dari halaman bayar, dan pencocokan
otomatis tiap 5 menit — jadi pembayaran tetap tercatat walau webhook terlambat. Webhook yang tanda tangannya tidak sah
tidak pernah melunasi invoice; statusnya selalu ditanyakan ulang ke API gateway. Ganti gateway = ubah `PAYMENT_*`
lalu rilis ulang; bila bentuk API-nya berbeda, tambah satu kelas di `app/Services/Payment/Gateways/`.

## Kapan pindah ke Redis

Default `database` (PostgreSQL) untuk antrean, cache, dan sesi sudah cukup untuk awal. Pindahkan **antrean & cache**
ke Redis dengan satu perintah — kapan saja, tanpa downtime:

```bash
sudo bash /var/www/tapcard/current/deploy/enable-redis.sh                    # pasang & pindah (memori maks 256 MB)
sudo bash /var/www/tapcard/current/deploy/enable-redis.sh --maxmemory 512mb  # ubah batas memori
sudo bash /var/www/tapcard/current/deploy/enable-redis.sh --off              # kembali ke database
```

Untuk server baru: isi `REDIS=yes` di `tapcard-install.env`. Script memasang `redis-server` dari repositori Ubuntu
(lisensi BSD, gratis) + `php-redis`, lalu mengatur: hanya mendengar di `127.0.0.1` dengan password acak (port 6379
tidak dibuka), simpan ke disk tiap detik (AOF — Redis/VPS restart, job paling banyak hilang 1 detik), batas memori
dengan `volatile-lru` (hanya cache yang dibuang saat penuh, job antrean tidak pernah). Koneksi diuji dari Laravel
sebelum dipakai; gagal = `.env` dikembalikan. Job yang menunggu di tabel `jobs` tetap dikerjakan sampai habis.

**Sesi login sengaja tetap di database**: "blokir pengguna" dan "keluarkan dari semua perangkat" di panel membaca
tabel `sessions` — di Redis kedua fitur itu berhenti bekerja.

Saatnya pindah (panel → Sistem / `tapcard:health` memberi tanda) bila:

- tap > ~20/detik pada jam sibuk, atau job menunggu sering > 1.000;
- umur job tertua rutin > 1 menit;
- p95 resolver `/c/{token}` > 300 ms padahal query kartu sudah ter-cache.

## Domain kustom pelanggan

Domain kustom (`kartu.perusahaan.com`) yang sudah diverifikasi di panel diarahkan pelanggan ke `app.domain` (CNAME).
Server block di atas hanya melayani `domain` & `*.domain`; untuk domain kustom tambahkan server block per domain dengan
sertifikatnya sendiri (`certbot certonly --webroot -w /var/www/letsencrypt -d kartu.perusahaan.com`), atau pakai proxy
dengan TLS on-demand (mis. Caddy) di depan Nginx.

## Pindah domain

```bash
sudo bash /var/www/tapcard/current/deploy/switch-domain.sh domainbaru.com --dry-run   # lihat rencana & cek DNS dulu
sudo bash /var/www/tapcard/current/deploy/switch-domain.sh domainbaru.com             # jalankan
```

Chip NFC & QR berisi alamat tetap `https://tap.<domain-lama>/c/…` yang tidak bisa diubah setelah dicetak. Karena itu
domain lama **tidak dimatikan**: semua alamatnya dialihkan permanen (308, path & query tetap) ke domain baru —
`tap.lama.com/c/AbC…` → `tap.baru.com/c/AbC…`, `{slug}.lama.com` → `{slug}.baru.com`. **Domain lama harus tetap
diperpanjang dan tetap mengarah ke VPS** selama kartu lama masih beredar.

Sebelum menjalankan: buat record A `domainbaru.com` dan `*.domainbaru.com` ke IP VPS. Untuk sertifikat wildcard
(`--ssl cloudflare`, bawaan bila dulu memasang dengan Cloudflare), token harus berizin untuk zona domain baru —
token lain: `CLOUDFLARE_API_TOKEN=… sudo -E bash …/switch-domain.sh domainbaru.com`.

Yang dikerjakan script: sertifikat domain baru → Nginx domain baru + pengalihan domain lama (diuji `nginx -t`, gagal =
dikembalikan) → `.env` (`APP_URL`, `TAPCARD_BASE_DOMAIN`, `TAPCARD_RESOLVER_URL`, `REVERB_HOST`, `GOOGLE_REDIRECT_URI`;
cadangan di `shared/domain-switch-<waktu>/`) → cache & restart → pemeriksaan. Pindah berkali-kali tetap satu langkah
pengalihan (semua domain lama langsung ke domain terbaru); kembali ke domain lama = jalankan lagi dengan domain lama.

Setelahnya, ubah manual: redirect URI Google OAuth, URL webhook di dashboard payment gateway, verifikasi domain pengirim
di penyedia SMTP (`MAIL_FROM_ADDRESS` tidak diubah otomatis), dan CNAME domain kustom pelanggan ke `app.domainbaru.com`.
Pengguna perlu login ulang dan memasang ulang aplikasi PWA.

## Pemecahan masalah

| Gejala | Periksa |
|---|---|
| `502 Bad Gateway` | `systemctl status php8.3-fpm`, `/var/log/php-fpm/` |
| Halaman error 500 | `tail -f /var/www/tapcard/shared/storage/logs/laravel-*.log` |
| Kode daftar/lupa password tidak sampai | `tapcard:mail-test` (lihat tabel di atas); pastikan domain pengirim terverifikasi di penyedia SMTP |
| Tap/notifikasi tidak tercatat | `sudo supervisorctl status` — worker `tapcard-queue` harus `RUNNING` |
| Notifikasi tidak langsung (status bukan "langsung (realtime)") | `sudo supervisorctl status tapcard-reverb` harus `RUNNING`; DNS `ws.<domain>` mengarah ke VPS; `REVERB_*` di `.env` lalu `php artisan config:cache`. Tanpa Reverb aplikasi otomatis kembali ke SSE |
| Sertifikat gagal (`http`) | DNS subdomain harus sudah mengarah ke VPS dan port 80 terbuka |
| Sertifikat gagal (`cloudflare`) | token harus berizin *Zone → DNS → Edit* untuk domain tersebut |
