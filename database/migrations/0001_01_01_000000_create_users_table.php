<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BSD §4.1 — tanpa password: login lewat email OTP atau Google.
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('name', 120);
            $t->string('email', 190);
            $t->string('phone', 32)->nullable();
            $t->string('google_id', 64)->nullable()->unique();
            $t->string('handle', 30)->nullable()->unique();
            $t->string('avatar_path')->nullable();
            $t->unsignedBigInteger('personal_tenant_id')->nullable(); // FK ditambahkan setelah tabel tenants
            $t->unsignedBigInteger('last_tenant_id')->nullable();
            $t->string('locale', 5)->default('id');
            $t->string('timezone', 40)->default('Asia/Jakarta');
            $t->jsonb('notification_prefs')->default('{}');
            $t->timestampTz('blocked_at')->nullable();
            $t->rememberToken();
            $t->timestampTz('last_login_at')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX users_email_lower_uq ON users (lower(email))');

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
