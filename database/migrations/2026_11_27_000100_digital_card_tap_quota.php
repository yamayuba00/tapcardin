<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paket kartu digital: kuota tap kumulatif per kartu (`tap_quota` null = tanpa batas, mis. kartu fisik), pemakaian
 * `tap_used`, dan ambang notifikasi yang sudah dikirim (`tap_quota_alert`: 0 / 90 / 100 persen). Tambahan kuota dibeli
 * lewat invoice bertipe `topup`. Kartu digital yang sudah ada mulai dari kuota dasar dengan pemakaian 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $t) {
            $t->integer('tap_quota')->nullable();
            $t->integer('tap_used')->default(0);
            $t->smallInteger('tap_quota_alert')->default(0);
        });
        DB::table('cards')->where('form', 'digital')->update(['tap_quota' => (int) config('tapcard.digital_card.tap_quota')]);

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_type_chk');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_chk CHECK (type IN ('order','subscription','adjustment','topup'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_type_chk');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_chk CHECK (type IN ('order','subscription','adjustment'))");
        Schema::table('cards', fn (Blueprint $t) => $t->dropColumn(['tap_quota', 'tap_used', 'tap_quota_alert']));
    }
};
