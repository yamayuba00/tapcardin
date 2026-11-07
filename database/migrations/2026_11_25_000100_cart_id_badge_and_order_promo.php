<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pemesanan cetak lebih lengkap:
 *  - card_products.form_factor: kartu biasa atau ID badge (kartu potret berlubang lanyard) sebagai produk sendiri;
 *  - keranjang (cart_items): kartu, ID badge, & lanyard ditambah satu-satu; checkout pilih item mana saja;
 *  - kode promo untuk pesanan: vouchers.applies_to (plan | order | any), orders.discount + voucher_id;
 *  - alamat kirim lebih lengkap: orders.district (kecamatan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_products', function (Blueprint $t) {
            $t->string('form_factor', 10)->default('card')->after('chip_type');
        });
        DB::statement("ALTER TABLE card_products ADD CONSTRAINT card_products_form_chk CHECK (form_factor IN ('card','id_badge'))");
        $now = now();
        DB::table('card_products')->insert([
            'public_id' => (string) Str::ulid(), 'key' => 'id_badge_ntag215', 'name' => 'ID Badge PVC NFC',
            'description' => 'Kartu ID potret dengan lubang lanyard, cetak penuh dua sisi, chip NFC.',
            'chip_type' => 'ntag215', 'form_factor' => 'id_badge', 'price' => 60000, 'min_qty' => 1, 'sort' => 5, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        Schema::table('vouchers', function (Blueprint $t) {
            $t->string('applies_to', 8)->default('plan')->after('plan_keys');
        });
        DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_applies_chk CHECK (applies_to IN ('plan','order','any'))");

        Schema::table('orders', function (Blueprint $t) {
            $t->string('district', 80)->nullable()->after('address_line');
            $t->bigInteger('discount')->default(0)->after('shipping_fee');
            $t->foreignId('voucher_id')->nullable()->after('discount')->constrained('vouchers')->nullOnDelete();
            $t->index('voucher_id');
        });
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_amounts_chk');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_chk CHECK
            (subtotal >= 0 AND shipping_fee >= 0 AND discount >= 0 AND discount <= subtotal AND total = subtotal + shipping_fee - discount)');

        Schema::create('cart_items', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('design_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10);
            $t->foreignId('card_product_id')->nullable()->constrained('card_products')->cascadeOnDelete();
            $t->unsignedInteger('quantity');
            $t->timestampsTz();
            $t->index(['tenant_id', 'user_id']);
            $t->unique(['user_id', 'design_id', 'card_product_id']);
        });
        DB::statement("ALTER TABLE cart_items ADD CONSTRAINT cart_items_kind_chk CHECK
            ((kind = 'card' AND card_product_id IS NOT NULL) OR (kind = 'lanyard' AND card_product_id IS NULL))");
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_qty_chk CHECK (quantity BETWEEN 1 AND 5000)');
        // satu lanyard per desain di keranjang (unique di atas tidak berlaku saat card_product_id NULL)
        DB::statement("CREATE UNIQUE INDEX cart_items_lanyard_once ON cart_items (user_id, design_id) WHERE kind = 'lanyard'");
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_amounts_chk');
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('voucher_id');
            $t->dropColumn(['district', 'discount']);
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_chk CHECK (subtotal >= 0 AND shipping_fee >= 0 AND total = subtotal + shipping_fee)');
        DB::statement('ALTER TABLE vouchers DROP CONSTRAINT vouchers_applies_chk');
        Schema::table('vouchers', fn (Blueprint $t) => $t->dropColumn('applies_to'));
        DB::table('card_products')->where('form_factor', 'id_badge')->delete();
        DB::statement('ALTER TABLE card_products DROP CONSTRAINT card_products_form_chk');
        Schema::table('card_products', fn (Blueprint $t) => $t->dropColumn('form_factor'));
    }
};
