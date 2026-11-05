# TapCard

**Kartu nama NFC & digital dengan bio link, tukar kontak, dan pengelolaan lead — dalam satu platform.**

Satu tap ke ponsel (atau scan QR) membuka profil pemilik kartu: orang yang Anda temui bisa menyimpan kontak dengan
satu ketukan dan berbagi kontaknya balik. Setiap pertemuan tercatat sebagai koneksi yang bisa ditindaklanjuti.
Isi kartu bisa diubah kapan saja tanpa cetak ulang, dan pengguna bisa langsung mulai dengan **kartu digital gratis**
sebelum kartu fisiknya tiba.

---

## Fitur

### Untuk pemilik kartu
- **Kartu fisik NFC + QR** — aktivasi sekali (oleh pembeli atau dengan kode 8 karakter di kemasan); jeda, laporkan
  hilang (tap berikutnya hanya melihat halaman netral & pemilik diberi tahu lokasinya), minta kartu pengganti.
- **Kartu digital** — dibuat otomatis saat daftar: desain sama seperti kartu fisik, QR asli di sisi belakang, bisa
  **diunduh sebagai gambar**, dibagikan lewat QR layar penuh, tautan pendek `tap…/@nama`, atau WhatsApp.
- **Bio link per kartu** — setiap kartu punya halaman sendiri: 8 tema, 15 warna aksen, tata letak & gaya tombol,
  45 jenis kontak (WhatsApp, sosial media, toko online, rekening bank, QRIS, e-wallet), **Simpan Kontak** (vCard).
  Halaman disusun dari **blok** (simpan kontak, tombol, teks, video YouTube/TikTok/Vimeo/Spotify, pemisah, kontak) dan
  satu kartu bisa punya **3 bio link** (mis. Kerja / Pameran / Personal) — hanya satu yang aktif saat kartu di-tap.
- **Tukar Kontak & koneksi** — lawan bicara mengirim kontaknya lewat formulir dengan persetujuan tercatat; koneksi
  punya tahap pipeline, catatan, tag, pengingat follow-up, impor/ekspor CSV, dan dashboard lead yang bisa diatur.
- **Mode Event & Mode Reader** — tandai koneksi per acara; baca kartu orang lain lewat NFC, kamera QR, atau kode.
- **Analitik** — jumlah tap, pengunjung unik, simpan kontak, sumber (NFC / QR / tautan), kota, tautan terpopuler.
- **Notifikasi realtime** & aplikasi yang bisa **dipasang di layar utama** (PWA) dengan pintasan "Bagikan kartu".
- **Panduan awal** — checklist kelengkapan bio, @nama, berbagi kartu, dan kartu fisik di Beranda.

### Design Studio
- **Editor kartu** — mulai dari kartu kosong, 8 template, atau template sendiri; teks, bentuk, ikon, gambar & logo,
  pilih banyak, grup, ratakan, putar, kunci, lapisan, undo/redo, grid & snap, pratinjau cetak + unduh PNG.
- **Editor lanyard** — tali 15/20/25 mm × 90 cm, desain satu pola yang diulang, cetak 1 atau 2 sisi, pilihan pengait,
  harga langsung dari katalog, dan pratinjau kartu menggantung di tali.
- **Editor merchandise** — jaket, kaos/baju, tote bag, mug, dan topi: area cetak sesuai produk (depan/belakang),
  warna bahan, dan pratinjau siluet produk. Pemesanan cetak merchandise segera hadir.
- **Brand kit bersama** — warna, logo, nama merek, tagline dipakai kartu & lanyard sekaligus; elemen bisa disalin dua arah.
- **Cek siap cetak otomatis** — batas aman, area potong, ukuran teks & QR, area chip NFC dan lubang lanyard.
  QR, ikon NFC, dan logo TapCard selalu dibangun ulang server sehingga tidak bisa rusak.

### Pemesanan kartu fisik & lanyard
- **Pesan dari desain sendiri** — pilih desain yang lolos cek cetak, jenis kartu (PVC NFC / NFC Secure) dan jumlah,
  lanyard (lebar, sisi cetak, pengait mengikuti desain), metode kirim & alamat; harga dihitung server dari katalog.
