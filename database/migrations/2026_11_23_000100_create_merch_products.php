<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Desain merchandise (jaket, kaos/baju, tote bag, mug, topi): editor sudah bisa dipakai, pemesanan cetak "segera hadir".
 * Katalog produk menyimpan area cetak (mm) & jumlah sisi; desain merch menunjuk satu produk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merch_products', function (Blueprint $t) {
            $t->id();
            $t->string('key', 24)->unique();
            $t->string('name', 60);
            $t->string('category', 10);
            $t->string('silhouette', 16);
            $t->unsignedSmallInteger('print_w_mm');
            $t->unsignedSmallInteger('print_h_mm');
            $t->unsignedSmallInteger('print_sides')->default(2);
            $t->string('description', 200)->nullable();
            $t->string('status', 12)->default('coming_soon');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE merch_products ADD CONSTRAINT merch_products_category_chk CHECK (category IN ('apparel','merch'))");
        DB::statement("ALTER TABLE merch_products ADD CONSTRAINT merch_products_silhouette_chk CHECK (silhouette IN ('tshirt','jacket','totebag','mug','cap'))");
        DB::statement("ALTER TABLE merch_products ADD CONSTRAINT merch_products_status_chk CHECK (status IN ('coming_soon','available'))");
        DB::statement('ALTER TABLE merch_products ADD CONSTRAINT merch_products_print_chk CHECK (print_w_mm BETWEEN 20 AND 600 AND print_h_mm BETWEEN 20 AND 600 AND print_sides IN (1,2))');

        $now = now();
        $rows = [
            ['tshirt', 'Kaos / Baju', 'apparel', 'tshirt', 290, 400, 2, 'Sablon DTF bagian depan & belakang, area cetak maks. A3.'],
            ['jacket', 'Jaket', 'apparel', 'jacket', 280, 350, 2, 'Bordir/DTF depan & punggung untuk tim, komunitas, atau event.'],
            ['totebag', 'Tote bag', 'merch', 'totebag', 280, 320, 2, 'Tas kanvas dua sisi untuk goodie bag & souvenir.'],
            ['mug', 'Mug', 'merch', 'mug', 200, 85, 1, 'Cetak keliling mug 11 oz (wrap).'],
            ['cap', 'Topi', 'merch', 'cap', 100, 55, 1, 'Bordir/print bagian depan topi.'],
        ];
        foreach ($rows as $i => [$key, $name, $category, $silhouette, $w, $h, $sides, $desc]) {
            DB::table('merch_products')->insert([
                'key' => $key, 'name' => $name, 'category' => $category, 'silhouette' => $silhouette, 'print_w_mm' => $w, 'print_h_mm' => $h,
                'print_sides' => $sides, 'description' => $desc, 'status' => 'coming_soon', 'sort' => $i, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('designs', function (Blueprint $t) {
            $t->foreignId('merch_product_id')->nullable()->index()->after('lanyard_hardware_id')->constrained('merch_products')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE designs DROP CONSTRAINT designs_kind_chk');
        DB::statement("ALTER TABLE designs ADD CONSTRAINT designs_kind_chk CHECK (kind IN ('card','lanyard','merch'))");
        DB::statement("ALTER TABLE designs ADD CONSTRAINT designs_merch_product_chk CHECK ((kind = 'merch') = (merch_product_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::table('designs')->where('kind', 'merch')->delete();
        DB::statement('ALTER TABLE designs DROP CONSTRAINT designs_merch_product_chk');
        DB::statement('ALTER TABLE designs DROP CONSTRAINT designs_kind_chk');
        DB::statement("ALTER TABLE designs ADD CONSTRAINT designs_kind_chk CHECK (kind IN ('card','lanyard'))");
        Schema::table('designs', fn (Blueprint $t) => $t->dropConstrainedForeignId('merch_product_id'));
        Schema::dropIfExists('merch_products');
    }
};
