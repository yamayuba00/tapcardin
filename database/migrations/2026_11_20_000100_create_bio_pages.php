<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Bio link berbasis blok: satu profil (= satu kartu) punya maks. 3 halaman bio, hanya satu yang aktif
 * (dijamin unique index parsial). Identitas & kontak tetap milik profil; tiap halaman punya tema & susunan blok sendiri.
 * Profil lama mendapat halaman "Utama" aktif berisi blok Kontak & tautan, jadi tampilannya tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bio_pages', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('profile_id')->index()->constrained()->cascadeOnDelete();
            $t->string('name', 40);
            $t->boolean('is_active')->default(false);
            $t->jsonb('theme')->default('{}');
            $t->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX bio_pages_one_active ON bio_pages (profile_id) WHERE is_active');

        Schema::create('bio_blocks', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('bio_page_id')->constrained()->cascadeOnDelete();
            $t->string('type', 16);
            $t->jsonb('data')->default('{}');
            $t->boolean('is_visible')->default(true);
            $t->smallInteger('sort')->default(0);
            $t->timestampsTz();
            $t->index(['bio_page_id', 'sort']);
        });
        DB::statement("ALTER TABLE bio_blocks ADD CONSTRAINT bio_blocks_type_chk CHECK
            (type IN ('contacts','button','text','video','gallery','divider'))");
        DB::statement('ALTER TABLE bio_blocks ADD CONSTRAINT bio_blocks_sort_chk CHECK (sort >= 0)');

        $now = now();
        DB::table('profiles')->whereNull('deleted_at')->orderBy('id')
            ->select('id', 'tenant_id', 'theme')->chunkById(500, function ($profiles) use ($now) {
                foreach ($profiles as $p) {
                    $pageId = DB::table('bio_pages')->insertGetId([
                        'public_id' => (string) Str::ulid(), 'tenant_id' => $p->tenant_id, 'profile_id' => $p->id,
                        'name' => 'Utama', 'is_active' => true, 'theme' => $p->theme ?? '{}', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('bio_blocks')->insert([
                        'public_id' => (string) Str::ulid(), 'tenant_id' => $p->tenant_id, 'bio_page_id' => $pageId,
                        'type' => 'contacts', 'data' => '{}', 'sort' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('bio_blocks');
        Schema::dropIfExists('bio_pages');
    }
};
