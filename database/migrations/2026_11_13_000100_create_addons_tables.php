<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Add-on berbayar terpisah dari paket (mis. Integrasi CRM). Selama `coming_soon`, workspace bisa mendaftar minat
 * (`addon_interests`, satu per workspace per add-on) — dipakai untuk mengabari & mengukur permintaan sebelum
 * pembayaran add-on dibuka. Harga & status dikelola dari panel (Katalog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('key', 32)->unique();
            $t->string('name', 80);
            $t->string('description', 300);
            $t->jsonb('features')->default('[]'); // daftar poin manfaat
            $t->integer('price_monthly')->nullable(); // perkiraan; null = belum ditentukan
            $t->string('status', 16)->default('coming_soon');
            $t->smallInteger('sort')->default(0);
            $t->timestampsTz();
            $t->index(['status', 'sort']);
        });
        DB::statement("ALTER TABLE addons ADD CONSTRAINT addons_status_chk CHECK (status IN ('coming_soon','available','retired'))");
        DB::statement('ALTER TABLE addons ADD CONSTRAINT addons_price_chk CHECK (price_monthly IS NULL OR price_monthly >= 0)');

        Schema::create('addon_interests', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('note', 200)->nullable(); // mis. CRM yang dipakai
            $t->timestampTz('notified_at')->nullable(); // sudah dikabari saat add-on tersedia
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['addon_id', 'tenant_id']);
            $t->index('tenant_id');
            $t->index('user_id');
        });

        DB::table('addons')->insert([
            'public_id' => (string) Str::ulid(), 'key' => 'crm', 'name' => 'Integrasi CRM', 'sort' => 0, 'status' => 'coming_soon',
            'description' => 'Kirim koneksi & lead otomatis ke CRM yang sudah Anda pakai — tanpa ekspor CSV manual.',
            'features' => json_encode(['Sinkron otomatis ke Google Sheets', 'Kontak & deal baru di HubSpot', 'Pemetaan kolom & tahap pipeline', 'Riwayat sinkron & kirim ulang bila gagal']),
            'price_monthly' => 29000, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_interests');
        Schema::dropIfExists('addons');
    }
};
