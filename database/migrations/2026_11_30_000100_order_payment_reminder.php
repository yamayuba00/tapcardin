<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pesanan belum dibayar: kapan pengingat "akan dibatalkan" dikirim (sekali), sebelum batal otomatis (tapcard:expire-orders). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->timestampTz('payment_reminded_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('payment_reminded_at'));
    }
};
