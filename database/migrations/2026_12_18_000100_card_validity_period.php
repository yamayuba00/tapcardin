<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Masa pakai kartu hasil penjualan langsung (null = tanpa batas, kartu lama tidak berubah) + penanda pengingat. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $t) {
            $t->timestampTz('valid_until')->nullable();
            $t->jsonb('period_reminders')->default(DB::raw("'[]'::jsonb"));
        });
        DB::statement('CREATE INDEX IF NOT EXISTS cards_valid_until_ix ON cards (valid_until) WHERE valid_until IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cards_valid_until_ix');
        Schema::table('cards', function (Blueprint $t) {
            $t->dropColumn(['valid_until', 'period_reminders']);
        });
    }
};
