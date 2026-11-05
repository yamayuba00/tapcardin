<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Blok Galeri dihapus dari bio link (tanpa unggah gambar). Blok galeri yang ada dihapus beserta file gambarnya,
 * lalu jenis blok yang diizinkan dipersempit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bio_blocks')->where('type', 'gallery')->orderBy('id')->select('id', 'data')->chunkById(500, function ($blocks) {
            $paths = [];
            foreach ($blocks as $b) {
                foreach (json_decode($b->data, true)['images'] ?? [] as $img) {
                    if (is_string($img['path'] ?? null) && preg_match('#^t\d+/bio/[A-Za-z0-9]{32}\.webp$#', $img['path'])) {
                        $paths[] = $img['path'];
                    }
                }
            }
            if ($paths) {
                Storage::disk('public')->delete($paths);
            }
            DB::table('bio_blocks')->whereIn('id', $blocks->pluck('id'))->delete();
        });

        DB::statement('ALTER TABLE bio_blocks DROP CONSTRAINT bio_blocks_type_chk');
        DB::statement("ALTER TABLE bio_blocks ADD CONSTRAINT bio_blocks_type_chk CHECK
            (type IN ('save_contact','contacts','button','text','video','divider'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bio_blocks DROP CONSTRAINT bio_blocks_type_chk');
        DB::statement("ALTER TABLE bio_blocks ADD CONSTRAINT bio_blocks_type_chk CHECK
            (type IN ('save_contact','contacts','button','text','video','gallery','divider'))");
    }
};