- **Bayar lewat payment service yang sama** dengan langganan (saat ini QRIS); status dikonfirmasi otomatis.
- **Langsung ke produksi** — panel → Pesanan → *Mulai produksi* membuat batch per item kartu yang tertaut ke pemesan;
  token, encode NFC, QC, lalu *Kirim* dengan resi. Kartu yang tiba bisa diaktifkan pemesan tanpa kode.
- **Desain dibekukan** saat dipesan, sehingga yang dicetak sama dengan yang dibayar; pelanggan memantau status & resi.

### Untuk bisnis
- **Workspace perusahaan** di subdomain sendiri atau **domain kustom** (verifikasi DNS), anggota, peran & izin.
- **Paket & langganan** — bulanan/tahunan, checkout lewat **payment service** (saat ini QRIS; gateway diganti cukup lewat env), voucher, invoice & kuitansi, pengingat
  perpanjangan, masa tenggang, dan turun ke paket gratis otomatis.
- **Add-on** berbayar terpisah — Integrasi CRM (Google Sheets, HubSpot) *segera hadir*, dengan pendaftaran minat.

### Panel pemilik (`panel.`)
Operasional (workspace, domain kustom, pengguna, kartu), **produksi** (pesanan, batch, encode NFC, quality control, kirim),
**bisnis** (paket & harga, keuangan & MRR, voucher, katalog produk kartu, ongkir, lanyard & jenis kontak, pengumuman), dan sistem
(staf & peran, audit log, kesehatan sistem). Menu mengikuti izin setiap staf.

### Keamanan & privasi
Pengguna masuk dengan **Google** (akun dibuat otomatis) atau **kartu NFC + kode akun** 6 angka — bukti kartu dari Web NFC
(UID chip, Chrome Android) atau kunci chip di URL NFC (tap di HP mana pun, termasuk iPhone; tidak ada di QR); kode salah 5×
mengunci 15 menit. **Aktivasi kartu** (`panel.…/aktivasi-kartu`): staf mengaktifkan kartu untuk pelanggan, kode akun dibuat acak, dan
pembayaran ditandai QRIS / manual.
Anti-bot **ALTCHA** (tanpa layanan pihak ketiga), "ingat saya" 7 hari; panel staf terpisah dengan email + password; isolasi data antar-workspace di level query; IP pengunjung hanya disimpan
sebagai hash; CSP ber-nonce; bio tidak diindeks mesin pencari kecuali diizinkan; ekspor & hapus akun mandiri.

---

## Cara kerja

```
 Daftar ──▶ kartu digital aktif ──▶ lengkapi bio link ──▶ bagikan (QR / @nama / WhatsApp)
                                                               │
 Pesan kartu fisik ──▶ bayar ──▶ produksi (batch · encode · QC) ──▶ kirim ──▶ aktivasi ──▶ tap NFC / scan QR
                                                               │
                         pengunjung: lihat bio · Simpan Kontak · Tukar Kontak
                                                               │
                         pemilik: notifikasi · koneksi & pipeline · follow-up · analitik
```

| Alamat | Fungsi |
|---|---|
| `domain` | Landing page: nilai produk, template, demo editor & bio link, harga, FAQ |
| `app.domain` | Aplikasi pengguna: daftar/masuk, kartu, bio link, koneksi, desain, paket |
| `{slug}.domain` / domain kustom | Workspace perusahaan |
| `tap.domain/c/{token}` | Alamat permanen yang tercetak di chip & QR kartu; `/@nama` tautan pendek |
| `panel.domain` | Panel pemilik & produksi (sesi terpisah, khusus staf) |

---

## Teknologi

| Lapisan | Pilihan |
|---|---|
| Backend | Laravel 13 (PHP 8.3) sebagai **API JSON** — PostgreSQL 16 untuk data, cache, antrean, dan sesi |
| Frontend | **Vue 3 SPA** (vue-router, Pinia) + Tailwind CSS v4 + ikon lucide; satu-satunya Blade adalah shell kosong & template email |
| Dibuat sendiri | Encoder QR, NDEF (NFC), vCard, verifikasi ALTCHA, mesin izin & multi-tenant |
| Pembayaran | `app/Services/Payment` — `PaymentService` + kontrak `PaymentGateway`; gateway aktif & semua alamatnya dari env `PAYMENT_*` ([`config/payment.php`](config/payment.php)) |
| Produksi | Nginx + PHP-FPM, Supervisor, Let's Encrypt — instalasi sekali jalan di [`deploy/`](deploy/README.md) |

