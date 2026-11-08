<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CardProduct;
use App\Models\LanyardWidth;
use App\Models\MerchProduct;
use App\Models\Plan;
use App\Support\Bio\BioBlocks;
use App\Support\Bio\BioTheme;
use App\Support\Design\CardGeometry;
use App\Support\Design\LanyardGeometry;
use App\Support\Design\LanyardPricing;
use App\Support\Design\Palette;
use App\Support\Design\TemplateLibrary;
use App\Support\Qr\QrEncoder;
use App\Support\Qr\SvgRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * GET {base}/api/showcase — data publik landing page: template kartu default (dirender dengan
 * komponen yang sama dengan Studio), preset bio link, contoh profil, dan paket. Tanpa data pengguna.
 */
class ShowcaseController extends Controller
{
    /** Contoh yang realistis untuk etalase (Variables::SAMPLE berisi label placeholder untuk Studio). */
    public const PERSONA = [
        'name' => 'Rina Saputra', 'title' => 'Konsultan Pajak', 'company' => 'Saputra & Rekan',
        'phone' => '+62 812 3456 7890', 'email' => 'rina@saputra.id', 'website' => 'saputra.id',
    ];

    public function __invoke(): JsonResponse
    {
        $data = Cache::remember('showcase:v7', now()->addHour(), function () {
            $qr = QrEncoder::encode(config('tapcard.resolver_url').'/c/XXXXXXXXXX?src=qr');

            return [
                'templates' => array_map(fn ($t) => $t + ['preview' => [
                    'landscape' => TemplateLibrary::make($t['key'], new CardGeometry('landscape')),
                    'portrait' => TemplateLibrary::make($t['key'], new CardGeometry('portrait')),
                ]], array_values(array_filter(TemplateLibrary::catalog(), fn ($t) => $t['key'] !== 'kosong'))),
                'variables' => self::PERSONA,
                'qr' => ['modules' => $qr->size, 'path' => SvgRenderer::path($qr)],
                'bio' => BioTheme::catalog(),
                'editor' => self::editor(),
                'sample' => self::sampleProfile(),
                'plans' => Plan::where('is_public', true)->where('tenant_type', 'personal')->orderBy('sort')->get()
                    ->map(fn (Plan $p) => [
                        'key' => $p->key, 'name' => $p->name, 'price_monthly' => $p->price_monthly,
                        'cards' => $p->limits['cards_active'] ?? null, 'connections' => $p->limits['connections'] ?? null,
                        'analytics_days' => $p->limits['analytics_days'] ?? null,
                    ])->values()->all(),
                // paket Tim & Perusahaan: harga per anggota / bulan, uji coba gratis
                'company_plans' => Plan::where('is_public', true)->where('tenant_type', 'company')->orderBy('sort')->get()
                    ->map(fn (Plan $p) => [
                        'key' => $p->key, 'name' => $p->name, 'price_monthly' => $p->price_monthly, 'per_seat' => (bool) $p->per_seat,
                        'trial_days' => $p->trial_days, 'members' => $p->limits['members'] ?? null,
                        'cards_per_member' => $p->limits['cards_per_member'] ?? null, 'analytics_days' => $p->limits['analytics_days'] ?? null,
                        'features' => array_keys(array_filter($p->features ?? [])),
                    ])->values()->all(),
                // paket kartu digital (terpisah dari paket workspace)
                'digital_card' => [
                    'tap_quota' => (int) config('tapcard.digital_card.tap_quota'), 'bio_pages' => (int) config('tapcard.digital_card.bio_pages'),
                    'topup_taps' => (int) config('tapcard.digital_card.topup_taps'), 'topup_price' => (int) config('tapcard.digital_card.topup_price'),
                ],
                'physical_bio_pages' => BioBlocks::MAX_PAGES,
                'card_products' => CardProduct::where('is_active', true)->orderBy('sort')->get()
                    ->map(fn (CardProduct $c) => ['name' => $c->name, 'price' => $c->price, 'description' => $c->description, 'chip' => $c->chip_type?->value])->values()->all(),
                'lanyard_from' => LanyardWidth::where('is_active', true)->min('price_single'),
                'merch' => MerchProduct::where('is_active', true)->orderBy('sort')->get()
                    ->map(fn (MerchProduct $m) => ['name' => $m->name, 'coming_soon' => $m->status === 'coming_soon'])->values()->all(),
            ];
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'public, max-age=600');
    }

    /**
     * Data etalase editor: kartu potret dengan slot lanyard (pasangan lanyard), pola lanyard per lebar tali
     * (depan gelap, belakang terang) dan katalog harga dari tabel lanyard_widths / lanyard_hardware.
     */
    private static function editor(): array
    {
        $catalog = LanyardPricing::catalog();
        $brand = self::PERSONA['company'];
        $tiles = [];
        foreach ($catalog['widths'] as $w) {
            $g = new LanyardGeometry((float) $w['width_mm'], 150);
            $tiles[$w['width_mm']] = [
                'front' => $g->starterTile($brand, Palette::hex('onyx-950'), Palette::hex('champagne-300')),
                'back' => $g->starterTile($brand, Palette::hex('bone-50'), Palette::hex('onyx-950')),
            ];
        }

        return [
            'paired_card' => TemplateLibrary::make('onyx', new CardGeometry('portrait', true)),
            'lanyards' => $tiles,
            // selalu array JSON (bukan objek) — klien memakai .find/.map
            'widths' => collect($catalog['widths'])->values()->all(),
            'hardware' => collect($catalog['hardware'])->values()->all(),
        ];
    }

    /** Bentuk sama dengan PublicProfile (mode preview: tanpa URL aktif). */
    private static function sampleProfile(): array
    {
        $v = self::PERSONA;

        return [
            'token' => 'preview000',
            'display_name' => $v['name'],
            'title' => $v['title'],
            'company' => $v['company'],
            'bio' => 'Membantu UMKM merapikan pajak & pembukuan. Konsultasi pertama gratis.',
            'avatar_url' => null,
            'theme' => BioTheme::resolve(['preset' => 'onyx']),
            'locale' => 'id',
            'indexable' => false,
            'event' => null,
            'brand' => null,
            'quick' => ['whatsapp' => '#', 'phone' => '#', 'email' => '#'],
            'links' => [
                ['id' => 's1', 'type' => 'url', 'group' => 'business', 'icon' => 'Globe', 'action' => 'link', 'label' => 'Website', 'display' => 'saputra.id', 'url' => null, 'copy' => null, 'image' => null],
                ['id' => 's2', 'type' => 'instagram', 'group' => 'social', 'icon' => 'Instagram', 'action' => 'link', 'label' => 'Instagram', 'display' => '@saputra.rekan', 'url' => null, 'copy' => null, 'image' => null],
                ['id' => 's3', 'type' => 'linkedin', 'group' => 'social', 'icon' => 'Linkedin', 'action' => 'link', 'label' => 'LinkedIn', 'display' => '@rina-saputra', 'url' => null, 'copy' => null, 'image' => null],
                ['id' => 's4', 'type' => 'booking', 'group' => 'business', 'icon' => 'CalendarCheck', 'action' => 'link', 'label' => 'Jadwalkan konsultasi', 'display' => 'calendly.com/rina', 'url' => null, 'copy' => null, 'image' => null],
                ['id' => 's5', 'type' => 'shopee', 'group' => 'store', 'icon' => 'ShoppingBag', 'action' => 'link', 'label' => 'Shopee', 'display' => '@saputra-store', 'url' => null, 'copy' => null, 'image' => null],
                ['id' => 's6', 'type' => 'bank', 'group' => 'payment', 'icon' => 'Landmark', 'action' => 'copy', 'label' => 'Rekening bank', 'display' => 'BCA · 1234567890 a.n. Rina Saputra', 'url' => null, 'copy' => '1234567890', 'image' => null],
                ['id' => 's7', 'type' => 'address', 'group' => 'business', 'icon' => 'MapPin', 'action' => 'map', 'label' => 'Kantor', 'display' => 'Jl. Sudirman 21, Jakarta', 'url' => null, 'copy' => null, 'image' => null],
            ],
            'vcard_url' => null,
            'exchange' => ['url' => null, 't' => null],
        ];
    }
}
