# TapCard

Kartu nama NFC + QR yang terhubung ke profil digital. Implementasi **fase MVP** dari dokumen di [`docs/`](docs/) (PRD §16).

Arsitektur:

- **Backend** — Laravel 13 (+ `laravel/socialite`) sebagai **API JSON saja** (`/api/*`), plus endpoint unduhan/redirect (`/c/{token}/vcard`, `/c/{token}/l/{id}`, `/c/{token}/qr.svg`). PostgreSQL 16 untuk data **dan** cache/queue/session (Redis menyusul, TRD §3.2). Encoder QR, vCard, NDEF dibuat sendiri di `app/Support`.
- **Frontend** — **SPA Vue 3** + vue-router + Pinia + **Tailwind CSS v4** + **lucide-vue-next**. Satu-satunya Blade adalah shell kosong `resources/views/app.blade.php` (dan template email). Rute dipilih dari host (`tap.`, `app.`/`{slug}.`, `admin.`, domain utama).
- **Desain** — palet tertutup DBD dipetakan ke tema Tailwind di `resources/css/tokens.css` (palet & bayangan bawaan Tailwind dimatikan). Pakai utilitas semantik: `bg-bg`, `bg-surface`, `text-fg`, `text-muted`, `border-line`, `bg-accent`, `text-ok`, …

### Struktur frontend (`resources/js`)

```
main.js                 bootstrap (Pinia, router, tema)
App/index.vue           root: RouterView + toast
config/                 konfigurasi runtime dari shell + deteksi host
router/                 index.js (guard) · routes/{public,workspace,admin,marketing}.js
api/                    client.js (fetch + XSRF) · auth · public · workspace · admin
stores/                 auth · toast · notifications (SSE)
composables/            useAsync · usePagedList · useTheme
lib/                    format · nfc · scanner · feedback (+ __tests__)
layouts/                WorkspaceLayout/index.vue · AdminLayout/index.vue
components/ui/          AppButton, AppInput, AppField, AppSelect, AppModal, AppTable, AppBadge, … (masing-masing <Nama>/index.vue)
components/common/      PageHeader, KpiCard, MiniChart, StageBadge, TapZone, BrandLogo, …
components/profile/     PublicProfileView (dipakai resolver & preview editor), ExchangeSheet
pages/public|auth|workspace|admin|marketing/<Halaman>/index.vue
```

## Menjalankan secara lokal

Prasyarat: PHP 8.3 (ekstensi `pdo_pgsql`, `gd` dengan WebP, `intl`, `bcmath`), Composer, Node LTS, PostgreSQL 16.

```bash
# PostgreSQL lewat container (sekali saja)
podman run -d --name tapcard-pg -e POSTGRES_USER=tapcard -e POSTGRES_PASSWORD=secret \
  -e POSTGRES_DB=tapcard -p 5432:5432 docker.io/library/postgres:16-alpine
podman exec tapcard-pg psql -U tapcard -c "CREATE DATABASE tapcard_test"

composer install && npm install
cp .env.example .env && php artisan key:generate   # isi TAPCARD_*_PEPPER dengan string acak
php artisan migrate --seed                          # izin, peran, paket, Pemilik Platform (SEED_PLATFORM_OWNER_EMAIL)
php artisan db:seed --class=DemoSeeder              # opsional: pengguna demo + kartu + riwayat tap
php artisan storage:link
npm run build                                       # atau `npm run dev` untuk HMR

php artisan serve --port=8000
php artisan queue:work --queue=taps,notify,leads,default,render   # wajib: tap & notifikasi lewat antrean
php artisan schedule:work                                          # pengingat follow-up (tiap menit), agregasi statistik, partisi, prune
```

Host lokal memakai `*.localhost` (browser modern otomatis ke 127.0.0.1, tanpa edit file hosts):

