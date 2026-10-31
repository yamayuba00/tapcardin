<?php

namespace Tests\Feature;

use App\Models\BrandKit;
use App\Models\Design;
use App\Support\Design\DesignValidator;
use App\Support\Design\Palette;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Editor penuh (layer baru), brand kit bersama, lanyard berpasangan dengan kartu, katalog & harga. */
class LanyardDesignTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    private function card(string $template = 'onyx', string $orientation = 'portrait'): array
    {
        return $this->api('POST', '/designs', ['template' => $template, 'orientation' => $orientation])->assertCreated()->json('data');
    }

    public function test_blank_card_has_only_locked_system_elements(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->card('kosong', 'landscape');
        $this->assertSame([], collect($d['front']['layers'])->reject(fn ($l) => $l['locked'] ?? false)->values()->all());
        $this->assertSame([], collect($d['back']['layers'])->reject(fn ($l) => $l['locked'] ?? false)->values()->all());
        $this->assertSame(['sys-nfc'], array_column($d['front']['layers'], 'id'));
    }

    public function test_new_layer_types_and_common_properties_are_kept_and_sanitised(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->card('kosong', 'landscape');
        $front = $d['front'];
        $front['layers'] = [
            ['id' => 'e1', 'type' => 'ellipse', 'x' => 10, 'y' => 10, 'w' => 20, 'h' => 10, 'fill' => '#ff0000', 'stroke' => '#000000', 'strokeWidth' => 0.5, 'rotate' => 30, 'opacity' => 0.5, 'group' => 'g1', 'lock' => true],
            ['id' => 'p1', 'type' => 'polygon', 'x' => 40, 'y' => 10, 'w' => 10, 'h' => 10, 'sides' => 99, 'star' => true, 'fill' => 'kit:primary', 'group' => 'g1'],
            ['id' => 'g1', 'type' => 'glyph', 'name' => 'instagram', 'x' => 60, 'y' => 10, 'size' => 6, 'color' => 'kit:text'],
            ['id' => 'bad', 'type' => 'glyph', 'name' => 'tidak-ada', 'x' => 60, 'y' => 10, 'size' => 6],
            ['id' => 't1', 'type' => 'text', 'text' => "Baris satu\nBaris dua", 'x' => 10, 'y' => 30, 'size' => 9, 'lineHeight' => 1.4, 'color' => 'url(#grad)', 'hidden' => true],
            ['id' => 'x1', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5, 'fill' => '#000000', 'filter' => 'blur(4px)'],
        ];
        $layers = collect($this->api('PATCH', "/designs/{$d['id']}", ['front' => $front])->assertOk()->json('data.front.layers'))->keyBy('id');

        $this->assertSame(['ellipse', 30, 0.5, 'g1', true, '#FF0000'], [$layers['e1']['type'], $layers['e1']['rotate'], $layers['e1']['opacity'], $layers['e1']['group'], $layers['e1']['lock'], $layers['e1']['fill']]);
        $this->assertSame([12, true, 'kit:primary'], [$layers['p1']['sides'], $layers['p1']['star'], $layers['p1']['fill']], 'sisi poligon dibatasi 3–12');
        $this->assertSame('kit:text', $layers['g1']['color']);
        $this->assertArrayNotHasKey('bad', $layers->all(), 'ikon di luar daftar ditolak');
        $this->assertSame("Baris satu\nBaris dua", $layers['t1']['text']);
        $this->assertTrue($layers['t1']['hidden']);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $layers['t1']['color'], 'warna non-hex diganti');
        $this->assertArrayNotHasKey('filter', $layers['x1']);
    }

    public function test_rotated_and_multiline_boxes(): void
    {
        $box = DesignValidator::box(['type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 20, 'h' => 10, 'rotate' => 90]);
        $this->assertEqualsWithDelta(10, $box['w'], 0.01);
        $this->assertEqualsWithDelta(20, $box['h'], 0.01);
        $one = DesignValidator::box(['type' => 'text', 'text' => 'Halo', 'x' => 0, 'y' => 10, 'size' => 10]);
        $two = DesignValidator::box(['type' => 'text', 'text' => "Halo\nDunia", 'x' => 0, 'y' => 10, 'size' => 10, 'lineHeight' => 1.5]);
        $this->assertGreaterThan($one['h'] * 2, $two['h']);
    }

    public function test_paired_lanyard_shares_brand_kit_and_turns_on_card_slot(): void
    {
        $this->actingAs($this->makeUser());
        $card = $this->card('onyx', 'portrait');
        $this->assertFalse($card['lanyard']);

        $l = $this->api('POST', "/designs/{$card['id']}/lanyard")->assertCreated()->json('data');
        $this->assertSame(['lanyard', 20, 'lobster', 2, 150], [$l['kind'], $l['lanyard_spec']['width_mm'], $l['lanyard_spec']['hardware'], $l['lanyard_spec']['print_sides'], $l['lanyard_spec']['tile_mm']]);
        $this->assertSame($card['id'], $l['paired']['id']);
        $this->assertNotNull($l['kit']);
        $this->assertSame('kit:primary', $l['front']['background']['value']);
        $this->assertSame(20.0, (float) $l['front']['size']['h']);
        $this->assertSame([], collect($l['front']['layers'])->where('locked', true)->all(), 'lanyard tanpa elemen sistem');

        $c = $this->api('GET', "/designs/{$card['id']}")->json('data');
        $this->assertTrue($c['lanyard'], 'slot lanyard kartu otomatis aktif');
        $this->assertArrayHasKey('slot', $c['front']['zones']);
        $this->assertSame($l['kit']['id'], $c['kit']['id'], 'brand kit dipakai bersama');
        $this->assertSame($l['id'], $c['paired']['id']);

        // dipanggil lagi → lanyard yang sama, bukan duplikat
        $this->assertSame($l['id'], $this->api('POST', "/designs/{$card['id']}/lanyard")->assertOk()->json('data.id'));
        $this->api('POST', "/designs/{$l['id']}/lanyard")->assertJsonPath('error.code', 'not_a_card');

        // kartu lain tidak bisa dipasangkan ke lanyard kedua bila sudah punya pasangan
        $l2 = $this->api('POST', '/designs', ['kind' => 'lanyard'])->assertCreated()->json('data');
        $this->api('PATCH', "/designs/{$l2['id']}", ['paired_design_id' => $card['id']])->assertJsonPath('error.code', 'card_already_paired');
    }

    public function test_lanyard_specs_geometry_and_validation(): void
    {
        $this->actingAs($this->makeUser());
        $l = $this->api('POST', '/designs', ['kind' => 'lanyard'])->assertCreated()->json('data');
        $this->api('PATCH', "/designs/{$l['id']}", ['width_mm' => 17])->assertStatus(422);
        $this->api('PATCH', "/designs/{$l['id']}", ['tile_mm' => 10])->assertStatus(422);
        $card = $this->card();
        $this->api('PATCH', "/designs/{$card['id']}", ['width_mm' => 20])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['width_mm']]]);

        $front = $l['front'];
        $front['layers'][] = ['id' => 'big', 'type' => 'text', 'text' => 'TERLALU BESAR', 'x' => 10, 'y' => 24, 'size' => 40, 'color' => '#FFFFFF'];
        $res = $this->api('PATCH', "/designs/{$l['id']}", ['width_mm' => 15, 'hardware' => 'badge_reel', 'print_sides' => 1, 'tile_mm' => 200, 'front' => $front])->assertOk();
        $res->assertJsonPath('data.lanyard_spec.width_mm', 15)->assertJsonPath('data.lanyard_spec.tile_mm', 200)->assertJsonPath('data.front.size.w', 200);
        $this->assertTrue(collect($res->json('data.issues'))->contains(fn ($i) => $i['layer'] === 'big' && str_contains($i['message'], 'tepi tali')));

        $this->api('GET', '/lanyards/quote?width_mm=15&hardware=badge_reel&print_sides=1&qty=3')->assertOk()
            ->assertJsonPath('data.qty', 10)->assertJsonPath('data.unit', 9000 + 6000)->assertJsonPath('data.total', 150000);
    }

    public function test_brand_kit_crud_logo_and_kit_logo_layer(): void
    {
        Storage::fake('public');
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $kit = $this->api('POST', '/brand-kits', ['name' => 'Saputra', 'colors' => ['primary' => '#2f6e58', 'secondary' => '#C9A96E', 'background' => '#FBF9F4', 'text' => '#141210'], 'tagline' => 'Pajak tanpa pusing'])
            ->assertCreated()->assertJsonPath('data.colors.primary', '#2F6E58')->json('data');
        $this->api('POST', '/brand-kits', ['name' => 'X', 'colors' => ['primary' => 'red', 'secondary' => '#000000', 'background' => '#000000', 'text' => '#000000']])->assertStatus(422);
        $this->api('POST', "/brand-kits/{$kit['id']}/logo", ['image' => UploadedFile::fake()->image('logo.png', 900, 300)])->assertOk()->assertJsonPath('data.logo.px_w', 900);

        $card = $this->card('kosong', 'landscape');
        $front = $card['front'];
        $front['layers'][] = ['id' => 'logo', 'type' => 'image', 'asset' => 'kit:logo', 'x' => 10, 'y' => 10, 'w' => 30, 'h' => 10];
        $front['layers'][] = ['id' => 'tag', 'type' => 'text', 'text' => '{{tagline}}', 'x' => 10, 'y' => 30, 'size' => 8, 'color' => 'kit:text'];
        $front['background'] = ['type' => 'color', 'value' => 'kit:primary'];
        $res = $this->api('PATCH', "/designs/{$card['id']}", ['brand_kit_id' => $kit['id'], 'front' => $front])->assertOk();
        $this->assertSame('kit:logo', collect($res->json('data.front.layers'))->firstWhere('id', 'logo')['asset']);
        $this->assertSame('kit:primary', $res->json('data.front.background.value'));
        $this->assertSame(Palette::hex('bone-50'), collect($res->json('data.front.layers'))->firstWhere('id', 'sys-nfc')['color'], 'kontras elemen sistem dihitung dari warna kit (hijau gelap → tinta terang)');
        $this->assertFalse(collect($res->json('data.issues'))->contains(fn ($i) => $i['layer'] === 'logo' && str_contains($i['message'], 'dpi')), 'logo 900px pada 30mm = 762 dpi');

        $other = $this->makeUser('lain@example.com', 'Lain');
        $this->actingAs($other)->api('PATCH', "/brand-kits/{$kit['id']}", ['name' => 'curian'])->assertNotFound();
        $this->actingAs($rina)->api('DELETE', "/brand-kits/{$kit['id']}")->assertNoContent();
        $this->assertSoftDeleted(BrandKit::withoutGlobalScope(TenantScope::class)->getModel()->getTable(), ['public_id' => $kit['id']]);
    }

    public function test_save_as_template_and_create_from_it(): void
    {
        $this->actingAs($this->makeUser());
        $card = $this->card('garis', 'landscape');
        $t = $this->api('POST', "/designs/{$card['id']}/template", ['name' => 'Template kantor'])->assertCreated()->json('data');
        $this->assertSame('Template kantor', $this->api('GET', '/studio/templates')->json('data.my_templates.0.name'));
        $this->assertCount(1, $this->api('GET', '/designs')->json('data'), 'template tidak tampil sebagai desain biasa');

        $copy = $this->api('POST', '/designs', ['from' => $t['id']])->assertCreated()->json('data');
        $this->assertNotSame($card['id'], $copy['id']);
        $this->assertSame(Design::withoutGlobalScope(TenantScope::class)->where('public_id', $copy['id'])->value('is_template'), false);
    }
}
