<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estimasi cetak: lead time per produk kartu (hari kerja), tanggal jadwal per item pesanan (disimpan saat lunas),
 * dan pemakaian kapasitas harian (order_item_schedules: berapa kartu dicetak pada hari tertentu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_products', function (Blueprint $t) {
            $t->unsignedSmallInteger('print_min_days')->default(3)->after('min_qty');
            $t->unsignedSmallInteger('print_max_days')->default(7)->after('print_min_days');
        });
        DB::statement('ALTER TABLE card_products ADD CONSTRAINT card_products_print_days_chk CHECK (print_min_days BETWEEN 1 AND 60 AND print_max_days BETWEEN print_min_days AND 60)');

        Schema::table('order_items', function (Blueprint $t) {
            $t->date('print_starts_on')->nullable();
            $t->date('print_eta_min')->nullable();
            $t->date('print_eta_max')->nullable();
            $t->unsignedSmallInteger('print_delay_days')->default(0);
        });

        Schema::create('order_item_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $t->date('day');
            $t->unsignedInteger('quantity');
            $t->timestampTz('created_at')->nullable();
            $t->index('day');
        });
        DB::statement('ALTER TABLE order_item_schedules ADD CONSTRAINT order_item_schedules_qty_chk CHECK (quantity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_schedules');
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropColumn(['print_starts_on', 'print_eta_min', 'print_eta_max', 'print_delay_days']);
        });
        DB::statement('ALTER TABLE card_products DROP CONSTRAINT IF EXISTS card_products_print_days_chk');
        Schema::table('card_products', function (Blueprint $t) {
            $t->dropColumn(['print_min_days', 'print_max_days']);
        });
    }
};
