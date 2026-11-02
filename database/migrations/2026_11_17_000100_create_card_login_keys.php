<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login dengan kartu NFC: kartu fisik (UID chip terikat saat encode) + PIN 6 digit milik pemegang.
 * Satu kunci per kartu; kunci gugur bila pemegang kartu berganti (dicek saat login).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_login_keys', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('card_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $t->string('pin_hash');
            $t->smallInteger('failed_attempts')->default(0);
            $t->timestampTz('locked_until')->nullable();
            $t->timestampTz('last_used_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE card_login_keys ADD CONSTRAINT card_login_keys_attempts_chk CHECK (failed_attempts >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('card_login_keys');
    }
};
