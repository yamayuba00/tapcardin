<?php

namespace App\Http\Controllers\Workspace;

use App\Exceptions\ApiError;
use App\Models\BrandKit;
use App\Models\Design;
use App\Models\LanyardHardware;
use App\Models\LanyardWidth;
use App\Models\MerchProduct;
use App\Models\Profile;
use App\Support\Design\CardGeometry;
use App\Support\Design\DesignNormalizer;
use App\Support\Design\DesignValidator;
use App\Support\Design\Geometry;
use App\Support\Design\LanyardGeometry;
use App\Support\Design\LanyardPricing;
use App\Support\Design\MerchGeometry;
use App\Support\Design\Palette;
use App\Support\Design\TemplateLibrary;
use App\Support\Design\Variables;
use App\Support\ImageResizer;
use App\Support\Qr\QrEncoder;
use App\Support\Qr\SvgRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * PRD §10.3 — Design Studio kartu & editor lanyard: template, desain milik pengguna, validasi cetak,
 * aset gambar, brand kit bersama, pasangan kartu ↔ lanyard, dan template milik sendiri.
 */
class DesignController extends WorkspaceController
{
    /** Katalog editor: template, palet, font, ikon, QR contoh, variabel profil + brand kit, katalog lanyard. */
    public function templates(): JsonResponse
    {
        $this->access()->authorize('cards.manage.own');
        $previews = [];
        foreach (TemplateLibrary::catalog() as $t) {
            foreach (['landscape', 'portrait'] as $o) {
                $previews[$t['key']][$o] = TemplateLibrary::make($t['key'], new CardGeometry($o));
            }
        }
        $qr = QrEncoder::encode(config('tapcard.resolver_url').'/c/XXXXXXXXXX?src=qr');
        $mine = Design::where('user_id', $this->user()->id)->where('is_template', true)->where('kind', 'card')->latest('updated_at')->limit(30)->get();

        return response()->json(['data' => [
            'templates' => array_map(fn ($t) => $t + ['preview' => $previews[$t['key']]], TemplateLibrary::catalog()),
            'my_templates' => $mine->map(fn (Design $d) => ['id' => $d->public_id, 'name' => $d->name, 'orientation' => $d->orientation, 'front' => $d->front_json, 'back' => $d->back_json]),
            'palette' => collect(Palette::COLORS)->map(fn ($hex, $name) => ['name' => $name, 'hex' => $hex])->values(),
            'fonts' => DesignNormalizer::FONTS,
            'glyphs' => DesignNormalizer::GLYPHS,
            'qr' => ['modules' => $qr->size, 'path' => SvgRenderer::path($qr)],
            'variables' => $this->variables(),
            // brand kit milik saya + brand kit workspace (logo organisasi) — kit workspace selalu pertama
            'kits' => $this->kits()->orderByDesc('is_workspace')->latest('updated_at')->get()->map(fn (BrandKit $k) => $k->toKit()),
            'lanyard' => LanyardPricing::catalog(),
            'merch' => MerchProduct::catalog(),
            'limits' => ['min_text_pt' => DesignValidator::MIN_TEXT_PT, 'max_layers' => DesignNormalizer::MAX_LAYERS, 'safe' => CardGeometry::SAFE, 'bleed' => CardGeometry::BLEED],
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $designs = Design::where('user_id', $this->user()->id)->where('is_template', false)
            ->with(['brandKit', 'lanyardWidth', 'lanyardHardware', 'merchProduct', 'pairedCard', 'pairedLanyard'])
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->latest('updated_at')->get();

        return response()->json(['data' => $designs->map(fn ($d) => $this->present($d, false))]);
    }

    /** Desain kartu baru dari template default / template milik sendiri (`from`), lanyard kosong, atau merchandise (`kind=merch` + `product`). */
    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize('cards.manage.own');
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(['card', 'lanyard', 'merch'])],
            'product' => ['nullable', 'required_if:kind,merch', Rule::exists('merch_products', 'key')->where('is_active', true)],
            'template' => ['nullable', 'required_without_all:from,kind', Rule::in(array_keys(TemplateLibrary::TEMPLATES))],
            'from' => ['nullable', 'string', 'size:26'],
            'orientation' => ['nullable', Rule::in(['landscape', 'portrait'])],
            'lanyard' => ['boolean'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        if (($data['kind'] ?? 'card') === 'merch') {
            return response()->json(['data' => $this->present($this->loaded($this->createMerch($data['product'], $data['name'] ?? null)))], 201);
        }
        if (($data['kind'] ?? 'card') === 'lanyard') {
            return response()->json(['data' => $this->present(DB::transaction(fn () => $this->createLanyard(null, $data['name'] ?? null)))], 201);
        }
        if (! empty($data['from'])) {
            $src = Design::where('public_id', $data['from'])->where('user_id', $this->user()->id)->where('kind', 'card')->firstOrFail();
            $design = $src->replicate(['public_id', 'paired_design_id', 'validated_at']);
            $design->fill(['name' => ($data['name'] ?? null) ?: $src->name, 'is_template' => false, 'version' => 1])->save();

            return response()->json(['data' => $this->present($design)], 201);
        }
        if (empty($data['template'])) {
            throw new ApiError('template_required', 'Pilih template atau mulai dari kartu kosong.');
        }

        $g = new CardGeometry($data['orientation'] ?? 'landscape', (bool) ($data['lanyard'] ?? false));
        $sides = TemplateLibrary::make($data['template'], $g);
        $design = Design::create([
            'user_id' => $this->user()->id,
            'kind' => 'card',
            'name' => ($data['name'] ?? null) ?: TemplateLibrary::TEMPLATES[$data['template']][0].' '.($g->portrait() ? 'potret' : 'lanskap'),
            'template_key' => $data['template'],
            'orientation' => $g->orientation,
            'lanyard' => $g->lanyard,
            'front_json' => $sides['front'],
            'back_json' => $sides['back'],
            'locked_layer_ids' => $this->lockedIds($sides),
            // workspace organisasi: logo & warna organisasi langsung terpasang di kartu baru
            'brand_kit_id' => WorkspaceBrandController::kitFor($this->tenant()->id)?->id,
        ]);

        return response()->json(['data' => $this->present($design)], 201);
    }

    public function show(Design $design): JsonResponse
    {
        $this->own($design);

        return response()->json(['data' => $this->present($this->loaded($design))]);
    }

    /** Simpan desain. Elemen terkunci kartu selalu dibangun ulang server; perubahan klien atasnya diabaikan. */
    public function update(Request $request, Design $design): JsonResponse
    {
        $this->own($design);
        $isLanyard = $design->kind === 'lanyard';
        $only = $isLanyard ? 'sometimes' : 'prohibited';
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'lanyard' => ['sometimes', 'boolean'],
            'front' => ['sometimes', 'array'],
            'back' => ['sometimes', 'array'],
            'brand_kit_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'width_mm' => [$only, Rule::exists('lanyard_widths', 'width_mm')->where('is_active', true)],
            'hardware' => [$only, Rule::exists('lanyard_hardware', 'key')->where('is_active', true)],
            'print_sides' => [$only, Rule::in([1, 2])],
            'tile_mm' => [$only, 'integer', 'min:'.LanyardGeometry::TILE_MIN, 'max:'.LanyardGeometry::TILE_MAX],
            'paired_design_id' => [$only, 'nullable', 'string', 'size:26'],
        ]);

        DB::transaction(function () use ($design, $data, $isLanyard) {
            if (array_key_exists('brand_kit_id', $data)) {
                $design->brand_kit_id = $data['brand_kit_id'] ? $this->kit($data['brand_kit_id'])->id : null;
            }
            if (array_key_exists('lanyard', $data) && $design->kind === 'card') {
                $design->lanyard = $data['lanyard'];
            }
            if ($isLanyard) {
                if (isset($data['width_mm'])) {
                    $design->lanyard_width_id = LanyardWidth::where('width_mm', $data['width_mm'])->value('id');
                }
                if (isset($data['hardware'])) {
                    $design->lanyard_hardware_id = LanyardHardware::where('key', $data['hardware'])->value('id');
                }
                $design->print_sides = $data['print_sides'] ?? $design->print_sides;
                $design->tile_mm = $data['tile_mm'] ?? $design->tile_mm;
                if (array_key_exists('paired_design_id', $data)) {
                    $this->pair($design, $data['paired_design_id']);
                }
            }
            $this->normalize($design, $data['front'] ?? null, $data['back'] ?? null);
            if (isset($data['name'])) {
                $design->name = $data['name'];
            }
            $design->version++;
            $design->save();
        });
        $design = $this->loaded($design->refresh());

        return response()->json(['data' => $this->present($design) + ['issues' => $this->issues($design)]]);
    }