| Host | Fungsi |
|---|---|
| `http://tap.localhost:8000/c/{token}` | Resolver permanen (chip & QR), `/k` kode pendek, `/@handle` |
| `http://app.localhost:8000` | Login & workspace Personal |
| `http://panel.localhost:8000` | Panel pemilik & produksi (cookie sesi terpisah) |
| `http://localhost:8000` | Halaman marketing |

Email (kode OTP) dikirim ke `storage/logs/laravel-*.log` karena `MAIL_MAILER=log`.

### Akun & login

- **Workspace** (`app.localhost:8000`) — tanpa password: email OTP 6 digit atau Google. Akun dibuat otomatis saat login pertama.
- **Panel pemilik** (`panel.localhost:8000`) — **email + password**, hanya staf aktif; sesi terpisah & berakhir tiap 12 jam. Staf baru mendapat password sementara (tampil sekali) dari menu Staf; ganti di menu *Akun & password*.

Mode lokal (`APP_ENV=local` + `DEV_LOGIN_CODE=123456`): kode OTP selalu `123456`, dan halaman login menampilkan tombol akun dummy untuk masuk satu klik. Di produksi otomatis mati.

| Perintah | Hasil |
|---|---|
| `php artisan migrate --seed` | Data dasar + **Pemilik Platform** dari `SEED_PLATFORM_OWNER_EMAIL` (password dari `SEED_PLATFORM_OWNER_PASSWORD`, atau acak & dicetak di terminal) |
| `php artisan db:seed --class=DummyAccountSeeder` | Akun dummy (aman diulang): `demo@` & `budi@tapcard.test` (kode `123456`), `admin@`, `produksi@`, `support@`, `keuangan@tapcard.test` (password `password123`) + kartu & riwayat tap untuk demo |

## Pengujian

```bash
composer test            # PHPUnit pada database tapcard_test (168 test)
npm test                 # node --test untuk resources/js/lib/__tests__
vendor/bin/pint --test   # format kode
sh scripts/check-design.sh   # DBD §3.4: tanpa gradien/bayangan, hex hanya di tokens.css
```

## Cakupan MVP yang sudah dibangun

- **Fondasi multi-tenant** (TRD §4.5): `tenant_id` + global scope yang *menolak* query tanpa konteks, routing berdasarkan host, test isolasi lintas tenant.
- **Mesin izin** (PRD §5, TRD §5.3): katalog izin + 14 peran sistem (platform & workspace) di `app/Authz/Catalog.php`, cakupan own/team/event/all, izin dipangkas oleh fitur paket, cache berversi.
- **Auth**: email OTP (hash, 10 menit, 5 percobaan, throttle) + Google Socialite; panel pemilik dengan verifikasi ulang 12 jam.
- **Resolver `/c/{token}`**: HTML pertama sudah memuat data profil + judul/OG (tanpa request API kedua), tampilan per status kartu & workspace, tap dicatat lewat antrean (IP di-hash, kota dari header Cloudflare), `no-store`, `noindex`, CSP ber-nonce.
- **Aktivasi** dua faktor (pembeli atau kode 8 karakter Crockford, terkunci 1 jam setelah 5 salah). Kartu bisa ditambah dari app (**Kartu → Tambah kartu**: kode kartu + kode aktivasi) atau dari halaman tap (kode dibawa ke app dan langsung aktif setelah login). Wizard 4 langkah Profil → Foto → Kontak → Coba, yang mendeteksi tap pertama (“Berhasil! Kartu Anda terbaca.”).
- **Profil publik** Blade + JS vanilla kecil: Simpan Kontak (vCard 3.0), tombol cepat, tautan terhitung klik, **Tukar Kontak** (persetujuan tercatat, honeypot, waktu minimum, dedup E.164/email).
- **Workspace Personal**: dashboard realtime (SSE), kartu (jeda, hilang/ditemukan, **minta kartu pengganti** → staf membuat batch 1 kartu; mengaktifkan pengganti otomatis mencabut kartu lama), **bio link per kartu** (1 kartu = 1 bio; kartu baru menyalin bio terakhir termasuk foto) dengan editor `/cards/{token}/bio` + preview langsung: 8 preset tema, 15 warna aksen (kontras dijaga server), header tengah/kiri/sampul, bentuk foto, sudut & gaya tombol, tautan daftar/tombol/grid, font nama, koneksi (tahap, alasan kalah, aktivitas, tag, ekspor CSV), analitik, pengaturan (notifikasi, @handle, ekspor JSON, hapus akun).
- **Produksi**: batch → token/kode pendek/kode aktivasi → bundle CSV vendor & lembar QR → stasiun encode Web NFC → QC (NFC & QR harus sama) → kirim.
- **Panel pemilik**: dashboard, workspace (paket, batas khusus, suspend), pengguna (blokir, paksa keluar), kartu (riwayat, ubah status, terbitkan ulang kode), staf & peran, audit log, kesehatan sistem + ambang Redis.
- **Design Studio** (`/designs`): 8 template default (Onyx, Ivory, Garis, Kreatif, Natural, Metal, Sage, Korporat) × lanskap/potret × dengan/tanpa lanyard; flip depan-belakang; teks/kotak/garis/lingkaran/cincin/gambar; undo/redo; geser dengan snap ke garis panduan (Alt = bebas), pegangan ubah ukuran (Shift = rasio tetap), zoom 50–300% (`+` `−` `0`), nama panjang otomatis diperkecil (`fitWidth`, min 6 pt); validasi cetak (teks ≥ 6 pt, safe zone 4 mm, bleed 3 mm, area slot lanyard ±10–12 mm, QR tidak tertutup). Elemen wajib — **QR + kode pendek, ikon NFC, zona TAP, logo TapCard** — dibangun ulang server pada setiap simpan sehingga tidak bisa diubah/dihapus; warnanya mengikuti kontras latar. Pesan/cetak desain menyusul bersama modul pesanan (v1).
- **Encoder QR PHP murni** (mode byte, level M, versi 2–6), diverifikasi decode 600 URL acak dengan zxing; **NDEF**, **vCard**, **ImageResizer** (GD → WebP/JPEG).

