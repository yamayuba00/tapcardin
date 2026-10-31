<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Editor lanyard + brand kit bersama:
 *  - brand_kits: warna, logo, nama & tagline merek; dirujuk kartu & lanyard (kit:primary, kit:logo, {{brand}}).
 *  - lanyard_widths / lanyard_hardware: katalog & harga (dikelola panel pemilik, seeder awal di sini).
 *  - designs.kind = card | lanyard; lanyard menunjuk kartu pasangannya (paired_design_id, unik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_kits', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('name', 80);
            $t->char('primary_color', 7);
            $t->char('secondary_color', 7);
            $t->char('background_color', 7);
            $t->char('text_color', 7);
            $t->string('logo_path')->nullable();
            $t->unsignedInteger('logo_px_w')->nullable();
            $t->unsignedInteger('logo_px_h')->nullable();
            $t->string('display_name', 120)->nullable();
            $t->string('tagline', 160)->nullable();
            $t->string('website', 160)->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
            $t->index(['tenant_id', 'user_id']);
        });
        foreach (['primary_color', 'secondary_color', 'background_color', 'text_color'] as $c) {
            DB::statement("ALTER TABLE brand_kits ADD CONSTRAINT brand_kits_{$c}_chk CHECK ($c ~ '^#[0-9A-F]{6}$')");
        }

        Schema::create('lanyard_widths', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->unsignedSmallInteger('width_mm')->unique();
            $t->string('label', 60);
            $t->unsignedInteger('price_single'); // rupiah per pcs, cetak 1 sisi
            $t->unsignedInteger('price_double'); // rupiah per pcs, cetak 2 sisi
            $t->unsignedSmallInteger('min_qty')->default(10);
            $t->smallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->index(['is_active', 'sort']);
        });

        Schema::create('lanyard_hardware', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('key', 24)->unique();
            $t->string('name', 60);
            $t->string('description', 200)->nullable();
            $t->unsignedInteger('price')->default(0); // tambahan per pcs
            $t->smallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->index(['is_active', 'sort']);
        });

        Schema::table('designs', function (Blueprint $t) {
            $t->string('kind', 10)->default('card')->after('name');
            $t->foreignId('brand_kit_id')->nullable()->after('kind')->constrained('brand_kits')->nullOnDelete();
            $t->foreignId('paired_design_id')->nullable()->after('brand_kit_id')->constrained('designs')->nullOnDelete();
            $t->foreignId('lanyard_width_id')->nullable()->constrained('lanyard_widths')->nullOnDelete();
            $t->foreignId('lanyard_hardware_id')->nullable()->constrained('lanyard_hardware')->nullOnDelete();
            $t->unsignedSmallInteger('print_sides')->default(2);
            $t->unsignedSmallInteger('tile_mm')->nullable(); // panjang pola berulang lanyard
            $t->index(['tenant_id', 'user_id', 'kind']);
            $t->index('brand_kit_id');
        });
        DB::statement("ALTER TABLE designs ADD CONSTRAINT designs_kind_chk CHECK (kind IN ('card','lanyard'))");
        DB::statement('ALTER TABLE designs ADD CONSTRAINT designs_print_sides_chk CHECK (print_sides IN (1,2))');
        // satu kartu dipasangkan dengan paling banyak satu lanyard
        DB::statement('CREATE UNIQUE INDEX designs_paired_uq ON designs (paired_design_id) WHERE paired_design_id IS NOT NULL AND deleted_at IS NULL');

        $now = now();
        $row = fn (array $r) => $r + ['public_id' => (string) Str::ulid(), 'created_at' => $now, 'updated_at' => $now];
        foreach ([[15, 'Ramping 15 mm', 9000, 12000, 0], [20, 'Standar 20 mm', 11000, 14500, 1], [25, 'Lebar 25 mm', 13000, 17000, 2]] as [$w, $label, $s, $d, $sort]) {
            DB::table('lanyard_widths')->insert($row(['width_mm' => $w, 'label' => $label, 'price_single' => $s, 'price_double' => $d, 'min_qty' => 10, 'sort' => $sort, 'is_active' => true]));
        }
        foreach ([
            ['lobster', 'Kait lobster', 'Pengait logam standar, cocok untuk ID card holder.', 0],
            ['j_hook', 'J-hook', 'Kait J berputar, mudah dilepas-pasang.', 1500],
            ['badge_reel', 'Badge reel', 'Tali tarik-ulur untuk scan kartu tanpa melepas.', 6000],
            ['breakaway', 'Pengaman putus', 'Klip keselamatan di belakang leher (wajib di area pabrik).', 1500],
        ] as $sort => [$key, $name, $desc, $price]) {
            DB::table('lanyard_hardware')->insert($row(['key' => $key, 'name' => $name, 'description' => $desc, 'price' => $price, 'sort' => $sort, 'is_active' => true]));
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS designs_paired_uq');
        Schema::table('designs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('lanyard_hardware_id');
            $t->dropConstrainedForeignId('lanyard_width_id');
            $t->dropConstrainedForeignId('paired_design_id');
            $t->dropConstrainedForeignId('brand_kit_id');
            $t->dropIndex(['tenant_id', 'user_id', 'kind']);
            $t->dropColumn(['kind', 'print_sides', 'tile_mm']);
        });
        Schema::dropIfExists('lanyard_hardware');
        Schema::dropIfExists('lanyard_widths');
        Schema::dropIfExists('brand_kits');
    }
};
