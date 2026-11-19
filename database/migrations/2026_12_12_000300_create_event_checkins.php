<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kehadiran peserta event (check-in oleh panitia). Satu kartu satu kali per event (unik). Kartu boleh milik workspace
 * lain (tamu): `user_id` kosong, hanya `guest_name` (nama publik profilnya) yang disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_checkins', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('card_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('guest_name', 120)->nullable();
            $t->string('method', 8);
            $t->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['event_id', 'card_id']);
            $t->index(['event_id', 'created_at']);
            $t->index('tenant_id');
            $t->index('card_id');
            $t->index('user_id');
            $t->index('checked_in_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_checkins');
    }
};
