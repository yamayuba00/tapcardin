<?php

namespace Tests\Unit;

use App\Support\Design\Palette;
use PHPUnit\Framework\TestCase;

/** Pustaka motif bawaan: hanya memakai nama palet (tanpa hex) agar selalu konsisten dengan tema & validasi. */
class MotifCatalogTest extends TestCase
{
    public function test_motif_catalog_uses_only_palette_names_and_no_hex(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/resources/js/lib/motifs.js');
        $this->assertNotFalse($src);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $src, 'motifs.js tidak boleh memuat hex');

        preg_match_all('/colors: \[([^\]]*)\]/', $src, $blocks);
        $names = [];
        foreach ($blocks[1] as $block) {
            preg_match_all("/'([^']+)'/", $block, $m);
            array_push($names, ...$m[1]);
        }

        $this->assertNotEmpty($names);
        foreach (array_unique($names) as $name) {
            $this->assertArrayHasKey($name, Palette::COLORS, "Warna motif '$name' tidak ada di App\Support\Design\Palette");
        }
    }

    public function test_motif_keys_are_unique_and_there_are_twelve(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/resources/js/lib/motifs.js');
        preg_match_all("/key: '([^']+)'/", $src, $m);
        $this->assertCount(12, $m[1]);
        $this->assertSame(count($m[1]), count(array_unique($m[1])));
    }
}
