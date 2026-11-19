<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Studio fleksibel tahap 2: pola garis (putus/titik), potong gambar ke bentuk + perbesar/geser, balik gambar. */
class StudioShapesImagesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    public function test_stroke_styles_masks_crop_and_flip_are_kept_and_clamped(): void
    {
        Storage::fake('public');
        $this->actingAs($this->makeUser());
        $d = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => 'landscape'])->assertCreated()->json('data');
        $img = $this->api('POST', "/designs/{$d['id']}/assets", ['image' => UploadedFile::fake()->image('foto.png', 1200, 1200)])->assertCreated()->json('data');

        $front = $d['front'];
        $front['layers'] = array_merge($front['layers'], [
            ['id' => 'r', 'type' => 'rect', 'x' => 10, 'y' => 10, 'w' => 20, 'h' => 10, 'fill' => 'none', 'stroke' => '#112233', 'strokeWidth' => 0.4, 'strokeStyle' => 'dashed', 'dashLen' => 99],
            ['id' => 'ln', 'type' => 'line', 'x1' => 10, 'y1' => 30, 'x2' => 40, 'y2' => 30, 'color' => '#112233', 'width' => 0.5, 'strokeStyle' => 'dotted', 'dashLen' => 2],
            ['id' => 'c', 'type' => 'circle', 'cx' => 60, 'cy' => 30, 'r' => 6, 'stroke' => '#112233', 'strokeWidth' => 0.3, 'strokeStyle' => 'wavy'],
            ['id' => 'nostroke', 'type' => 'ellipse', 'x' => 10, 'y' => 35, 'w' => 8, 'h' => 8, 'fill' => '#112233', 'strokeStyle' => 'dashed'],
            ['id' => 'img', 'type' => 'image', 'asset' => $img['asset'], 'x' => 50, 'y' => 10, 'w' => 20, 'h' => 20, 'px_w' => 1200, 'px_h' => 1200,
                'fit' => 'cover', 'mask' => 'polygon', 'maskSides' => 40, 'cropZoom' => 9, 'cropX' => -3, 'cropY' => 0.4, 'flipX' => true],
            ['id' => 'star', 'type' => 'image', 'asset' => $img['asset'], 'x' => 20, 'y' => 15, 'w' => 10, 'h' => 10, 'px_w' => 1200, 'px_h' => 1200, 'mask' => 'star', 'cropZoom' => 1],
            ['id' => 'bad', 'type' => 'image', 'asset' => $img['asset'], 'x' => 20, 'y' => 30, 'w' => 10, 'h' => 10, 'px_w' => 1200, 'px_h' => 1200, 'mask' => 'blob'],
        ]);
        $res = $this->api('PATCH', "/designs/{$d['id']}", ['front' => $front])->assertOk()->json('data');
        $l = collect($res['front']['layers'])->keyBy('id');

        $this->assertSame('dashed', $l['r']['strokeStyle']);
        $this->assertEquals(20, $l['r']['dashLen']);
        $this->assertSame('dotted', $l['ln']['strokeStyle']);
        $this->assertArrayNotHasKey('dashLen', $l['ln']);
        $this->assertArrayNotHasKey('strokeStyle', $l['c']);
        $this->assertArrayNotHasKey('strokeStyle', $l['nostroke']); // tanpa garis tepi, pola diabaikan
        $this->assertSame('polygon', $l['img']['mask']);
        $this->assertSame(12, $l['img']['maskSides']);
        $this->assertEquals(5, $l['img']['cropZoom']);
        $this->assertEquals(-1, $l['img']['cropX']);
        $this->assertEquals(0.4, $l['img']['cropY']);
        $this->assertTrue($l['img']['flipX']);
        $this->assertArrayNotHasKey('flipY', $l['img']);
        $this->assertSame('star', $l['star']['mask']);
        $this->assertArrayNotHasKey('cropZoom', $l['star']);
        $this->assertArrayNotHasKey('maskSides', $l['star']);
        $this->assertArrayNotHasKey('mask', $l['bad']);

        // 1200 px pada 20 mm = 1524 dpi; diperbesar 5× = 305 dpi (aman). Kotak 10 mm yang tak diperbesar juga aman.
        $issues = collect($res['issues']);
        $this->assertFalse($issues->contains(fn ($i) => $i['layer'] === 'img' && str_contains($i['message'], 'dpi')));
    }

    public function test_zooming_an_image_inside_its_mask_lowers_the_print_resolution(): void
    {
        Storage::fake('public');
        $this->actingAs($this->makeUser());
        $d = $this->api('POST', '/designs', ['template' => 'onyx', 'orientation' => 'landscape'])->assertCreated()->json('data');
        $img = $this->api('POST', "/designs/{$d['id']}/assets", ['image' => UploadedFile::fake()->image('kecil.png', 400, 400)])->assertCreated()->json('data');
        $front = $d['front'];
        $front['layers'][] = ['id' => 'z', 'type' => 'image', 'asset' => $img['asset'], 'x' => 20, 'y' => 15, 'w' => 20, 'h' => 20, 'px_w' => 400, 'px_h' => 400, 'mask' => 'circle', 'cropZoom' => 3];
        $issues = collect($this->api('PATCH', "/designs/{$d['id']}", ['front' => $front])->assertOk()->json('data.issues'));
        // 400 px / (60 mm / 25.4) ≈ 169 dpi
        $this->assertTrue($issues->contains(fn ($i) => $i['layer'] === 'z' && str_contains($i['message'], '169 dpi')));
    }
}
