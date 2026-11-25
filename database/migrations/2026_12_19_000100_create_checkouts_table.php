<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Draf checkout: satu sesi pemilihan item keranjang + alamat + promo, diberi ID (ULID) yang masuk ke URL
 * /orders/new/{id}?step=… sehingga langkah wizard bisa dibuka ulang. Pesanan baru dibuat saat `pay`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->json('cart_ids'); // public_id item keranjang yang dipilih
            $t->json('shipping')->nullable();
            $t->string('promo', 32)->nullable();
            $t->string('status', 10)->default('open');
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->timestampTz('expires_at');
            $t->timestampsTz();
            $t->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE checkouts ADD CONSTRAINT checkouts_status_chk CHECK (status IN ('open','paid'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
