<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembuatan token batch di latar (per 50 kartu, GenerateBatchChunk): kapan dimulai & error terakhir.
 * Progres = jumlah kartu batch yang sudah ada; null = tidak sedang berjalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $t) {
            $t->timestampTz('tokens_started_at')->nullable();
            $t->string('tokens_error', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('batches', fn (Blueprint $t) => $t->dropColumn(['tokens_started_at', 'tokens_error']));
    }
};
