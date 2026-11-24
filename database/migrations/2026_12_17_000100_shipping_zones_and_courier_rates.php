<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Tarif ekspedisi per zona (PLACEHOLDER — ganti angkanya di panel), metode `kurir`, dan kolom layanan/ETA pesanan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zone_rates', function (Blueprint $t) {
            $t->id();
            $t->string('zone_code', 32);
            $t->string('courier', 20);
            $t->string('service', 20);
            $t->unsignedInteger('base_cost');
            $t->unsignedInteger('per_kg_cost');
            $t->unsignedSmallInteger('etd_min_days');
            $t->unsignedSmallInteger('etd_max_days');
            $t->boolean('is_placeholder')->default(true);
            $t->timestampsTz();
            $t->unique(['zone_code', 'courier', 'service']);
        });

        $now = now();
        $base = ['Jawa-Bali' => 9000, 'Sumatera' => 14000, 'Kalimantan' => 18000, 'Sulawesi' => 18000, 'Nusa Tenggara & Maluku' => 25000, 'Papua' => 35000];
        $rows = [];
        foreach ($base as $zone => $cost) {
            foreach (['JNE' => 0, 'J&T' => -1000, 'SiCepat' => -2000] as $courier => $adj) {
                foreach (['REG' => [0, 0, 2, 4], 'YES' => [1500, 4000, 1, 2]] as $service => [$extra, $perKg, $min, $max]) {
                    $rows[] = ['zone_code' => $zone, 'courier' => $courier, 'service' => $service,
                        'base_cost' => $cost + $adj + $extra, 'per_kg_cost' => 4000 + $perKg, 'etd_min_days' => $min, 'etd_max_days' => $max,
                        'is_placeholder' => true, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }
        DB::table('shipping_zone_rates')->insert($rows);

        DB::table('shipping_methods')->insertOrIgnore([[
            'public_id' => (string) Str::ulid(), 'key' => 'kurir', 'name' => 'Ekspedisi (JNE / J&T / SiCepat)',
            'eta' => 'Sesuai layanan & zona', 'price' => 0, 'needs_address' => true, 'sort' => 1, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]]);

        Schema::table('orders', function (Blueprint $t) {
            $t->string('shipping_service', 20)->nullable()->after('courier');
            $t->string('shipping_etd', 40)->nullable()->after('shipping_service');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['shipping_service', 'shipping_etd']);
        });
        DB::table('shipping_methods')->where('key', 'kurir')->delete();
        Schema::dropIfExists('shipping_zone_rates');
    }
};
