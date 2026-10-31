<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pemesanan kartu fisik & lanyard: katalog produk kartu & metode kirim → pesanan (orders, order_items) dibayar lewat
 * payment service (invoice type `order`) → produksi (batch per item kartu, kartu ditautkan ke item pesanan) → kirim.
 * Desain dibekukan sebagai snapshot saat dipesan agar yang dicetak = yang dibayar walau desain diubah setelahnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_products', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('key', 32)->unique();
            $t->string('name', 80);
            $t->string('description', 200)->nullable();
            $t->string('chip_type', 16);
            $t->unsignedInteger('price'); // rupiah per kartu
            $t->unsignedSmallInteger('min_qty')->default(1);
            $t->smallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->index(['is_active', 'sort']);
        });
        DB::statement("ALTER TABLE card_products ADD CONSTRAINT card_products_chip_chk CHECK (chip_type IN ('ntag213','ntag215','ntag216','ntag424'))");

        Schema::create('shipping_methods', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('key', 32)->unique();
            $t->string('name', 80);
            $t->string('eta', 60)->nullable(); // mis. "2–4 hari kerja"
            $t->unsignedInteger('price')->default(0);
            $t->boolean('needs_address')->default(true); // false = ambil sendiri
            $t->smallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->index(['is_active', 'sort']);
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('number', 24)->unique(); // ORD/2026/11/000123
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // pemesan & calon pemegang kartu
            $t->string('status', 20)->default('pending_payment');
            $t->bigInteger('subtotal');
            $t->bigInteger('shipping_fee')->default(0);
            $t->bigInteger('total');
            $t->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $t->string('recipient_name', 120)->nullable();
            $t->string('recipient_phone', 32)->nullable();
            $t->string('address_line', 300)->nullable();
            $t->string('city', 80)->nullable();
            $t->string('province', 80)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('notes', 300)->nullable();
            $t->string('courier', 40)->nullable();
            $t->string('tracking_number', 60)->nullable();
            $t->string('cancel_reason', 200)->nullable();
            foreach (['paid_at', 'production_started_at', 'shipped_at', 'delivered_at', 'cancelled_at'] as $c) {
                $t->timestampTz($c)->nullable();
            }
            $t->timestampsTz();
            $t->index(['tenant_id', 'created_at']);
            $t->index(['user_id', 'created_at']);
            $t->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_chk CHECK
            (status IN ('pending_payment','paid','in_production','shipped','delivered','cancelled'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_chk CHECK (subtotal >= 0 AND shipping_fee >= 0 AND total = subtotal + shipping_fee)');

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10); // card | lanyard
            $t->foreignId('design_id')->nullable()->constrained('designs')->nullOnDelete(); // asal; isi yang dicetak = snapshot
            $t->string('label', 160);
            $t->foreignId('card_product_id')->nullable()->constrained('card_products');
            $t->foreignId('lanyard_width_id')->nullable()->constrained('lanyard_widths');
            $t->foreignId('lanyard_hardware_id')->nullable()->constrained('lanyard_hardware');
            $t->unsignedSmallInteger('print_sides')->nullable();
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('unit_price');
            $t->bigInteger('total');
            $t->jsonb('design_snapshot'); // {name, orientation, front, back, kit, tile_mm?, width_mm?}
            $t->timestampsTz();
            $t->index('order_id');
            $t->index('design_id');
        });
        DB::statement("ALTER TABLE order_items ADD CONSTRAINT order_items_kind_chk CHECK (
            (kind = 'card' AND card_product_id IS NOT NULL AND lanyard_width_id IS NULL)
            OR (kind = 'lanyard' AND lanyard_width_id IS NOT NULL AND lanyard_hardware_id IS NOT NULL AND print_sides IN (1,2) AND card_product_id IS NULL))");
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_qty_chk CHECK (quantity BETWEEN 1 AND 5000 AND total = unit_price * quantity)');

        // invoice pesanan ↔ pesanan; batch produksi & kartu ↔ item pesanan
        Schema::table('invoices', function (Blueprint $t) {
            $t->foreignId('order_id')->nullable()->after('plan_id')->constrained('orders')->nullOnDelete();
            $t->index('order_id');
        });
        Schema::table('batches', function (Blueprint $t) {
            $t->foreignId('order_item_id')->nullable()->after('holder_user_id')->constrained('order_items')->nullOnDelete();
            $t->index('order_item_id');
        });
        Schema::table('cards', function (Blueprint $t) {
            $t->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
            $t->index('order_item_id');
        });

        $now = now();
        DB::table('card_products')->insert([
            ['public_id' => (string) Str::ulid(), 'key' => 'pvc_ntag215', 'name' => 'Kartu PVC NFC', 'chip_type' => 'ntag215', 'price' => 65000, 'min_qty' => 1, 'sort' => 0,
                'description' => 'PVC 0,76 mm, cetak penuh dua sisi, chip NFC + QR. Cocok untuk sebagian besar pemakaian.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'key' => 'pvc_ntag424', 'name' => 'Kartu PVC NFC Secure', 'chip_type' => 'ntag424', 'price' => 95000, 'min_qty' => 1, 'sort' => 1,
                'description' => 'Chip NTAG 424 DNA anti-salin untuk kartu karyawan & akses.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('shipping_methods')->insert([
            ['public_id' => (string) Str::ulid(), 'key' => 'regular', 'name' => 'Pengiriman reguler', 'eta' => '3–5 hari kerja setelah produksi', 'price' => 20000, 'needs_address' => true, 'sort' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'key' => 'express', 'name' => 'Pengiriman ekspres', 'eta' => '1–2 hari kerja setelah produksi', 'price' => 40000, 'needs_address' => true, 'sort' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['public_id' => (string) Str::ulid(), 'key' => 'pickup', 'name' => 'Ambil sendiri', 'eta' => 'Kami kabari saat siap diambil', 'price' => 0, 'needs_address' => false, 'sort' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $t) {
            $t->dropForeign(['order_item_id']);
            $t->dropIndex(['order_item_id']);
        });
        Schema::table('batches', fn (Blueprint $t) => $t->dropConstrainedForeignId('order_item_id'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropConstrainedForeignId('order_id'));
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('card_products');
    }
};
