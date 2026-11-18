<?php

namespace Tests\Feature;

use App\Support\Design\Fonts;
use App\Support\Design\TextLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Studio fleksibel tahap 1: koleksi font, kotak teks (bungkus + perkecil), teks melengkung, garis tepi huruf. */
class StudioRichTextTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    private function saveFront(array $layers, string $orientation = 'landscape'): array
    {
        $d = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => $orientation])->assertCreated()->json('data');
        $front = $d['front'];
        $front['layers'] = array_merge($front['layers'], $layers);

        return $this->api('PATCH', "/designs/{$d['id']}", ['front' => $front])->assertOk()->json('data');
    }

    public function test_font_collection_is_open_licensed_and_served_from_public_fonts(): void
    {
        $fonts = Fonts::all();
        $this->assertGreaterThanOrEqual(28, count($fonts));
        foreach ($fonts as $f) {
            $this->assertFileExists(public_path("fonts/{$f['slug']}-LICENSE.txt"), $f['family']);
            $this->assertStringContainsString('Open Font License', (string) file_get_contents(public_path("fonts/{$f['slug']}-LICENSE.txt")));
            foreach ($f['weights'] as $w) {
                $this->assertFileExists(public_path("fonts/{$f['slug']}-$w.woff2"), "{$f['family']} $w");
            }
        }
        $this->actingAs($this->makeUser());
        $this->assertSame([400, 600, 800], $this->api('GET', '/studio/templates')->assertOk()->json('data.fonts')['Plus Jakarta Sans']);
    }

    public function test_text_props_are_clamped_and_unknown_font_falls_back(): void
    {
        $this->actingAs($this->makeUser());
        $layers = collect($this->saveFront([
            ['id' => 'a', 'type' => 'text', 'text' => 'Halo', 'x' => 20, 'y' => 20, 'size' => 9, 'font' => 'Comic Sans', 'weight' => 900, 'tracking' => 5, 'outline' => '#112233', 'outlineWidth' => 9],
            ['id' => 'b', 'type' => 'text', 'text' => 'Montserrat', 'x' => 20, 'y' => 30, 'size' => 9, 'font' => 'Montserrat', 'weight' => 650, 'tracking' => -1],
        ])['front']['layers'])->keyBy('id');

        $this->assertSame('IBM Plex Sans', $layers['a']['font']);
        $this->assertSame(400, $layers['a']['weight']);
        $this->assertEquals(1, $layers['a']['tracking']);
        $this->assertSame('#112233', $layers['a']['outline']);
        $this->assertEquals(2, $layers['a']['outlineWidth']);
        $this->assertSame('Montserrat', $layers['b']['font']);
        $this->assertSame(400, $layers['b']['weight']); // tebal tidak tersedia → tebal pertama
        $this->assertEquals(-0.1, $layers['b']['tracking']);
    }

    public function test_text_box_wraps_shrinks_to_fit_and_warns_when_it_cannot(): void
    {
        $this->actingAs($this->makeUser());
        $long = 'Kantor pusat Jalan Merdeka Nomor 12 Jakarta Pusat Indonesia';
        $d = $this->saveFront([
            ['id' => 'fit', 'type' => 'text', 'box' => true, 'text' => $long, 'x' => 10, 'y' => 10, 'w' => 40, 'h' => 12, 'size' => 14, 'lineHeight' => 1.2, 'valign' => 'middle'],
            ['id' => 'over', 'type' => 'text', 'box' => true, 'text' => str_repeat('panjang sekali ', 30), 'x' => 10, 'y' => 30, 'w' => 20, 'h' => 4, 'size' => 10],
        ]);
        $layers = collect($d['front']['layers'])->keyBy('id');
        $fit = $layers['fit'];
        $this->assertTrue($fit['box']);
        $this->assertSame('middle', $fit['valign']);
        $this->assertArrayNotHasKey('fitWidth', $fit);

        $r = TextLayout::fitBox($fit);
        $this->assertLessThan(14, $r['size']);
        $this->assertFalse($r['overflow']);
        $this->assertGreaterThan(1, count($r['lines']));
        $issues = collect($d['issues']);
        $this->assertFalse($issues->contains(fn ($i) => $i['layer'] === 'fit'));
        $this->assertTrue($issues->contains(fn ($i) => $i['layer'] === 'over' && str_contains($i['message'], 'tidak muat')));
    }

    public function test_text_box_outside_safe_zone_is_flagged_like_other_content(): void
    {
        $this->actingAs($this->makeUser());
        $issues = collect($this->saveFront([
            ['id' => 'edge', 'type' => 'text', 'box' => true, 'text' => 'Tepi', 'x' => 1, 'y' => 10, 'w' => 30, 'h' => 8, 'size' => 9],
        ])['issues']);
        $this->assertTrue($issues->contains(fn ($i) => $i['layer'] === 'edge' && str_contains($i['message'], 'safe zone')));
    }

    public function test_curved_text_is_single_line_and_its_arc_is_checked_against_the_safe_zone(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->saveFront([
            ['id' => 'ok', 'type' => 'text', 'text' => "Segel\nResmi", 'x' => 42.8, 'y' => 20, 'size' => 9, 'curve' => 'up', 'curveR' => 15, 'curveAngle' => 0, 'fitWidth' => 30],
            ['id' => 'big', 'type' => 'text', 'text' => 'LINGKARAN BESAR SEKALI', 'x' => 42.8, 'y' => 10, 'size' => 12, 'curve' => 'down', 'curveR' => 200, 'curveAngle' => 999],
        ]);
        $layers = collect($d['front']['layers'])->keyBy('id');
        $this->assertSame('Segel Resmi', $layers['ok']['text']);
        $this->assertArrayNotHasKey('fitWidth', $layers['ok']);
        $this->assertArrayNotHasKey('curveAngle', $layers['ok']);
        $this->assertEquals(80, $layers['big']['curveR']);
        $this->assertEquals(180, $layers['big']['curveAngle']);

        $box = TextLayout::curveBox($layers['ok']);
        $this->assertLessThan(20, $box['y']); // teks berada di atas titik jangkar
        $this->assertEqualsWithDelta(42.8, $box['x'] + $box['w'] / 2, 0.01); // simetris di sekitar jangkar
        $issues = collect($d['issues']);
        $this->assertFalse($issues->contains(fn ($i) => $i['layer'] === 'ok'));
        $this->assertTrue($issues->contains(fn ($i) => $i['layer'] === 'big' && str_contains($i['message'], 'safe zone')));
    }

    public function test_small_script_font_gets_a_legibility_warning(): void
    {
        $this->actingAs($this->makeUser());
        $issues = collect($this->saveFront([
            ['id' => 'tiny', 'type' => 'text', 'text' => 'Kepala Divisi', 'x' => 20, 'y' => 30, 'size' => 7, 'font' => 'Great Vibes'],
            ['id' => 'fine', 'type' => 'text', 'text' => 'Kepala Divisi', 'x' => 20, 'y' => 40, 'size' => 7, 'font' => 'Inter'],
        ])['issues']);
        $this->assertTrue($issues->contains(fn ($i) => $i['layer'] === 'tiny' && str_contains($i['message'], 'tulisan tangan')));
        $this->assertFalse($issues->contains(fn ($i) => $i['layer'] === 'fine'));
    }

    public function test_existing_designs_keep_their_text_measurements(): void
    {
        // faktor lebar 3 font lama tidak berubah → auto-fit desain lama tetap sama
        $this->assertSame(0.55, Fonts::width('IBM Plex Sans'));
        $this->assertSame(0.55, Fonts::width('Syne'));
        $this->assertSame(0.6, Fonts::width('IBM Plex Mono'));
        $this->assertSame(0.55, Fonts::width('Tidak Ada'));
    }
}