    /** POST /api/designs/{card}/lanyard — buat lanyard pasangan kartu (brand kit bersama, slot lanyard kartu otomatis aktif). */
    public function createPairedLanyard(Design $design): JsonResponse
    {
        $this->own($design);
        if ($design->kind !== 'card') {
            throw new ApiError('not_a_card', 'Lanyard hanya bisa dipasangkan dengan desain kartu.');
        }
        if ($existing = $design->pairedLanyard) {
            return response()->json(['data' => $this->present($this->loaded($existing))]);
        }
        $lanyard = DB::transaction(fn () => $this->createLanyard($design));

        return response()->json(['data' => $this->present($this->loaded($lanyard))], 201);
    }

    /** POST /api/designs/{id}/template — simpan sebagai template milik sendiri. */
    public function saveAsTemplate(Request $request, Design $design): JsonResponse
    {
        $this->own($design);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120']]);
        $copy = $design->replicate(['public_id', 'paired_design_id', 'validated_at']);
        $copy->fill(['name' => ($data['name'] ?? null) ?: $design->name.' (template)', 'is_template' => true, 'version' => 1])->save();

        return response()->json(['data' => ['id' => $copy->public_id, 'name' => $copy->name]], 201);
    }

    public function validateDesign(Design $design): JsonResponse
    {
        $this->own($design);
        $issues = $this->issues($design);

        return response()->json(['data' => ['ok' => DesignValidator::ok($issues), 'issues' => $issues]]);
    }

