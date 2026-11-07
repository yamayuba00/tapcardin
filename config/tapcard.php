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
    'tap_dedupe_minutes' => (int) env('TAP_DEDUPE_MINUTES', 30),

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

    'reserved_slugs' => [
        'www', 'app', 'admin', 'panel', 'tap', 'ws', 'api', 'mail', 'help', 'status', 'docs', 'blog',
        'cdn', 'static', 'staging', 'dev', 'support', 'billing', 'login', 'auth',
    ],
];
