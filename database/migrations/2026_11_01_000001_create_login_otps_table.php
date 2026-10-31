<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_otps', function (Blueprint $t) {
            $t->id();
            $t->string('email', 190)->index();
            $t->string('purpose', 16)->default('login');
            $t->string('code_hash');
            $t->smallInteger('attempts')->default(0);
            $t->timestampTz('expires_at');
            $t->timestampTz('consumed_at')->nullable();
            $t->char('ip_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_otps');
    }
};