    public function destroy(Design $design): JsonResponse
    {
        $this->own($design);
        if ($design->isOrdered()) {
            throw new ApiError('design_ordered', 'Desain ini sudah dipesan sehingga tidak dapat dihapus. Batalkan pesanannya terlebih dahulu, atau gandakan desain untuk membuat versi baru.', 422);
        }
        $design->delete();

        return response()->json(null, 204);
    }

    /** POST /api/designs/{id}/assets — logo/foto untuk desain (GD re-encode PNG, buang metadata). */
    public function asset(Request $request, Design $design): JsonResponse
    {
        $this->own($design);
        $request->validate(['image' => ['required', 'file']]);
        $img = ImageResizer::load($request->file('image'));
        imagesavealpha($img, true);
        $path = "t{$design->tenant_id}/designs/".Str::random(32).'.png';
        Storage::disk('public')->put($path, ImageResizer::png($img));

        return response()->json(['data' => ['asset' => $path, 'url' => Storage::disk('public')->url($path), 'px_w' => imagesx($img), 'px_h' => imagesy($img)]], 201);
    }

    // ── internal ─────────────────────────────────────────────────────────────

    private function loaded(Design $d): Design
    {
        return $d->load(['brandKit', 'pairedCard', 'pairedLanyard', 'lanyardWidth', 'lanyardHardware', 'merchProduct']);
    }

    /** Desain merchandise baru: teks nama/merek di tengah area cetak, bahan putih, memakai brand kit terbaru bila ada. */
    private function createMerch(string $productKey, ?string $name): Design
    {
        $product = MerchProduct::where('key', $productKey)->where('is_active', true)->firstOrFail();
        $kit = BrandKit::where('user_id', $this->user()->id)->latest('updated_at')->first();
        $g = new MerchGeometry($product->print_w_mm, $product->print_h_mm);
        $side = $g->starter($kit ? '{{brand}}' : '{{name}}', Palette::hex('bone-0'), $kit ? 'kit:primary' : Palette::hex('ink-950'));
        $design = new Design([
            'user_id' => $this->user()->id, 'kind' => 'merch', 'name' => $name ?: "{$product->name} baru",
            'orientation' => $g->portrait() ? 'portrait' : 'landscape', 'lanyard' => false, 'brand_kit_id' => $kit?->id,
            'merch_product_id' => $product->id, 'print_sides' => $product->print_sides,
            'front_json' => $side, 'back_json' => array_replace($side, ['layers' => []]), 'locked_layer_ids' => [], // produk 1 sisi: belakang tak dipakai
        ]);
        $this->normalize($design);
        $design->save();

        return $design;
    }

