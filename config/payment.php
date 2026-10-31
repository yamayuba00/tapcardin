<?php

/*
| Payment service — langganan paket dibayar lewat satu gateway yang dipilih di sini.
| Semua nama, alamat, dan perilaku gateway diatur dari env (PAYMENT_*), jadi berganti penyedia cukup mengubah env
| — atau menambah kelas gateway baru di app/Services/Payment/Gateways bila bentuk API-nya berbeda.
|
| driver:
|   simulator — halaman bayar tiruan di aplikasi (lokal & test). Ditolak otomatis di produksi.
|   link      — gateway "payment link" via REST: buat pembayaran → arahkan pengguna ke link/QR → konfirmasi lewat
|               webhook ber-tanda tangan dan/atau cek status ke API (server-to-server).
*/
return [
    'driver' => env('PAYMENT_DRIVER', 'simulator'),

    'gateway' => [
        // Ditampilkan ke pengguna ("Pembayaran aman lewat …") dan disimpan sebagai penyedia di invoice/event (maks 16 karakter).
        'name' => env('PAYMENT_GATEWAY_NAME', 'Payment Gateway'),
        'code' => env('PAYMENT_GATEWAY_CODE', 'gateway'),

        // API gateway
        'api_url' => env('PAYMENT_API_URL'),
        'api_key' => env('PAYMENT_API_KEY'),
        'api_key_header' => env('PAYMENT_API_KEY_HEADER', 'X-Api-Key'),
        'create_path' => env('PAYMENT_CREATE_PATH', '/payments'),
        'status_path' => env('PAYMENT_STATUS_PATH', '/payments/{id}'),
        'timeout' => (int) env('PAYMENT_TIMEOUT', 20),

        // Metode bayar yang diminta ke gateway & teks yang ditampilkan ke pengguna
        'method' => env('PAYMENT_METHOD', 'QRIS'),
        'method_label' => env('PAYMENT_METHOD_LABEL', 'QRIS — semua e-wallet & mobile banking'),
        'expires_hours' => (int) env('PAYMENT_EXPIRES_HOURS', 24),

        // Webhook: signature (HMAC-SHA256 gaya Svix: header id/timestamp/signature, secret "whsec_…") | token | none.
        // none = isi webhook tidak dipercaya; status selalu dikonfirmasi ulang lewat API gateway.
        'webhook' => [
            'verify' => env('PAYMENT_WEBHOOK_VERIFY', 'signature'),
            'secret' => env('PAYMENT_WEBHOOK_SECRET'),
            'token' => env('PAYMENT_WEBHOOK_TOKEN'),
            'token_header' => env('PAYMENT_WEBHOOK_TOKEN_HEADER', 'X-Webhook-Token'),
            'tolerance' => (int) env('PAYMENT_WEBHOOK_TOLERANCE', 300), // detik, untuk timestamp signature
        ],
    ],
];
