<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tombol "Simpan ke Kontak HP" menjadi blok bio (bisa diganti teksnya, dipindah, disembunyikan per bio).
 * Setiap halaman bio yang ada mendapat blok ini di urutan teratas, jadi tampilannya tetap seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bio_blocks DROP CONSTRAINT bio_blocks_type_chk');
        DB::statement("ALTER TABLE bio_blocks ADD CONSTRAINT bio_blocks_type_chk CHECK
            (type IN ('save_contact','contacts','button','text','video','gallery','divider'))");

        $now = now();
        DB::table('bio_pages')->orderBy('id')->select('id', 'tenant_id')->chunkById(500, function ($pages) use ($now) {
            foreach ($pages as $page) {
                if (DB::table('bio_blocks')->where('bio_page_id', $page->id)->where('type', 'save_contact')->exists()) {
                    continue;
                }
                DB::table('bio_blocks')->where('bio_page_id', $page->id)->increment('sort');
                DB::table('bio_blocks')->insert([
                    'public_id' => (string) Str::ulid(), 'tenant_id' => $page->tenant_id, 'bio_page_id' => $page->id,
                    'type' => 'save_contact', 'data' => json_encode(['label' => 'Simpan ke Kontak HP']), 'sort' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('bio_blocks')->where('type', 'save_contact')->delete();
        DB::statement('ALTER TABLE bio_blocks DROP CONSTRAINT bio_blocks_type_chk');
        DB::statement("ALTER TABLE bio_blocks ADD CONSTRAINT bio_blocks_type_chk CHECK
            (type IN ('contacts','button','text','video','gallery','divider'))");
    }
};