    private function createLanyard(?Design $card, ?string $name = null): Design
    {
        $kit = $card?->brandKit ?? ($card ? $this->kitFromCard($card) : null);
        if ($card && ! $card->brand_kit_id && $kit) {
            $card->brand_kit_id = $kit->id;
        }
        $width = LanyardWidth::where('is_active', true)->orderByRaw('abs(width_mm - 20)')->firstOrFail();
        $hardware = LanyardHardware::where('is_active', true)->orderBy('sort')->firstOrFail();
        $g = new LanyardGeometry($width->width_mm, 150);
        $tile = $g->starterTile($kit ? '{{brand}}' : 'TAPCARD', $kit ? 'kit:primary' : Palette::hex('onyx-950'), $kit ? 'kit:background' : Palette::hex('bone-50'));
        $lanyard = Design::create([
            'user_id' => $this->user()->id, 'kind' => 'lanyard',
            'name' => $name ?: ($card ? "Lanyard — {$card->name}" : 'Lanyard baru'),
            'orientation' => 'landscape', 'lanyard' => false, 'brand_kit_id' => $kit?->id, 'paired_design_id' => $card?->id,
            'lanyard_width_id' => $width->id, 'lanyard_hardware_id' => $hardware->id, 'print_sides' => 2, 'tile_mm' => 150,
            'front_json' => $tile, 'back_json' => $tile, 'locked_layer_ids' => [],
        ]);
        if ($card) {
            if (! $card->lanyard) {
                $card->lanyard = true; // slot lanyard otomatis: kartu yang dipasangkan butuh lubang di atas
                $this->normalize($card);
            }
            $card->save();
        }

        return $lanyard;
    }

    /** Brand kit awal dari warna kartu: latar depan → utama, aksen yang ada → sekunder, teks → teks. */
    private function kitFromCard(Design $card): BrandKit
    {
        $front = $card->front_json;
        $bg = $front['background']['value'] ?? null;
        $bg = Palette::isHex($bg) ? strtoupper($bg) : Palette::hex('onyx-950');
        $layers = collect($front['layers'] ?? [])->reject(fn ($l) => $l['locked'] ?? false);
        $text = $layers->first(fn ($l) => ($l['type'] ?? '') === 'text' && Palette::isHex($l['color'] ?? null))['color'] ?? Palette::inkOn($bg);
        $accent = $layers->first(fn ($l) => in_array($l['type'] ?? '', ['rect', 'rings'], true) && Palette::isHex($l['fill'] ?? $l['color'] ?? null));
        $vars = $this->variables();
        $company = $vars['company'] !== Variables::SAMPLE['company'] ? $vars['company'] : null;

        return BrandKit::create([
            'user_id' => $this->user()->id, 'name' => 'Merek '.($company ?? $vars['name']),
            'primary_color' => $bg, 'secondary_color' => strtoupper($accent['fill'] ?? $accent['color'] ?? Palette::hex('champagne-500')),
            'background_color' => Palette::inkOn($bg), 'text_color' => strtoupper($text),
            'display_name' => $company ?? $vars['name'],
        ]);
    }

    private function pair(Design $lanyard, ?string $cardId): void
    {
        if (! $cardId) {
            $lanyard->paired_design_id = null;

            return;
        }
        $card = Design::where('public_id', $cardId)->where('user_id', $this->user()->id)->where('kind', 'card')->firstOrFail();
        if (Design::where('paired_design_id', $card->id)->whereKeyNot($lanyard->id)->exists()) {
            throw new ApiError('card_already_paired', 'Kartu ini sudah dipasangkan dengan lanyard lain.');
        }
        $lanyard->paired_design_id = $card->id;
        $lanyard->brand_kit_id ??= $card->brand_kit_id;
        if (! $card->brand_kit_id && $lanyard->brand_kit_id) {
            $card->brand_kit_id = $lanyard->brand_kit_id;
        }
        if (! $card->lanyard) {
            $card->lanyard = true;
            $this->normalize($card);
        }
        $card->save();
    }

