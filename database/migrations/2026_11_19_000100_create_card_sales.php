<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan kartu langsung (aktivasi di tempat oleh staf): siapa pembeli & penjual, nominal, dan status
 * bayar (QRIS / manual) yang ditandai staf di panel. Satu kartu = satu catatan penjualan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_sales', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('card_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('buyer_user_id')->index()->constrained('users')->restrictOnDelete();
            $t->foreignId('sold_by_user_id')->index()->constrained('users')->restrictOnDelete();
            $t->integer('amount')->default(0);
            $t->string('status', 10)->default('unpaid');
            $t->string('payment_method', 10)->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->string('note', 200)->nullable();
            $t->timestampsTz();
            $t->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE card_sales ADD CONSTRAINT card_sales_status_chk CHECK (status IN ('unpaid','paid'))");
        DB::statement("ALTER TABLE card_sales ADD CONSTRAINT card_sales_method_chk CHECK (payment_method IS NULL OR payment_method IN ('qris','manual'))");
        DB::statement('ALTER TABLE card_sales ADD CONSTRAINT card_sales_amount_chk CHECK (amount >= 0)');
        DB::statement("ALTER TABLE card_sales ADD CONSTRAINT card_sales_paid_chk CHECK (status <> 'paid' OR (payment_method IS NOT NULL AND paid_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('card_sales');
    }
};
