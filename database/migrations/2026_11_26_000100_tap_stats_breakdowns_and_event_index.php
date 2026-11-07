<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Analitik membaca ringkasan harian, bukan tabel `taps` mentah: perangkat & kota ikut diringkas per kartu per hari.
        Schema::table('daily_card_stats', function (Blueprint $t) {
            $t->jsonb('devices')->default('{}');
            $t->jsonb('cities')->default('{}');
        });

        // Ringkasan event. Parsial: sebagian besar tap tidak terjadi di event, jadi index hanya memuat tap yang ber-event.
        // Dibuat di tabel induk → otomatis ada di setiap partisi (termasuk yang dibuat `tapcard:partitions` nanti).
        DB::statement('CREATE INDEX IF NOT EXISTS taps_event_ix ON taps (event_id) WHERE event_id IS NOT NULL');

        // isi ulang ringkasan (perangkat & kota) untuk seluruh masa retensi partisi (24 bulan)
        Artisan::call('tapcard:aggregate-stats', ['--days' => 731]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS taps_event_ix');
        Schema::table('daily_card_stats', function (Blueprint $t) {
            $t->dropColumn(['devices', 'cities']);
        });
    }
};