## Fitur roadmap v1 yang sudah dibangun

- **Tabel server-side** di seluruh dashboard (`App\Support\Http\Table` + `usePagedList` + `DataTable`): cari, urut kolom (daftar putih), halaman, 10–100 baris per halaman, state di URL. Termasuk tabel di dalam halaman detail admin (kartu batch, anggota & kartu workspace, kartu pengguna, staf).
- **Dashboard lead** (`/connections/dashboard`): 13 widget (KPI, lead per hari, pipeline per tahap, sumber, event, alasan kalah, tag, follow-up terlambat, lead terbaru), periode 7/30/90 hari, tambah/lepas/urutkan/ubah lebar widget, tata letak disimpan per pengguna (`users.preferences`).
- **Pengingat follow-up** (`/follow-up`, panel di detail lead): otomatis 24 jam setelah lead baru (pengaturan `auto_followup_hours`), batal saat lead dihubungi (telepon/WA/email/meeting/pindah tahap — catatan saja tidak), notifikasi realtime lewat `tapcard:reminders` tiap menit.
- **Impor CSV** (`/connections/import`): pemisah `,` `;` tab, BOM & Windows-1252, tebak pemetaan kolom, pratinjau 20 baris, job antrean `leads` dengan dedup, ringkasan + contoh baris gagal, maks 10.000 baris.
- **Mode Event** (`/events`): buat event, "Saya sedang di sini" → tap & koneksi berlabel event, "Bertemu di …" di bio link (opsional), ringkasan & ekspor CSV per event.
- **Mode Reader** (`/scan`): baca kartu orang lain lewat Web NFC (Chrome Android), kamera QR (BarcodeDetector), atau kode/URL. Kartu TapCard → koneksi dua arah dari bio-nya + "Simpan balik"; kartu lain → koneksi eksternal.
- **QR dinamis** (`/cards/{token}/qr`, paket Pro): token sekali pakai 60 detik (disimpan sebagai hash), diperbarui otomatis, `/q/{token}` → redirect atau 410 "QR sudah kedaluwarsa".

