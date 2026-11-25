<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Harga & batas kartu paket perusahaan (per 6 bulan):
 *   Starter  Rp1.000.000 → 10 kartu aktif
 *   Growth   Rp4.000.000 → 40 kartu aktif
 *   Business Rp10.000.000 → 80 kartu aktif
 *   Custom   tidak tampil di billing; ditangani sales (plan enterprise, tidak publik)
 * Langganan yang sudah berjalan tidak diubah tanggalnya; batas baru hanya menahan aktivasi kartu baru.
 */
return new class extends Migration
{
    private const PRICING = [
        'starter' => [1000000, 10],
        'growth' => [4000000, 40],
        'business' => [10000000, 80],
    ];

    public function up(): void
    {
        foreach (self::PRICING as $key => [$price, $cards]) {
            $plan = DB::table('plans')->where('key', $key)->first();
            if (! $plan) {
                continue;
            }
            $limits = json_decode($plan->limits, true) ?? [];
            $limits['cards_active'] = $cards;
            DB::table('plans')->where('id', $plan->id)->update([
                'price_semiannual' => $price, 'limits' => json_encode($limits), 'updated_at' => now(),
            ]);
        }
    }

    /** Tidak dibalik otomatis: harga & batas lama tidak tersimpan di migrasi ini. */
    public function down(): void
    {
        //
    }
};
