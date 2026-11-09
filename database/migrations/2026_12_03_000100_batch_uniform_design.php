<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch "desain seragam": semua kartu tampak sama (tanpa QR/kode unik tercetak) — mis. stok jualan door-to-door.
 * Chip yang lolos verifikasi saat encode langsung dihitung lolos QC, dan kartu polosnya bisa ditulis di tempat
 * dari halaman Jual kartu. Tidak butuh bundle CSV vendor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', fn (Blueprint $t) => $t->boolean('uniform')->default(false));
    }

    public function down(): void
    {
        Schema::table('batches', fn (Blueprint $t) => $t->dropColumn('uniform'));
    }
};
