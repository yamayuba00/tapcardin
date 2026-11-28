<?php

return [
    // TRD §4.6 — semua host diturunkan dari base domain (domain.com di produksi).
    'base_domain' => env('TAPCARD_BASE_DOMAIN', 'domain.com'),

    // URL permanen yang tercetak di chip & QR. Tidak boleh berubah seumur hidup kartu.
    'resolver_url' => rtrim(env('TAPCARD_RESOLVER_URL', 'https://tap.domain.com'), '/'),

    'uid_pepper' => env('TAPCARD_UID_PEPPER'),
    'ip_pepper' => env('TAPCARD_IP_PEPPER'),

    // Subdomain panel pemilik & produksi (panel.domain.com). Host lama `admin.` dialihkan ke sini.
    'panel_subdomain' => env('TAPCARD_PANEL_SUBDOMAIN', 'panel'),

    'admin_session_cookie' => env('ADMIN_SESSION_COOKIE', 'tapcard_admin'),
    'admin_reverify_hours' => 12,

    'seed_platform_owner_email' => env('SEED_PLATFORM_OWNER_EMAIL'),
    'seed_platform_owner_password' => env('SEED_PLATFORM_OWNER_PASSWORD'),

    // Kode OTP tetap untuk pengembangan lokal (diabaikan jika APP_ENV bukan local).
    'dev_login_code' => env('DEV_LOGIN_CODE'),

    // ALTCHA anti-bot di login/daftar/lupa password. max_number = tingkat kesulitan (±0,2–1 dtk di ponsel).
    'altcha' => [
        'enabled' => (bool) env('ALTCHA_ENABLED', true),
        'key' => env('ALTCHA_HMAC_KEY'), // kosong = diturunkan dari APP_KEY
        'max_number' => (int) env('ALTCHA_MAX_NUMBER', 100000),
    ],

    // "Ingat saya" = cookie login 7 hari; tanpa centang, sesi berakhir mengikuti SESSION_LIFETIME.
    'remember_minutes' => (int) env('AUTH_REMEMBER_MINUTES', 60 * 24 * 7),

    'consent_text_version' => 'v1',

    // Tap berulang dari pengunjung yang sama (IP + browser) ke kartu yang sama dalam N menit dihitung sekali —
    // refresh/spam tidak menggelembungkan statistik & tidak mengirim notifikasi berulang. 0 = hitung semua.
    // editor merchandise (jaket, kaos, mug, ...) — false = tampil "Segera hadir", belum bisa dipakai
    'merch_enabled' => (bool) env('MERCH_ENABLED', false),

    'tap_dedupe_minutes' => (int) env('TAP_DEDUPE_MINUTES', 30),

    // Alarm email ke pemilik platform (tapcard:alert, tiap 5 menit) saat pemeriksaan tapcard:health gagal.
    // Kosong = semua staf aktif berperan Pemilik Platform. Isi (dipisah koma) agar tetap terkirim walau database mati.
    'alert' => [
        'emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALERT_EMAILS', ''))))),
        'errors_15m' => (int) env('ALERT_ERRORS_15M', 10),  // error server dalam 15 menit sebelum dianggap masalah
        'remind_hours' => (int) env('ALERT_REMIND_HOURS', 6),
    ],

    // Backup database harian ke disk `backups` (S3, folder backups/ — privat, di luar policy publik). Kosong = mati.
    'backup' => [
        'disk' => env('BACKUP_DISK'),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),
        // pg_dump butuh koneksi langsung; isi bila DB_PORT mengarah ke PgBouncer (mis. 6432 → 5432)
        'db_host' => env('BACKUP_DB_HOST'),
        'db_port' => env('BACKUP_DB_PORT'),
    ],

    // Pesanan "menunggu pembayaran" dibatalkan otomatis setelah N hari (pengingat sehari sebelumnya).
    'orders' => [
        'unpaid_cancel_days' => max(2, (int) env('ORDER_UNPAID_CANCEL_DAYS', 3)),
    ],

    // Paket kartu digital — terpisah dari paket workspace (kartu fisik). Kuota tap berlaku kumulatif (tidak di-reset
    // tiap bulan): habis → profil tidak bisa dibuka sampai pemilik membeli tambahan. Tap yang dihitung = tap unik
    // (lihat tap_dedupe_minutes), bot tidak dihitung. Kartu fisik tanpa kuota tap.
    // Masa pakai kartu hasil penjualan langsung: dihitung dari aktivasi, lalu tenggang sebelum nonaktif.
    'card_period' => [
        'months' => 6,
        'grace_days' => 7,
        'renewal_price' => (int) env('CARD_RENEWAL_PRICE', 150000), // PLACEHOLDER: bisa diubah di panel → Katalog
    ],
    // kartu fisik perusahaan: masa pakai 6 bulan per periode, kuota tap 6.000 per periode (reset tiap periode baru)
    'company_card' => [
        'tap_quota' => (int) env('COMPANY_CARD_TAP_QUOTA', 6000),
        // potongan per kartu fisik untuk pesanan dari workspace perusahaan (Rp)
        'order_discount' => (int) env('COMPANY_CARD_ORDER_DISCOUNT', 20000),
    ],
    'digital_card' => [
        'tap_quota' => (int) env('DIGITAL_TAP_QUOTA', 1500),
        'topup_taps' => (int) env('DIGITAL_TOPUP_TAPS', 2000),
        'topup_price' => (int) env('DIGITAL_TOPUP_PRICE', 70000),
        'bio_pages' => 1, // kartu fisik: BioBlocks::MAX_PAGES
    ],

    // Kebijakan refund (nominal dalam rupiah). Refund ≥ owner_threshold harus disetujui Pemilik Platform.
    'refund' => [
        'owner_threshold' => (int) env('REFUND_OWNER_THRESHOLD', 500000),
        'claim_window_days' => (int) env('REFUND_CLAIM_WINDOW_DAYS', 7),            // klaim cacat / salah cetak setelah barang tiba
        'not_delivered_after_days' => (int) env('REFUND_NOT_DELIVERED_AFTER_DAYS', 14), // "tidak sampai" baru bisa diajukan setelah N hari dikirim
        'approval_ttl_days' => (int) env('APPROVAL_TTL_DAYS', 3),                   // permintaan persetujuan kedaluwarsa bila tak diputuskan
    ],

    // Tanda risiko pesanan sebelum produksi. Pesanan bertanda risiko menunggu tinjauan staf.
    'risk' => [
        'high_value' => (int) env('ORDER_RISK_HIGH_VALUE', 2000000),
        'new_account_days' => 7,
        'new_account_value' => (int) env('ORDER_RISK_NEW_ACCOUNT_VALUE', 500000),
        'same_address_orders' => 3,
        'same_user_burst' => 5,
        // kata yang memicu tinjauan (pisahkan dengan koma), mis. nama merek yang tidak boleh dicetak
        'blocked_terms' => array_values(array_filter(array_map('trim', explode(',', (string) env('ORDER_RISK_TERMS', ''))))),
    ],

    // Nomor WhatsApp tim sales untuk tombol "Hubungi Sales" di landing (kode negara, angka saja, mis. 6281234567890).
    // Kosong → tombol disembunyikan.
    'sales_whatsapp' => preg_replace('/\D/', '', (string) env('TAPCARD_SALES_WHATSAPP', '')),

    // Email transaksional: asal gambar (logo) yang bisa diakses publik — kosong = APP_URL; tautan "Pusat bantuan"
    // di footer — kosong = WhatsApp sales bila diisi, selain itu situs utama.
    'mail' => [
        'asset_url' => env('MAIL_ASSET_URL'),
        'help_url' => env('MAIL_HELP_URL'),
    ],

    // Render server landing page (Vue SSR lewat node, hasil di-cache). Mati → landing tetap SPA biasa.
    'ssr' => [
        'enabled' => (bool) env('LANDING_SSR', true),
        'node' => env('LANDING_SSR_NODE', 'node'),
        'timeout' => (float) env('LANDING_SSR_TIMEOUT', 5),
    ],

    'reserved_slugs' => [
        'www', 'app', 'admin', 'panel', 'tap', 'ws', 'api', 'mail', 'help', 'status', 'docs', 'blog',
        'cdn', 'static', 'staging', 'dev', 'support', 'billing', 'login', 'auth', 'wbh',
    ],

    // Webhook keluar: tujuan di jaringan internal (localhost, 10.x, 192.168.x) hanya boleh di APP_ENV=local
    'webhooks' => [
        'allow_private' => (bool) env('WEBHOOKS_ALLOW_PRIVATE', false),
    ],
];
