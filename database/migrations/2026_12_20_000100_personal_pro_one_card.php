<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Personal Pro: 1 kartu digital (satu bio link per pengguna); batas `cards_active` sebelumnya 10 salah. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE plans SET limits = jsonb_set(limits, '{cards_active}', '1'::jsonb) WHERE key = 'personal_pro'");
    }

    public function down(): void
    {
        DB::statement("UPDATE plans SET limits = jsonb_set(limits, '{cards_active}', '10'::jsonb) WHERE key = 'personal_pro'");
    }
};
