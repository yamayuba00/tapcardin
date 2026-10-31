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

    'consent_text_version' => 'v1',

    'reserved_slugs' => [
        'www', 'app', 'admin', 'panel', 'tap', 'api', 'mail', 'help', 'status', 'docs', 'blog',
        'cdn', 'static', 'staging', 'dev', 'support', 'billing', 'login', 'auth',
    ],
];
