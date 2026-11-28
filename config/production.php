<?php

// Estimasi cetak pesanan kartu: hari kerja (Senin–Jumat, di luar daftar libur) dihitung sejak pesanan lunas.
return [
    // Kapasitas: maksimal kartu yang mulai dicetak per hari kerja. Bisa diubah di panel → Katalog (PlatformSetting
    // `production.cards_per_day`); nilai ini hanya dipakai bila pengaturan itu belum ada. 200 = placeholder.
    'cards_per_day' => (int) env('PRODUCTION_CARDS_PER_DAY', 200),

    // Lanyard tidak punya tabel lead time sendiri dan tidak memakai kapasitas kartu.
    'lanyard_print_min_days' => 3,
    'lanyard_print_max_days' => 7,

    // Hari libur (format Y-m-d) yang tidak dihitung sebagai hari kerja. Kosong dulu; isi sesuai kalender pemilik.
    'holidays' => [],
];
