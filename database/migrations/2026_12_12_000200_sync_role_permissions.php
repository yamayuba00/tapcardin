<?php

use Database\Seeders\AuthzSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Sinkronkan izin peran sistem dari App\Authz\Catalog ke database (Manager kini boleh menugaskan kartu timnya).
 * deploy.sh hanya menjalankan db:seed sekali, jadi perubahan katalog peran dikirim lewat migrasi. Idempoten.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new AuthzSeeder)->run();
    }

    public function down(): void
    {
        // izin peran sistem selalu mengikuti katalog di kode; tidak ada yang perlu dikembalikan
    }
};
