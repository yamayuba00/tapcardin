<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Studio fleksibel tahap 3: garis bantu tersimpan per sisi (tidak dicetak), dibersihkan & dibatasi. */
class StudioLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    public function test_guides_are_saved_per_side_rounded_bounded_and_ignored_by_print_checks(): void
    {
        $this->actingAs($this->makeUser());
        $d = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => 'landscape'])->assertCreated()->json('data');
        $front = $d['front'];
        $front['guides'] = ['x' => [10.04, 10.04, 42.8, 500, -50, 'abc'], 'y' => array_merge([27], range(1, 30))];
        $res = $this->api('PATCH', "/designs/{$d['id']}", ['front' => $front])->assertOk()->json('data');

        $this->assertEquals([10, 42.8], $res['front']['guides']['x']);
        $this->assertCount(20, $res['front']['guides']['y']);
        $this->assertArrayNotHasKey('guides', $res['back']);
        $this->assertSame([], $res['issues']);
        $this->assertEquals([10, 42.8], $this->api('GET', "/designs/{$d['id']}")->assertOk()->json('data.front.guides.x'));

        // dihapus semua → kunci guides hilang
        $front = $res['front'];
        $front['guides'] = ['x' => [], 'y' => []];
        $this->assertArrayNotHasKey('guides', $this->api('PATCH', "/designs/{$d['id']}", ['front' => $front])->assertOk()->json('data.front'));
    }

    public function test_lanyard_pattern_keeps_guides_too(): void
    {
        $this->actingAs($this->makeUser());
        $card = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => 'portrait'])->assertCreated()->json('data');
        $l = $this->api('POST', "/designs/{$card['id']}/lanyard")->assertCreated()->json('data');
        $front = $l['front'];
        $front['guides'] = ['x' => [75], 'y' => [10]];
        $this->assertEquals(['x' => [75], 'y' => [10]], $this->api('PATCH', "/designs/{$l['id']}", ['front' => $front])->assertOk()->json('data.front.guides'));
    }

    public function test_element_fully_outside_the_print_area_is_flagged(): void
    {
        $this->actingAs($this->makeUser());
        $star = ['id' => 's1', 'type' => 'polygon', 'x' => 8, 'y' => 30, 'w' => 8, 'h' => 8, 'sides' => 5, 'star' => true, 'inner' => 0.45, 'fill' => '#2F5C8F'];
        $card = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => 'landscape'])->assertCreated()->json('data');
        $l = $this->api('POST', "/designs/{$card['id']}/lanyard")->assertCreated()->json('data');

        // tali 20 mm: bintang di y = 30 mm tidak terlihat & tidak tercetak
        $front = $l['front'];
        $front['layers'] = [...$front['layers'], $star];
        $issues = collect($this->api('PATCH', "/designs/{$l['id']}", ['front' => $front])->assertOk()->json('data.issues'))->where('layer', 's1');
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('di luar area cetak', $issues->first()['message']);

        // di kartu (54 mm) posisi yang sama masih di dalam → tidak ada peringatan itu
        $front = $card['front'];
        $front['layers'] = [...$front['layers'], ['y' => 20] + $star];
        $issues = collect($this->api('PATCH', "/designs/{$card['id']}", ['front' => $front])->assertOk()->json('data.issues'))->where('layer', 's1');
        $this->assertSame([], $issues->filter(fn ($i) => str_contains($i['message'], 'di luar area cetak'))->values()->all());
    }
}
