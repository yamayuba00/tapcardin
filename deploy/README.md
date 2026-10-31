# Deploy TapCard ke VPS

Satu perintah memasang server kosong sampai aplikasi online: Nginx, PHP 8.3-FPM, Composer, Node 22,
Supervisor (worker antrean + scheduler), sertifikat SSL, firewall, rilis pertama, data awal, dan akun Pemilik Platform.

**Kebutuhan:** VPS Ubuntu **22.04 atau 24.04** (min. 2 GB RAM), akses root, domain yang DNS-nya bisa Anda atur,
database PostgreSQL (terkelola, mis. Sumopod — atau dipasang otomatis di VPS), dan akun SMTP untuk email.

## 1. Arahkan DNS ke VPS

| Tipe | Nama | Nilai |
|---|---|---|
| A | `@` (domain utama) | IP VPS |
| A | `*` (semua subdomain: app, tap, panel, workspace perusahaan) | IP VPS |

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
Penjelasan setiap isian ada di [`install.env.example`](install.env.example). File ini berisi rahasia — hanya simpan di server.

**Pilihan SSL (`SSL_MODE`)**

| Mode | Sertifikat | Kapan dipakai |
|---|---|---|
| `cloudflare` (disarankan) | Wildcard `*.domain` lewat DNS Cloudflare — isi `CLOUDFLARE_API_TOKEN` (izin *Zone → DNS → Edit*) | Workspace perusahaan (`{slug}.domain`) ikut aman |
| `http` | `domain`, `www`, `app`, `tap`, `panel` | DNS bukan di Cloudflare; subdomain perusahaan belum tercakup |
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
| Ubah konfigurasi (SMTP, Midtrans, Google…) | edit `/var/www/tapcard/shared/.env`, lalu jalankan rilis ulang |
| Status worker & scheduler | `sudo supervisorctl status` |
| Log aplikasi | `/var/www/tapcard/shared/storage/logs/` |
| Log worker / scheduler | `/var/log/tapcard/queue.log`, `/var/log/tapcard/schedule.log` |
| Uji email | `sudo -u www-data php /var/www/tapcard/current/artisan tapcard:mail-test email@anda.com` |

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
| Nginx | [`nginx/tapcard.conf`](nginx/tapcard.conf) — satu server block untuk domain & semua subdomain, HTTP→HTTPS, aset ber-hash di-cache setahun, SSE notifikasi tanpa buffering. Kompatibel Nginx 1.18–1.27 |
| PHP-FPM | [`php/fpm-pools.conf`](php/fpm-pools.conf) — pool utama (jumlah worker dihitung dari RAM) + pool terpisah untuk notifikasi realtime; [`php/opcache-production.ini`](php/opcache-production.ini) |
| Supervisor | [`supervisor/tapcard.conf`](supervisor/tapcard.conf) — 2 worker antrean (`taps`, `notify`, `leads`, `default`, `render`) + scheduler |
| Scheduler | pengingat follow-up (tiap menit), statistik kartu (15 menit), tagihan & masa tenggang langganan (01.00 WIB), partisi & pembersihan data (harian) |
| SSL | Certbot, perpanjang otomatis (timer sistem) + reload Nginx |
| Keamanan | UFW: hanya SSH, 80, 443 terbuka · `.env` tidak terbaca publik · PHP selain `index.php` tidak dieksekusi |
| Backup | `DB_MODE=local`: `pg_dump` harian ke `/var/backups/tapcard` (14 hari). Salin juga ke luar server. Database terkelola memakai backup penyedianya |
| Log | rotasi mingguan untuk log worker & PHP-FPM; log Laravel harian (14 hari) |

## Kapan pindah ke Redis

Default `database` untuk cache/antrean/sesi sudah cukup untuk awal. Ganti ke Redis
(`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis` di `shared/.env`, lalu rilis ulang) bila:

- tap > ~20/detik pada jam sibuk, atau tabel `jobs` sering > 1.000 baris menunggu;
- p95 resolver `/c/{token}` > 300 ms padahal query kartu sudah ter-cache;
- pengguna notifikasi serentak > ~150 (sekalian pindahkan notifikasi realtime ke Reverb/WebSocket).

## Domain kustom pelanggan

Domain kustom (`kartu.perusahaan.com`) yang sudah diverifikasi di panel diarahkan pelanggan ke `app.domain` (CNAME).
Server block di atas hanya melayani `domain` & `*.domain`; untuk domain kustom tambahkan server block per domain dengan
sertifikatnya sendiri (`certbot certonly --webroot -w /var/www/letsencrypt -d kartu.perusahaan.com`), atau pakai proxy
dengan TLS on-demand (mis. Caddy) di depan Nginx.

## Pemecahan masalah

| Gejala | Periksa |
|---|---|
| `502 Bad Gateway` | `systemctl status php8.3-fpm`, `/var/log/php-fpm/` |
| Halaman error 500 | `tail -f /var/www/tapcard/shared/storage/logs/laravel-*.log` |
| Kode daftar/lupa password tidak sampai | `tapcard:mail-test` (lihat tabel di atas); pastikan domain pengirim terverifikasi di penyedia SMTP |
| Tap/notifikasi tidak tercatat | `sudo supervisorctl status` — worker `tapcard-queue` harus `RUNNING` |
| Sertifikat gagal (`http`) | DNS subdomain harus sudah mengarah ke VPS dan port 80 terbuka |
| Sertifikat gagal (`cloudflare`) | token harus berizin *Zone → DNS → Edit* untuk domain tersebut |
