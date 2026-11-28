<?php

namespace App\Http\Controllers\Platform;

use App\Models\Addon;
use App\Models\CardProduct;
use App\Models\ContactType;
use App\Models\LanyardHardware;
use App\Models\LanyardWidth;
use App\Models\PlatformSetting;
use App\Models\ShippingMethod;
use App\Models\ShippingZoneRate;
use App\Support\Audit;
use App\Support\CardPeriod;
use App\Support\Contacts\ContactTypes;
use App\Support\Production\PrintSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Katalog (platform.catalog.manage): produk kartu & ongkir, harga lanyard, jenis kontak bio link, add-on (harga, status, peminat). */
class CatalogController extends PlatformController
{
    public function show(): JsonResponse
    {
        $this->can('catalog.manage');

        return $this->ok([
            'card_renewal' => ['price' => CardPeriod::renewalPrice(), 'months' => CardPeriod::months(), 'grace_days' => CardPeriod::graceDays()],
            'production' => ['cards_per_day' => PrintSchedule::capacity()],
            'card_products' => CardProduct::orderBy('sort')->get()->map(fn (CardProduct $p) => $p->only(['public_id', 'key', 'name', 'description', 'price', 'min_qty', 'print_min_days', 'print_max_days', 'sort', 'is_active']) + ['chip_type' => $p->chip_type->value]),
            'shipping_methods' => ShippingMethod::orderBy('sort')->get()->map(fn ($m) => $m->only(['public_id', 'key', 'name', 'eta', 'price', 'needs_address', 'sort', 'is_active'])),
            'shipping_rates' => ShippingZoneRate::orderBy('zone_code')->orderBy('courier')->orderBy('service')->get()->map(fn ($r) => ['id' => $r->id, 'zone' => $r->zone_code, 'courier' => $r->courier, 'service' => $r->service, 'base_cost' => $r->base_cost, 'per_kg_cost' => $r->per_kg_cost, 'etd_min_days' => $r->etd_min_days, 'etd_max_days' => $r->etd_max_days, 'is_placeholder' => $r->is_placeholder]),
            'lanyard_widths' => LanyardWidth::orderBy('sort')->get()->map(fn ($w) => $w->only(['public_id', 'width_mm', 'label', 'price_single', 'price_double', 'min_qty', 'sort', 'is_active'])),
            'lanyard_hardware' => LanyardHardware::orderBy('sort')->get()->map(fn ($h) => $h->only(['public_id', 'key', 'name', 'description', 'price', 'sort', 'is_active'])),
            'addons' => Addon::withCount('interests')->orderBy('sort')->get()->map(fn (Addon $a) => $a->only([
                'public_id', 'key', 'name', 'description', 'features', 'price_monthly', 'status', 'sort',
            ]) + ['interests' => $a->interests_count]),
            'contact_types' => ContactType::with('group')->orderBy('group_id')->orderBy('sort')->get()->map(fn ($t) => [
                'public_id' => $t->public_id, 'key' => $t->key, 'label' => $t->label, 'group' => $t->group->name, 'input' => $t->input,
                'icon' => $t->icon, 'sort' => $t->sort, 'is_active' => $t->is_active,
            ]),
        ]);
    }

