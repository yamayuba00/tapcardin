<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Personal Free: 1 kartu aktif. Personal Pro: tap kartu digital tanpa batas (fitur `unlimited_digital_taps`). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE plans SET limits = jsonb_set(limits, '{cards_active}', '1'::jsonb) WHERE key = 'personal_free'");
        DB::statement("UPDATE plans SET features = features || '{\"unlimited_digital_taps\": true}'::jsonb WHERE key = 'personal_pro'");
    }

    public function down(): void
    {
        DB::statement("UPDATE plans SET limits = jsonb_set(limits, '{cards_active}', '2'::jsonb) WHERE key = 'personal_free'");
        DB::statement("UPDATE plans SET features = features - 'unlimited_digital_taps' WHERE key = 'personal_pro'");
    }
};
