<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Password hanya untuk staf panel pemilik (admin.); pengguna workspace tetap login via OTP/Google. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('password')->nullable()->after('google_id');
            $t->timestampTz('password_changed_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['password', 'password_changed_at']));
    }
};
