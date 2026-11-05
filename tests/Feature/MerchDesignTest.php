<?php

namespace Tests\Feature;

use App\Models\Design;
use App\Models\MerchProduct;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Editor merchandise (jaket, kaos, tote bag, mug, topi): bisa didesain & disimpan, pemesanan cetak belum dibuka. */
class MerchDesignTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    public function test_catalog_lists_coming_soon_products_with_print_areas(): void
    {
        $this->actingAs($this->makeUser());
        $merch = collect($this->api('GET', '/studio/templates')->assertOk()->json('data.merch'));
        $this->assertSame(['tshirt', 'jacket', 'totebag', 'mug', 'cap'], $merch->pluck('key')->all());
        $this->assertTrue($merch->every(fn ($p) => $p['coming_soon']));
        $this->assertSame([290, 400, 2], [$merch[0]['print_w_mm'], $merch[0]['print_h_mm'], $merch[0]['print_sides']]);
        $this->assertSame(1, $merch->firstWhere('key', 'mug')['print_sides']);
    }

    public function test_each_product_gets_its_own_print_area_and_starter_design(): void
    {
        $this->actingAs($this->makeUser());
        foreach (MerchProduct::orderBy('sort')->get() as $p) {
            $d = $this->api('POST', '/designs', ['kind' => 'merch', 'product' => $p->key])->assertCreated()->json('data');
            $this->assertSame(['merch', "{$p->name} baru", $p->key], [$d['kind'], $d['name'], $d['merch_spec']['key']]);
            $this->assertSame(['w' => $p->print_w_mm, 'h' => $p->print_h_mm, 'bleed' => 0, 'unit' => 'mm'], $d['front']['size']);
            $this->assertSame(['brand'], array_column($d['front']['layers'], 'id'), 'tanpa QR/NFC/TapCard');
            $this->assertSame('{{name}}', $d['front']['layers'][0]['text']);
            $this->assertTrue($d['validated'], 'desain awal tidak bermasalah');
        }
        $this->api('POST', '/designs', ['kind' => 'merch'])->assertStatus(422);
        $this->api('POST', '/designs', ['kind' => 'merch', 'product' => 'payung'])->assertStatus(422);
    }

    public function test_save_keeps_geometry_rejects_lanyard_fields_and_flags_out_of_area(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->api('POST', '/designs', ['kind' => 'merch', 'product' => 'tshirt'])->assertCreated()->json('data');

        // klien tidak bisa mengubah ukuran area cetak atau menyelipkan elemen sistem kartu
        $front = $d['front'];
        $front['size'] = ['w' => 10, 'h' => 10, 'bleed' => 3];
        $front['layers'][] = ['id' => 'sys-qr', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10];
        $front['layers'][] = ['id' => 'far', 'type' => 'text', 'text' => 'Keluar', 'x' => 280, 'y' => 395, 'size' => 40];
        $res = $this->api('PATCH', "/designs/{$d['id']}", ['front' => $front, 'lanyard' => true])->assertOk()->json('data');
        $this->assertSame(290, (int) $res['front']['size']['w']);
        $this->assertSame(['brand', 'far'], array_column($res['front']['layers'], 'id'));
        $this->assertFalse($res['lanyard']);
        $this->assertStringContainsString('keluar dari area cetak', collect($res['issues'])->firstWhere('layer', 'far')['message']);

        $this->api('PATCH', "/designs/{$d['id']}", ['width_mm' => 20])->assertStatus(422);
        $this->api('PATCH', "/designs/{$d['id']}", ['tile_mm' => 100])->assertStatus(422);
    }

    public function test_merch_is_listed_but_not_orderable_yet(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->api('POST', '/designs', ['kind' => 'merch', 'product' => 'jacket'])->assertCreated()->json('data');
        $this->assertSame([$d['id']], array_column($this->api('GET', '/designs?kind=merch')->assertOk()->json('data'), 'id'));
        $this->assertNotContains($d['id'], array_column($this->api('GET', '/designs?kind=card')->json('data'), 'id'));

        $this->assertNotContains($d['id'], array_column($this->api('GET', '/orders/catalog')->assertOk()->json('data.designs'), 'id'));
        $this->api('POST', '/orders', ['items' => [['kind' => 'merch', 'design' => $d['id'], 'quantity' => 10]], 'shipping' => ['method' => 'regular']])
            ->assertStatus(422);
        $this->api('DELETE', "/designs/{$d['id']}")->assertNoContent();
    }

    public function test_database_requires_product_for_merch_only(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $d = $this->api('POST', '/designs', ['kind' => 'merch', 'product' => 'mug'])->assertCreated()->json('data');
        $this->expectException(QueryException::class);
        Design::withoutGlobalScope(TenantScope::class)->where('public_id', $d['id'])->update(['merch_product_id' => null]);
    }
}