## Editor kartu, lanyard & brand kit

- **Studio kartu** (`/designs/:id`, layar penuh): mulai dari **kartu kosong**, 8 template, atau **template saya**; teks multi-baris, kotak, elips, poligon/bintang, garis, lingkaran, cincin, **48 ikon lucide**, gambar (crop/mask bulat/membulat), latar gambar; **pilih banyak** (Shift/Ctrl, kotak seleksi), **grup** (Ctrl+G), **ratakan/distribusi**, **putar** (Shift = 15°), **kunci/sembunyi**, urutan lapisan (drag), salin ke sisi lain, copy/paste antar-desain, grid & snap, zoom, undo/redo, **warna bebas** (hex + warna terakhir), pratinjau cetak + **unduh PNG 300 dpi**.
- **Editor lanyard** (`/lanyards/:id`): pita 15/20/25 mm × 90 cm, desain satu pola yang diulang (40–300 mm), 1/2 sisi, pengait (lobster, J-hook, badge reel, pengaman putus), **harga dari katalog** (`lanyard_widths`, `lanyard_hardware`; checkout menyusul modul pesanan).
- **Sinkron kartu ↔ lanyard**: `brand_kits` (warna, logo, nama, tagline) dirujuk lewat `kit:primary`, `kit:logo`, `{{brand}}` → ubah sekali, semua desain ikut; `designs.paired_design_id` (unik) memasangkan lanyard dengan kartu, lubang slot kartu aktif otomatis, pratinjau gantung gabungan, salin elemen dua arah (diskalakan otomatis).
- **Kontak & tautan relasional**: `contact_type_groups` → `contact_types` (45 jenis: pesan, sosial, bisnis, toko online, rekening bank, QRIS, e-wallet) ← FK `profile_fields.type`; normalisasi, tautan, dan vCard per jenis di `App\Support\Contacts\ContactValue`.
- **Select single/multiple** (`AppSelect`): pencarian, grup, chip, keyboard & ARIA; filter Koneksi multi-pilih (tahap, sumber, tag).

## Paket & pembayaran

- **Paket & tagihan** (`/billing`): paket aktif + pemakaian, perbandingan paket bulanan/tahunan (tahunan = 10× bulanan), checkout, tagihan terbuka, batal/lanjutkan langganan, riwayat invoice; invoice/kuitansi bisa dicetak (`/billing/invoices/:id`).
- Tabel `subscriptions` (1 per workspace), `invoices` (nomor `INV/YYYY/MM/000123`), `payment_events` (unik per provider + external_id → webhook ganda diabaikan). Harga selalu dari tabel `plans`.
- Gateway: `TAPCARD_PAYMENT_DRIVER=simulator` (lokal: halaman bayar tiruan `/billing/pay/:id`) atau `midtrans` (Snap; isi `MIDTRANS_SERVER_KEY`, webhook `POST https://domain.com/webhooks/payments/midtrans`). Notifikasi diverifikasi signature SHA-512 & nominal; produksi tidak pernah memakai simulator.
- `tapcard:subscriptions` (harian 01.00 WIB): invoice perpanjangan H-7, jatuh tempo → masa tenggang 14 hari, lalu kembali ke paket gratis.
- Fitur berbayar yang terkunci (mis. QR aman) menampilkan layar upgrade dengan harga & tombol langsung ke checkout.
- Panel pemilik kini di **`panel.`** (`TAPCARD_PANEL_SUBDOMAIN`); `admin.` dialihkan 301.
- **Voucher** di checkout (`POST /api/billing/voucher` untuk pratinjau): persen/nominal, kuota, masa berlaku, paket tertentu; 1× per workspace, dihitung terpakai saat invoice lunas. Voucher 100% langsung mengaktifkan paket.

## Panel pemilik (`panel.`)

Menu dikelompokkan **Operasional · Produksi · Bisnis · Sistem** dan mengikuti izin staf (`platform.*`):

