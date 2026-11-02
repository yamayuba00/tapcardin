<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QR kartu digital paket Free berganti tiap N hari (plans.limits.qr_rotation_days): QR mengarah ke
 * /s/{code}; kode lama berhenti berlaku setelah expires_at. Paket berbayar memakai QR permanen /c/{token}.
 * Sekaligus: Personal Free menampilkan 100 koneksi (sisanya tetap tersimpan, terbuka saat upgrade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_share_codes', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('card_id')->constrained()->cascadeOnDelete();
            $t->char('code', 12)->unique();
            $t->timestampTz('starts_at');
            $t->timestampTz('expires_at');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['card_id', 'expires_at']);
        });
        DB::statement('ALTER TABLE card_share_codes ADD CONSTRAINT card_share_codes_period_chk CHECK (expires_at > starts_at)');

        // hanya bila batasnya masih bawaan lama (tidak diubah admin di panel)
        DB::statement(<<<'SQL'
            UPDATE plans SET limits = jsonb_set(jsonb_set(limits::jsonb, '{connections}', '100'), '{qr_rotation_days}', '7')
            WHERE key = 'personal_free' AND (limits::jsonb ->> 'connections') = '200'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('card_share_codes');
        DB::statement(<<<'SQL'
            UPDATE plans SET limits = (limits::jsonb - 'qr_rotation_days') || '{"connections": 200}'::jsonb
            WHERE key = 'personal_free' AND (limits::jsonb ->> 'connections') = '100'
        SQL);
    }
};
