<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desain depan & belakang untuk batch stok jual langsung (door-to-door): dibuat & diubah di Design Studio akun
 * petugas, dipilih saat Tambah stok, bisa diganti kapan saja. Desain dihapus → batch tetap ada tanpa desain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', fn (Blueprint $t) => $t->foreignId('design_id')->nullable()->constrained('designs')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('batches', fn (Blueprint $t) => $t->dropConstrainedForeignId('design_id'));
    }
};