- **Paket & harga** (`plans.manage`): harga bulanan/tahunan, masa uji coba, tampil/sembunyi, batas & fitur per paket. Harga baru berlaku untuk checkout berikutnya; batas & fitur langsung berlaku.
- **Keuangan** (`finance.*`): MRR/ARR, pendapatan 30 hari, invoice (tandai lunas manual via jalur pembayaran yang sama, batalkan), langganan, event gateway, *beri paket* tanpa bayar (invoice penyesuaian Rp0 + audit).
- **Voucher**, **Katalog** (harga lanyard per lebar/sisi cetak, pengait, jenis kontak bio link), **Pengumuman** (notifikasi in-app ke semua / personal / perusahaan / paket).
- **Workspace**: detail kini memuat langganan, invoice terbaru, *beri paket*, dan domain kustom.
- **Domain kustom** (fitur paket `custom_domain`, Business/Enterprise): staf menambah domain → pelanggan memasang
  `TXT _tapcard.<domain> = tapcard-verify=<token>` dan `CNAME <domain> → app.<base>` → *Cek DNS* → aktif. Workspace lalu bisa
  dibuka di domain itu (`ResolveWorkspace`, cache `tenant:domain:<host>`). Di produksi Nginx perlu server block *catch-all*
  (`server_name _;`) ke aplikasi yang sama, plus TLS otomatis (mis. Caddy on-demand TLS / certbot per domain).

## Landing page

Domain utama (`localhost:8000`) menampilkan landing page: hero, nilai utama, cara kerja, galeri 8 template (lanskap/potret,
bisa dibalik), demo bio link interaktif, fitur, perbandingan dengan kartu kertas, harga dari tabel `plans`, FAQ.
Datanya dari `GET /api/showcase` (publik, di-cache 1 jam) dan dirender dengan komponen yang sama dengan produk.

## Produksi

Lihat [deploy/README.md](deploy/README.md): Nginx + PHP-FPM (pool SSE terpisah), OPcache produksi, Supervisor
(2 worker antrean + scheduler), `deploy/deploy.sh` (config/route/view/event cache), `.env.production.example`,
dan ambang kapan pindah ke Redis. `php artisan serve` hanya untuk lokal.

## Belum dibangun (fase berikutnya sesuai roadmap)

v1: pesanan & pembayaran (termasuk PDF cetak desain via Chromium) Midtrans/Xendit, katalog/voucher, moderasi, PWA service worker, notifikasi email/push untuk pengingat.
v1.5: workspace Perusahaan (UI pembuatan, undangan, tim, **penugasan kartu ke anggota tim**, custom role, branding), langganan & invoice, webhook, NTAG 424 DNA/SUN, impersonation.
Skema database untuk sebagian besar fitur ini sudah disiapkan.

## Penyesuaian terhadap dokumen

- `batches.tenant_id` & `batches.holder_user_id` ditambahkan: tanpa modul pesanan (v1), batch pilot bisa langsung ditujukan ke satu pembeli agar aktivasi tanpa kode tetap jalan.
- `platform_staff.ip_allowlist` disimpan sebagai `jsonb`, bukan `inet[]`.
- Halaman aktivasi P-02 di `tap.` mengarahkan ke `app.…/activate/{token}` untuk login; jadi alurnya tidak bergantung pada cookie lintas subdomain.
- **Menyimpang dari TRD §2, §4.3, §4.4, §17 (T1)** atas permintaan pemilik produk: SPA Vue penuh dengan vue-router, Pinia, Tailwind, dan lucide-vue-next menggantikan Blade + pulau Vue. Konsekuensi: profil publik kini butuh JS bundle + satu panggilan API sebelum tampil (sebelumnya HTML server-render < 3 KB JS), jadi target LCP < 1,5 detik di 4G perlu diukur ulang.
- `php artisan test` tidak tersedia karena `nunomaduro/collision` dihapus (TRD §2.1); `composer test` memanggil PHPUnit langsung.
