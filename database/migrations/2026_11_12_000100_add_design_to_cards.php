<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tampilan kartu digital mengikuti desain kartu (template atau desain Studio milik pemegang), sama seperti kartu
 * fisik — hanya saja QR dipakai sebagai pengganti NFC dan kartunya bisa diunduh sebagai gambar.
 * Desain dihapus → kartu kembali ke template bawaan (null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $t) {
            $t->foreignId('design_id')->nullable()->after('profile_id')->constrained('designs')->nullOnDelete();
            $t->index('design_id');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $t) {
            $t->dropIndex(['design_id']);
            $t->dropConstrainedForeignId('design_id');
        });
    }
};