Prinsip data: setiap entitas punya relasi (foreign key), index, dan constraint `CHECK`; ID publik berupa ULID
(`public_id`), ID numerik tidak pernah muncul di URL. Desain mengikuti token tertutup di `resources/css/tokens.css`
(tanpa gradien/bayangan, tanpa warna hex di luar token) — diperiksa oleh `scripts/check-design.sh`.

---

## Menjalankan di komputer lokal

Prasyarat: PHP 8.3 (`pdo_pgsql`, `gd` + WebP, `intl`, `bcmath`), Composer, Node 22, PostgreSQL 16.

```bash
# PostgreSQL lewat container (sekali saja)
podman run -d --name tapcard-pg -e POSTGRES_USER=tapcard -e POSTGRES_PASSWORD=secret \
  -e POSTGRES_DB=tapcard -p 5432:5432 docker.io/library/postgres:16-alpine
podman exec tapcard-pg psql -U tapcard -c "CREATE DATABASE tapcard_test"

composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed                          # peran & izin, paket, jenis kontak, Pemilik Platform
php artisan db:seed --class=DummyAccountSeeder      # akun demo + kartu & riwayat tap
php artisan storage:link
npm run build                                       # atau `npm run dev` untuk hot reload

php artisan serve --port=8000
php artisan queue:work --queue=taps,notify,leads,default,render   # wajib: tap & notifikasi lewat antrean
php artisan schedule:work                                          # pengingat, statistik, langganan
```

Host lokal memakai `*.localhost` (otomatis ke 127.0.0.1, tanpa edit file hosts): `localhost:8000` (landing),
`app.localhost:8000`, `tap.localhost:8000`, `panel.localhost:8000`.

**Akun demo** (di halaman login lokal ada tombol masuk satu klik; staf panel memakai password `password123`):

| Akun | Masuk di |
|---|---|
| `demo@tapcard.test`, `budi@tapcard.test` | `app.localhost:8000` |
| `admin@` (Pemilik Platform), `produksi@`, `support@`, `keuangan@tapcard.test` | `panel.localhost:8000` |

Mode lokal (`APP_ENV=local`, `DEV_LOGIN_CODE` diisi 6 digit): tombol akun dummy aktif di halaman login, pembayaran memakai halaman bayar simulasi, dan email ditulis ke `storage/logs` bila `MAIL_MAILER=log`.

## Pengujian

```bash
composer test                  # PHPUnit — selalu di database lokal tapcard_test (243 test)
npm test                       # unit test JavaScript
vendor/bin/pint --test         # format kode PHP
sh scripts/check-design.sh     # aturan desain & tombol
```

## Struktur proyek

```
app/
  Actions/        alur bisnis (aktivasi kartu, checkout, pembayaran, kartu digital, auth…)
  Authz/          katalog izin, peran, fitur paket
  Http/           controller API per konteks: Public (tap.), Workspace (app.), Platform (panel.), Auth
  Support/        QR, NDEF, vCard, ALTCHA, desain kartu (template, geometri, validasi), tabel server-side
database/         migrasi (relasi, index, CHECK) & seeder
resources/js/
  pages/          public · auth · workspace · admin · marketing  (<Halaman>/index.vue)
  components/     ui (dasar) · studio (editor) · cards · billing · marketing · admin · …
  api/ stores/ composables/ lib/ router/
resources/css/tokens.css   satu-satunya sumber warna & ukuran desain
deploy/           instalasi server sekali jalan, rilis, rollback, konfigurasi Nginx/PHP/Supervisor
tests/Feature/    test API & alur end-to-end
```

## Roadmap

| Status | Fitur |
|---|---|
| Berikutnya | Notifikasi email (pengingat tagihan bertahap, kuitansi, follow-up, kartu hilang di-tap) |
| Direncanakan | Add-on Integrasi CRM (Google Sheets, HubSpot), Apple / Google Wallet untuk kartu digital |
| Direncanakan | NTAG 424 DNA (tap anti-salin), SSL otomatis untuk domain kustom, Redis & WebSocket saat trafik tinggi |
