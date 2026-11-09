<?php

namespace Tests\Feature;

use App\Support\Design\CardGeometry;
use App\Support\Design\DesignValidator;
use App\Support\Design\Palette;
use App\Support\Design\TemplateLibrary;
use App\Support\Design\Variables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** PRD §10.3 — Design Studio: template default, elemen wajib terkunci, validasi cetak & lanyard. */
class DesignStudioTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const SYSTEM_FRONT = ['sys-nfc'];

    private const SYSTEM_BACK = ['sys-tapzone', 'sys-tapicon', 'sys-qr', 'sys-shortcode', 'sys-brand'];

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    private function create(string $template = 'onyx', string $orientation = 'landscape', bool $lanyard = false): array
    {
        return $this->api('POST', '/designs', compact('template', 'orientation', 'lanyard'))->assertCreated()->json('data');
    }

    public static function variants(): array
    {
        $out = [];
        foreach (array_keys(TemplateLibrary::TEMPLATES) as $t) {
            foreach (['landscape', 'portrait'] as $o) {
                foreach ([false, true] as $l) {
                    $out["$t $o".($l ? ' lanyard' : '')] = [$t, $o, $l];
                }
            }
        }

        return $out;
    }

    #[DataProvider('variants')]
    public function test_every_default_template_is_print_ready_with_required_elements(string $t, string $o, bool $l): void
    {
        $g = new CardGeometry($o, $l);
        $sides = TemplateLibrary::make($t, $g);
        $ids = fn ($side) => array_column(array_filter($side['layers'], fn ($x) => $x['locked'] ?? false), 'id');

        $this->assertSame(self::SYSTEM_FRONT, $ids($sides['front']));
        $this->assertSame(self::SYSTEM_BACK, $ids($sides['back']));
        // desain berslot logo divalidasi dengan brand kit yang sudah punya logo (2000 px → tajam di ukuran cetak)
        $kit = in_array($t, TemplateLibrary::LOGO_TEMPLATES, true) ? ['logo' => ['asset' => 't1/brand/logo.png', 'px_w' => 2000, 'px_h' => 1000]] : null;
        $this->assertSame([], (new DesignValidator($g, Variables::SAMPLE, $kit))->validate($sides['front'], $sides['back']));
        $this->assertSame(in_array($t, TemplateLibrary::LOGO_TEMPLATES, true), str_contains(json_encode($sides), 'kit:logo'));

        $qr = collect($sides['back']['layers'])->firstWhere('id', 'sys-qr');
        $this->assertGreaterThanOrEqual(15, $qr['size'] - 3, 'QR ≥ 15 mm setelah quiet zone (DBD §8.1)');
        $this->assertSame($l, isset($sides['front']['zones']['slot']));

        // palet tertutup: template hanya memakai warna DBD §3.1
        $json = json_encode($sides);
        preg_match_all('/#[0-9A-F]{6}/i', $json, $m);
        $this->assertSame([], array_diff(array_unique(array_map('strtoupper', $m[0])), array_values(Palette::COLORS)));
    }

    public function test_catalog_lists_blank_plus_forty_three_templates_with_palette_qr_and_profile_variables(): void
    {
        $this->actingAs($this->makeUser());
        $res = $this->api('GET', '/studio/templates')->assertOk();
        $this->assertCount(44, $res->json('data.templates')); // kosong + 8 klasik + 35 desain koleksi
        $this->assertSame('kosong', $res->json('data.templates.0.key'), 'kartu kosong selalu pertama');
        $this->assertContains('instagram', $res->json('data.glyphs'));
        $this->assertCount(3, $res->json('data.lanyard.widths'));
        $this->assertCount(30, $res->json('data.palette'));
        $this->assertSame('Rina Saputra', $res->json('data.variables.name'));
        $this->assertStringStartsWith('M', $res->json('data.qr.path'));
        $this->assertNotNull($res->json('data.templates.0.preview.portrait.back'));
    }

    public function test_locked_elements_cannot_be_removed_moved_or_recolored(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->create('ivory');
        $back = $d['back'];
        // klien jahat: hapus QR, pindah brand, warnai ulang shortcode, tambah "sys-" palsu
        $back['layers'] = array_values(array_filter($back['layers'], fn ($l) => $l['id'] !== 'sys-qr'));
        foreach ($back['layers'] as &$l) {
            if ($l['id'] === 'sys-brand') {
                $l['x'] = 70;
            }
            if ($l['id'] === 'sys-shortcode') {
                $l['color'] = '#FF00FF';
            }
        }
        unset($l);
        $back['layers'][] = ['id' => 'sys-evil', 'type' => 'text', 'text' => 'hack', 'x' => 5, 'y' => 5, 'size' => 8];
        $front = $d['front'];
        $front['layers'] = array_values(array_filter($front['layers'], fn ($l) => $l['id'] !== 'sys-nfc'));

        $saved = $this->api('PATCH', "/designs/{$d['id']}", ['front' => $front, 'back' => $back])->assertOk()->json('data');

        $ids = fn ($side) => array_column(array_filter($saved[$side]['layers'], fn ($x) => $x['locked'] ?? false), 'id');
        $this->assertSame(self::SYSTEM_FRONT, $ids('front'));
        $this->assertSame(self::SYSTEM_BACK, $ids('back'));
        $this->assertNull(collect($saved['back']['layers'])->firstWhere('id', 'sys-evil'));
        $original = collect($d['back']['layers'])->keyBy('id');
        $now = collect($saved['back']['layers'])->keyBy('id');
        $this->assertSame($original['sys-brand']['x'], $now['sys-brand']['x']);
        $this->assertNotSame('#FF00FF', $now['sys-shortcode']['color']);
    }

    public function test_system_colors_follow_background_contrast(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->create('ivory');
        $back = $d['back'];
        $back['background'] = ['type' => 'color', 'value' => '#0D0D0E'];
        $saved = $this->api('PATCH', "/designs/{$d['id']}", ['back' => $back])->json('data.back.layers');
        $this->assertSame(Palette::hex('bone-50'), collect($saved)->firstWhere('id', 'sys-brand')['color']);
        $this->assertSame(Palette::hex('ink-950'), collect($saved)->firstWhere('id', 'sys-qr')['fg'], 'QR selalu gelap di atas pelat terang');
    }

    public function test_only_solid_colors_and_known_layer_types_are_kept(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->create('garis');
        $front = $d['front'];
        $front['background'] = ['type' => 'gradient', 'value' => 'linear-gradient(red, blue)'];
        $front['layers'][] = ['id' => 'x1', 'type' => 'rect', 'x' => 10, 'y' => 10, 'w' => 5, 'h' => 5, 'fill' => 'url(#grad)'];
        $front['layers'][] = ['id' => 'x2', 'type' => 'script', 'code' => 'alert(1)'];
        $saved = $this->api('PATCH', "/designs/{$d['id']}", ['front' => $front])->json('data.front');

        $this->assertSame(Palette::hex('bone-0'), $saved['background']['value']);
        $this->assertSame('color', $saved['background']['type']);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', collect($saved['layers'])->firstWhere('id', 'x1')['fill']);
        $this->assertNull(collect($saved['layers'])->firstWhere('id', 'x2'));
    }

    public function test_lanyard_slot_and_small_text_are_flagged(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->create('garis', 'portrait', true);
        $front = $d['front'];
        $front['layers'][] = ['id' => 'top', 'type' => 'text', 'text' => 'Di lubang', 'x' => 27, 'y' => 4, 'size' => 8, 'align' => 'center'];
        $front['layers'][] = ['id' => 'tiny', 'type' => 'text', 'text' => 'Kecil', 'x' => 10, 'y' => 60, 'size' => 5];
        $res = $this->api('PATCH', "/designs/{$d['id']}", ['front' => $front])->assertOk();

        $issues = collect($res->json('data.issues'));
        $this->assertTrue($issues->contains(fn ($i) => $i['layer'] === 'top' && $i['level'] === 'error' && str_contains($i['message'], 'lanyard')));
        $this->assertTrue($issues->contains(fn ($i) => $i['layer'] === 'tiny' && $i['level'] === 'error' && str_contains($i['message'], '6 pt')));
        $this->assertFalse($res->json('data.validated'));
    }

    public function test_toggling_lanyard_rebuilds_slot_zone(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->create('onyx', 'portrait');
        $this->assertArrayNotHasKey('slot', $d['front']['zones']);
        $saved = $this->api('PATCH', "/designs/{$d['id']}", ['lanyard' => true])->json('data');
        $this->assertTrue($saved['lanyard']);
        $this->assertSame(16, (int) $saved['front']['zones']['slot']['w']);
    }

    public function test_image_assets_are_tenant_scoped(): void
    {
        Storage::fake('public');
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $d = $this->create();
        $asset = $this->api('POST', "/designs/{$d['id']}/assets", ['image' => UploadedFile::fake()->image('logo.png', 600, 300)])
            ->assertCreated()->json('data');
        $this->assertStringStartsWith("t{$rina->personal_tenant_id}/designs/", $asset['asset']);

        Storage::disk('public')->put('t9999/designs/lain.png', 'x');
        $front = $d['front'];
        $front['layers'][] = ['id' => 'mine', 'type' => 'image', 'asset' => $asset['asset'], 'x' => 6, 'y' => 6, 'w' => 20, 'h' => 10, 'px_w' => 600, 'px_h' => 300];
        $front['layers'][] = ['id' => 'theirs', 'type' => 'image', 'asset' => 't9999/designs/lain.png', 'x' => 6, 'y' => 20, 'w' => 10, 'h' => 10];
        $saved = collect($this->api('PATCH', "/designs/{$d['id']}", ['front' => $front])->json('data.front.layers'));
        $this->assertNotNull($saved->firstWhere('id', 'mine'));
        $this->assertNull($saved->firstWhere('id', 'theirs'));
    }

    public function test_designs_are_private_to_their_owner(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->create();
        $this->actingAs($this->makeUser('lain@example.com'));
        $this->api('GET', "/designs/{$d['id']}")->assertNotFound();
        $this->api('PATCH', "/designs/{$d['id']}", ['name' => 'x'])->assertNotFound();
        $this->api('GET', '/designs')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_long_names_shrink_to_fit_instead_of_leaving_the_safe_zone(): void
    {
        $g = new CardGeometry('landscape', false);
        $name = collect(TemplateLibrary::make('ivory', $g)['front']['layers'])->firstWhere('id', 'name');
        $this->assertSame(round(85.6 - 8, 2), $name['fitWidth'], 'nama rata tengah dibatasi lebar safe zone');

        $long = ['name' => 'Raden Ajeng Anastasia Kusumawardhani'] + Variables::SAMPLE;
        $sides = TemplateLibrary::make('ivory', $g);
        $issues = (new DesignValidator($g, $long))->validate($sides['front'], $sides['back']);
        $this->assertSame([], array_values(array_filter($issues, fn ($i) => $i['layer'] === 'name')));

        $l = ['type' => 'text', 'text' => str_repeat('W', 80), 'size' => 17, 'fitWidth' => 40];
        $this->assertSame(6.0, DesignValidator::textSize($l), 'tidak pernah di bawah 6 pt');

        $this->actingAs($this->makeUser());
        $d = $this->create('ivory');
        $this->assertSame($name['fitWidth'], collect($d['front']['layers'])->firstWhere('id', 'name')['fitWidth'], 'disimpan apa adanya');
    }
}
