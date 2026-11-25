<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kartu fisik perusahaan yang sudah aktif sebelum aturan baru:
 *  - masa pakai 6 bulan dimulai dari saat migrasi (bukan dari tanggal aktivasi lama), supaya tidak ada kartu yang langsung nonaktif;
 *  - kuota tap = pemakaian sekarang + kuota periode (tanpa batas sebelumnya → tidak ada kartu yang langsung terhenti).
 * Kartu pribadi dan kartu digital tidak disentuh.
 */
return new class extends Migration
{
    private const WHERE = "FROM tenants t WHERE t.id = cards.tenant_id AND t.type = 'company' AND cards.form = 'physical'
        AND cards.status IN ('active', 'paused', 'lost')";

    public function up(): void
    {
        DB::statement("UPDATE cards SET valid_until = now() + interval '6 months' ".self::WHERE.' AND cards.valid_until IS NULL');
        DB::statement('UPDATE cards SET tap_quota = cards.tap_used + ? '.self::WHERE.' AND cards.tap_quota IS NULL', [(int) config('tapcard.company_card.tap_quota', 6000)]);
    }

    /** Tidak dibalik: mengembalikan kuota tanpa batas akan membuka batas tap yang sudah berlaku. */
    public function down(): void
    {
        //
    }
};