    /** Bangun ulang sisi desain dengan geometri & brand kit terbaru (elemen sistem kartu ikut diposisikan ulang). */
    private function normalize(Design $design, ?array $front = null, ?array $back = null): void
    {
        $kit = $design->brand_kit_id ? BrandKit::find($design->brand_kit_id)?->toKit() : null;
        $n = new DesignNormalizer($this->geometry($design), $this->tenant()->id, $kit['colors'] ?? []);
        $f = $n->side($front ?? $design->front_json, 'front');
        $b = $n->side($back ?? $design->back_json, 'back');
        $design->fill(['front_json' => $f, 'back_json' => $b, 'locked_layer_ids' => $this->lockedIds(['front' => $f, 'back' => $b])]);
        $design->validated_at = DesignValidator::ok($this->issues($design, $kit)) ? now() : null;
    }

    private function kit(string $publicId): BrandKit
    {
        return $this->kits()->where('public_id', $publicId)->firstOrFail();
    }

    /** Kit yang boleh dipakai: milik saya, atau kit workspace (logo organisasi yang diunggah admin). */
    private function kits()
    {
        return BrandKit::where(fn ($q) => $q->where('user_id', $this->user()->id)->orWhere('is_workspace', true));
    }

    private function present(Design $d, bool $full = true): array
    {
        $p = $d->kind === 'lanyard' ? $d->pairedCard : $d->pairedLanyard;
        $out = [
            'id' => $d->public_id,
            'kind' => $d->kind,
            'name' => $d->name,
            'template' => $d->template_key,
            'orientation' => $d->orientation,
            'lanyard' => $d->lanyard,
            'validated' => $d->validated_at !== null,
            'ordered' => $d->isOrdered(),
            'updated_at' => $d->updated_at?->toIso8601String(),
            'front' => $d->front_json,
            'back' => $d->back_json,
            'kit' => $d->brandKit?->toKit(),
            'paired' => $p ? ['id' => $p->public_id, 'kind' => $p->kind, 'name' => $p->name, 'orientation' => $p->orientation, 'front' => $p->front_json, 'back' => $p->back_json] : null,
        ];
        if ($d->kind === 'merch' && $d->merchProduct) {
            $out['merch_spec'] = $d->merchProduct->present();
        }
        if ($d->kind === 'lanyard') {
            $out['lanyard_spec'] = [
                'width_mm' => $d->lanyardWidth?->width_mm, 'hardware' => $d->lanyardHardware?->key,
                'print_sides' => $d->print_sides, 'tile_mm' => $d->tile_mm, 'length_mm' => LanyardGeometry::LENGTH,
            ];
        }
        if ($full) {
            $out['locked_layer_ids'] = $d->locked_layer_ids;
        }

        return $out;
    }

    private function issues(Design $d, ?array $kit = null): array
    {
        $kit ??= $d->brand_kit_id ? BrandKit::find($d->brand_kit_id)?->toKit() : null;

        return (new DesignValidator($this->geometry($d), Variables::forKit($kit) + $this->variables(), $kit))->validate($d->front_json, $d->back_json);
    }

    private function geometry(Design $d): Geometry
    {
        if ($d->kind === 'merch') {
            $p = $d->merchProduct ?? MerchProduct::findOrFail($d->merch_product_id);

            return new MerchGeometry($p->print_w_mm, $p->print_h_mm);
        }
        if ($d->kind === 'lanyard') {
            $w = $d->lanyard_width_id ? LanyardWidth::find($d->lanyard_width_id)?->width_mm : null;

            return new LanyardGeometry((float) ($w ?? 20), (float) ($d->tile_mm ?? 150));
        }

        return new CardGeometry($d->orientation, $d->lanyard);
    }

    private function lockedIds(array $sides): array
    {
        return collect([...$sides['front']['layers'], ...$sides['back']['layers']])->where('locked', true)->pluck('id')->values()->all();
    }

    private function variables(): array
    {
        $profile = Profile::where('user_id', $this->user()->id)->whereNull('archived_at')->with('fields')->orderBy('id')->first();

        return Variables::forUser($this->user(), $profile);
    }

    private function own(Design $d): void
    {
        abort_unless($d->user_id === $this->user()->id, 404);
    }
}
