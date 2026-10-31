<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AFD F-13 — kartu pengganti. Sebelum modul pesanan (v1), pemegang meminta pengganti dari UI,
     * staf produksi membuat batch 1 kartu yang menautkan `replaces_card_id`; aktivasi kartu baru
     * memakai profil yang sama dan mencabut kartu lama.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $t) {
            $t->timestampTz('replacement_requested_at')->nullable()->after('revoked_at');
            $t->string('replacement_note', 200)->nullable()->after('replacement_requested_at');
        });
        Schema::table('batches', function (Blueprint $t) {
            $t->foreignId('replaces_card_id')->nullable()->after('holder_user_id')->constrained('cards');
        });
    }

    public function down(): void
    {
        Schema::table('batches', fn (Blueprint $t) => $t->dropConstrainedForeignId('replaces_card_id'));
        Schema::table('cards', fn (Blueprint $t) => $t->dropColumn(['replacement_requested_at', 'replacement_note']));
    }
};
