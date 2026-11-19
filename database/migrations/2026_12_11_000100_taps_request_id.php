<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * taps.request_id (ULID per tap) + indeks unik → job RecordTap yang dicoba ulang tidak mencatat tap ganda.
 * Indeks unik di tabel berpartisi wajib memuat kunci partisi (created_at). Tidak bisa CONCURRENTLY di tabel
 * berpartisi: penulisan tap tertahan selama indeks dibangun — aman karena tap ditulis worker antrean, bukan request.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE taps ADD COLUMN IF NOT EXISTS request_id char(26)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS taps_request_uq ON taps (request_id, created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS taps_request_uq');
        DB::statement('ALTER TABLE taps DROP COLUMN IF EXISTS request_id');
    }
};
