<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login pengguna kini email + password (atau Google). Kode email hanya untuk verifikasi pendaftaran manual
 * (`register`, menyimpan nama & hash password yang menunggu verifikasi di `payload`) dan lupa password (`reset`).
 * `login` dipertahankan agar baris lama tetap sah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_otps', function (Blueprint $t) {
            $t->jsonb('payload')->nullable()->after('code_hash');
            $t->index(['email', 'purpose', 'consumed_at']);
        });
        // kode sekali pakai dari alur lama (mis. admin_reverify) sudah tidak dipakai & berumur 10 menit — buang
        DB::table('login_otps')->whereNotIn('purpose', ['login', 'register', 'reset'])->where('expires_at', '<', now())->delete();
        DB::statement("ALTER TABLE login_otps ADD CONSTRAINT login_otps_purpose_chk CHECK (purpose IN ('login','register','reset'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE login_otps DROP CONSTRAINT IF EXISTS login_otps_purpose_chk');
        Schema::table('login_otps', function (Blueprint $t) {
            $t->dropIndex(['email', 'purpose', 'consumed_at']);
            $t->dropColumn('payload');
        });
    }
};