    public function saveWidth(Request $request, ?string $id = null): JsonResponse
    {
        $this->can('catalog.manage');
        $w = $id ? LanyardWidth::where('public_id', $id)->firstOrFail() : new LanyardWidth;
        $data = $request->validate([
            'width_mm' => [$id ? 'sometimes' : 'required', 'integer', 'min:10', 'max:40', Rule::unique('lanyard_widths', 'width_mm')->ignore($w->id)],
            'label' => [$id ? 'sometimes' : 'required', 'string', 'max:60'],
            'price_single' => [$id ? 'sometimes' : 'required', 'integer', 'min:0'],
            'price_double' => [$id ? 'sometimes' : 'required', 'integer', 'min:0'],
            'min_qty' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $before = $w->exists ? $w->toArray() : null;
        $w->fill($data)->save();
        Audit::platform($id ? 'catalog.lanyard_width.updated' : 'catalog.lanyard_width.created', $w, $before, $w->toArray());

        return $this->show();
    }

    /** PATCH /api/catalog/production {cards_per_day} — kapasitas cetak: kartu yang mulai dicetak per hari kerja. */
    public function saveProduction(Request $request): JsonResponse
    {
        $this->can('catalog.manage');
        $data = $request->validate(['cards_per_day' => ['required', 'integer', 'min:1', 'max:100000']]);
        $before = PlatformSetting::get('production.cards_per_day');
        PlatformSetting::put('production.cards_per_day', (int) $data['cards_per_day'], auth()->id());
        Audit::platform('catalog.production_capacity', PlatformSetting::find('production.cards_per_day'), ['cards_per_day' => $before], ['cards_per_day' => (int) $data['cards_per_day']]);

        return $this->show();
    }

    /** PATCH /api/catalog/card-renewal {price} — harga perpanjangan masa pakai kartu (per periode `months`). */
    public function saveCardRenewal(Request $request): JsonResponse
    {
        $this->can('catalog.manage');
        $data = $request->validate(['price' => ['required', 'integer', 'min:0', 'max:100000000']]);
        PlatformSetting::put('card_renewal.price', (int) $data['price'], auth()->id());
        Audit::platform('catalog.card_renewal_price', PlatformSetting::find('card_renewal.price'), null, ['price' => (int) $data['price']]);

        return $this->show();
    }

    public function saveCardProduct(Request $request, ?string $id = null): JsonResponse
    {
        $this->can('catalog.manage');
        $p = $id ? CardProduct::where('public_id', $id)->firstOrFail() : new CardProduct;
        $s = $id ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$s, 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:200'],
            'chip_type' => [$s, Rule::in(['ntag213', 'ntag215', 'ntag216', 'ntag424'])],
            'price' => [$s, 'integer', 'min:0', 'max:100000000'],
            'min_qty' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'print_min_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'print_max_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'sort' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $minDays = (int) ($data['print_min_days'] ?? $p->print_min_days ?? 3);
        $maxDays = (int) ($data['print_max_days'] ?? $p->print_max_days ?? 7);
        if ($maxDays < $minDays) {
            throw ValidationException::withMessages(['print_max_days' => 'Lead time maksimal harus sama atau lebih dari minimal.']);
        }
        if (! $id) {
            $data['key'] = Str::limit(Str::slug($data['name'], '_'), 24, '').'_'.Str::lower(Str::random(3));
        }
        $before = $p->exists ? $p->toArray() : null;
        $p->fill($data)->save();
        Audit::platform($id ? 'catalog.card_product.updated' : 'catalog.card_product.created', $p, $before, $p->toArray());

        return $this->show();
    }

    /** PATCH /api/catalog/shipping-rates/{id} — isi tarif sungguhan untuk satu zona, kurir, dan layanan. */
    public function saveZoneRate(Request $request, int $id): JsonResponse
    {
        $this->can('catalog.manage');
        $data = $request->validate([
            'base_cost' => ['required', 'integer', 'min:0', 'max:10000000'],
            'per_kg_cost' => ['required', 'integer', 'min:0', 'max:10000000'],
            'etd_min_days' => ['required', 'integer', 'min:0', 'max:60'],
            'etd_max_days' => ['required', 'integer', 'gte:etd_min_days', 'max:60'],
        ]);
        $r = ShippingZoneRate::findOrFail($id);
        $before = $r->only(array_keys($data));
        $r->update($data + ['is_placeholder' => false]);
        Audit::platform('catalog.shipping_rate.updated', $r, $before, $r->only(array_keys($data)));

        return response()->json(['data' => ['id' => $r->id, 'is_placeholder' => false]]);
    }

    public function saveShipping(Request $request, ?string $id = null): JsonResponse
    {
        $this->can('catalog.manage');
        $m = $id ? ShippingMethod::where('public_id', $id)->firstOrFail() : new ShippingMethod;
        $s = $id ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$s, 'string', 'max:80'],
            'eta' => ['nullable', 'string', 'max:60'],
            'price' => [$s, 'integer', 'min:0', 'max:10000000'],
            'needs_address' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (! $id) {
            $data['key'] = Str::limit(Str::slug($data['name'], '_'), 24, '').'_'.Str::lower(Str::random(3));
        }
        $before = $m->exists ? $m->toArray() : null;
        $m->fill($data)->save();
        Audit::platform($id ? 'catalog.shipping.updated' : 'catalog.shipping.created', $m, $before, $m->toArray());

        return $this->show();
    }

    public function saveHardware(Request $request, ?string $id = null): JsonResponse
    {
        $this->can('catalog.manage');
        $h = $id ? LanyardHardware::where('public_id', $id)->firstOrFail() : new LanyardHardware;
        $data = $request->validate([
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:200'],
            'price' => [$id ? 'sometimes' : 'required', 'integer', 'min:0'],
            'sort' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (! $id) {
            $data['key'] = Str::limit(Str::slug($data['name'], '_'), 24, '').'_'.Str::lower(Str::random(3));
        }
        $before = $h->exists ? $h->toArray() : null;
        $h->fill($data)->save();
        Audit::platform($id ? 'catalog.hardware.updated' : 'catalog.hardware.created', $h, $before, $h->toArray());

        return $this->show();
    }

    public function updateAddon(Request $request, string $id): JsonResponse
    {
        $this->can('catalog.manage');
        $a = Addon::where('public_id', $id)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'string', 'max:300'],
            'features' => ['sometimes', 'array', 'max:8'],
            'features.*' => ['string', 'max:80'],
            'price_monthly' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(Addon::STATUSES)],
            'sort' => ['sometimes', 'integer'],
        ]);
        $before = $a->only(array_keys($data));
        $a->update($data);
        Audit::platform('catalog.addon.updated', $a, $before, $a->only(array_keys($data)));

        return $this->show();
    }

    public function updateContactType(Request $request, string $id): JsonResponse
    {
        $this->can('catalog.manage');
        $t = ContactType::where('public_id', $id)->firstOrFail();
        $data = $request->validate(['label' => ['sometimes', 'string', 'max:60'], 'sort' => ['sometimes', 'integer'], 'is_active' => ['sometimes', 'boolean']]);
        $before = $t->only(['label', 'sort', 'is_active']);
        $t->update($data);
        ContactTypes::forget();
        Audit::platform('catalog.contact_type.updated', $t, $before, $t->only(['label', 'sort', 'is_active']));

        return $this->show();
    }
}
