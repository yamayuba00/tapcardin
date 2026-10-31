<?php

namespace App\Http\Controllers\Workspace;

use App\Models\BrandKit;
use App\Support\Design\LanyardPricing;
use App\Support\ImageResizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Brand kit — warna, logo, nama & tagline merek yang dirujuk kartu dan lanyard (`kit:primary`, `kit:logo`,
 * `{{brand}}`). Mengubah kit langsung mengubah semua desain yang memakainya. + katalog & harga lanyard.
 */
class BrandKitController extends WorkspaceController
{
    private const HEX = 'regex:/^#[0-9A-Fa-f]{6}$/';

    public function index(): JsonResponse
    {
        return response()->json(['data' => BrandKit::where('user_id', $this->user()->id)->withCount('designs')->latest('updated_at')->get()
            ->map(fn (BrandKit $k) => $k->toKit() + ['designs_count' => $k->designs_count])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize('cards.manage.own');
        $kit = BrandKit::create(['user_id' => $this->user()->id] + $this->validated($request));

        return response()->json(['data' => $kit->toKit()], 201);
    }

    public function update(Request $request, BrandKit $kit): JsonResponse
    {
        $this->own($kit);
        $kit->update($this->validated($request, partial: true));

        return response()->json(['data' => $kit->toKit()]);
    }

    /** POST /api/brand-kits/{id}/logo */
    public function logo(Request $request, BrandKit $kit): JsonResponse
    {
        $this->own($kit);
        $request->validate(['image' => ['required', 'file', 'max:8192']]);
        $img = ImageResizer::load($request->file('image'));
        imagesavealpha($img, true);
        $path = "t{$kit->tenant_id}/designs/kit-".Str::random(28).'.png';
        Storage::disk('public')->put($path, ImageResizer::png($img));
        if ($kit->logo_path) {
            Storage::disk('public')->delete($kit->logo_path);
        }
        $kit->update(['logo_path' => $path, 'logo_px_w' => imagesx($img), 'logo_px_h' => imagesy($img)]);

        return response()->json(['data' => $kit->toKit()]);
    }

    public function destroy(BrandKit $kit): JsonResponse
    {
        $this->own($kit);
        $kit->delete(); // desain tetap; FK brand_kit_id di-null-kan saat hapus permanen

        return response()->json(null, 204);
    }

    /** GET /api/lanyards/quote?width_mm=&hardware=&print_sides=&qty= — perkiraan harga (checkout menyusul). */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'width_mm' => ['required', Rule::exists('lanyard_widths', 'width_mm')->where('is_active', true)],
            'hardware' => ['required', Rule::exists('lanyard_hardware', 'key')->where('is_active', true)],
            'print_sides' => ['required', Rule::in([1, 2])],
            'qty' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        return response()->json(['data' => LanyardPricing::quote((int) $data['width_mm'], $data['hardware'], (int) $data['print_sides'], (int) $data['qty'])]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:80'],
            'colors' => [$req, 'array'],
            'colors.primary' => [$req, self::HEX],
            'colors.secondary' => [$req, self::HEX],
            'colors.background' => [$req, self::HEX],
            'colors.text' => [$req, self::HEX],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'website' => ['sometimes', 'nullable', 'string', 'max:160'],
        ], ['colors.*.regex' => 'Warna harus hex 6 digit.']);
        foreach ($data['colors'] ?? [] as $k => $hex) {
            $data[$k.'_color'] = strtoupper($hex);
        }
        unset($data['colors']);

        return $data;
    }

    private function own(BrandKit $kit): void
    {
        abort_unless($kit->user_id === $this->user()->id, 404);
    }
}
