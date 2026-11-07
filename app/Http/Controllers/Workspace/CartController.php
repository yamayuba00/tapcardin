<?php

namespace App\Http\Controllers\Workspace;

use App\Exceptions\ApiError;
use App\Models\CardProduct;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\LanyardHardware;
use App\Models\LanyardWidth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Keranjang cetak: kartu, ID badge, & lanyard ditambahkan satu-satu atau sebagai paket (kartu + lanyard pasangannya).
 * Saat checkout pengguna memilih item mana yang dipesan; harga final tetap dihitung CreateOrder::quote().
 */
class CartController extends WorkspaceController
{
    /** GET /api/cart */
    public function index(): JsonResponse
    {
        $this->access()->authorize('orders.create');

        return response()->json(['data' => $this->items()]);
    }

    /** POST /api/cart — {design, product?, quantity?, with_lanyard?}: tambah (atau tambahkan jumlah) item; paket = sekaligus lanyard pasangan kartu. */
    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize('orders.create');
        $data = $request->validate([
            'design' => ['required', 'string', 'size:26'],
            'product' => ['nullable', 'string', 'max:32'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'with_lanyard' => ['boolean'],
        ]);
        $design = $this->design($data['design']);
        $added = $this->put($design, $data['product'] ?? null, $data['quantity'] ?? null);
        if (($data['with_lanyard'] ?? false) && $design->kind === 'card') {
            $lanyard = $design->pairedLanyard()->first();
            if (! $lanyard) {
                throw new ApiError('no_paired_lanyard', 'Kartu ini belum punya lanyard pasangan. Buat dari editor kartu → tab Lanyard.');
            }
            $this->put($this->design($lanyard->public_id), null, $data['quantity'] ?? null);
        }

        return response()->json(['data' => $this->items(), 'meta' => ['added' => $added->public_id]], 201);
    }

    /** PATCH /api/cart/{id} — {quantity?, product?} */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->access()->authorize('orders.create');
        $item = $this->find($id);
        $data = $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'product' => ['sometimes', 'string', Rule::exists('card_products', 'key')->where('is_active', true)],
        ]);
        if (isset($data['product']) && $item->kind === 'card') {
            $p = $this->product($item->design, $data['product']);
            // produk yang sama untuk desain yang sama sudah ada → gabungkan jumlahnya
            $twin = CartItem::where('user_id', $this->user()->id)->where('design_id', $item->design_id)->where('card_product_id', $p->id)->whereKeyNot($item->id)->first();
            if ($twin) {
                $twin->update(['quantity' => min(5000, $twin->quantity + ($data['quantity'] ?? $item->quantity))]);
                $item->delete();

                return response()->json(['data' => $this->items()]);
            }
            $item->card_product_id = $p->id;
        }
        if (isset($data['quantity'])) {
            $item->quantity = $data['quantity'];
        }
        $item->quantity = max($item->quantity, $this->minQty($item));
        $item->save();

        return response()->json(['data' => $this->items()]);
    }

    /** DELETE /api/cart/{id} */
    public function destroy(string $id): JsonResponse
    {
        $this->access()->authorize('orders.create');
        $this->find($id)->delete();

        return response()->json(['data' => $this->items()]);
    }

    private function put(Design $design, ?string $productKey, ?int $qty): CartItem
    {
        $productId = $design->kind === 'card' ? $this->product($design, $productKey)->id : null;
        $item = CartItem::where('user_id', $this->user()->id)->where('design_id', $design->id)
            ->where(fn ($q) => $productId ? $q->where('card_product_id', $productId) : $q->whereNull('card_product_id'))->first()
            ?? new CartItem(['user_id' => $this->user()->id, 'design_id' => $design->id, 'kind' => $design->kind, 'card_product_id' => $productId, 'quantity' => 0]);
        $item->setRelation('design', $design);
        $item->quantity = min(5000, max($this->minQty($item), $item->quantity + ($qty ?? 0), $item->exists ? 0 : 1));
        $item->save();

        return $item;
    }

    /** Produk kartu: yang dipilih, atau bawaan sesuai bentuk desain (potret berlubang lanyard → ID badge). */
    private function product(Design $design, ?string $key): CardProduct
    {
        $badge = $design->orientation === 'portrait' && $design->lanyard;
        $q = CardProduct::where('is_active', true);
        $p = $key ? (clone $q)->where('key', $key)->first() : (clone $q)->where('form_factor', $badge ? 'id_badge' : 'card')->orderBy('sort')->first();
        $p ??= (clone $q)->where('form_factor', 'card')->orderBy('sort')->first();
        if (! $p) {
            throw new ApiError('product_unavailable', 'Jenis kartu tidak tersedia.');
        }
        if ($p->form_factor === 'id_badge' && ! $badge) {
            throw new ApiError('id_badge_design', "{$p->name} butuh desain kartu potret dengan lubang lanyard.");
        }

        return $p;
    }

    private function minQty(CartItem $item): int
    {
        if ($item->kind === 'card') {
            return (int) (CardProduct::find($item->card_product_id)?->min_qty ?? 1);
        }

        return (int) (LanyardWidth::find($item->design->lanyard_width_id)?->min_qty ?? 1);
    }

    private function design(string $id): Design
    {
        $d = Design::where('public_id', $id)->where('user_id', $this->user()->id)->where('is_template', false)->whereIn('kind', ['card', 'lanyard'])->first()
            ?? throw new ApiError('design_not_found', 'Desain tidak ditemukan.', 404);
        if (! $d->validated_at) {
            throw new ApiError('design_not_ready', "Desain “{$d->name}” belum lolos cek cetak. Buka editor → tab Cek cetak, perbaiki, lalu simpan.");
        }

        return $d;
    }

    private function find(string $id): CartItem
    {
        return CartItem::where('user_id', $this->user()->id)->where('public_id', $id)->with('design')->firstOrFail();
    }

    /** Isi keranjang + harga satuan & minimum (pratinjau; harga final dari server saat checkout). */
    private function items(): array
    {
        $items = CartItem::where('user_id', $this->user()->id)->with(['design.brandKit', 'design.pairedLanyard', 'design.pairedCard', 'product'])->orderBy('id')->get()
            ->filter(fn (CartItem $i) => $i->design !== null);
        $widths = LanyardWidth::whereIn('id', $items->pluck('design.lanyard_width_id')->filter())->get()->keyBy('id');
        $hardware = LanyardHardware::whereIn('id', $items->pluck('design.lanyard_hardware_id')->filter())->get()->keyBy('id');

        return $items->map(function (CartItem $i) use ($widths, $hardware) {
            $d = $i->design;
            if ($i->kind === 'card') {
                $unit = (int) $i->product?->price;
                $min = (int) ($i->product?->min_qty ?? 1);
            } else {
                $w = $widths->get($d->lanyard_width_id);
                $h = $hardware->get($d->lanyard_hardware_id);
                $unit = $w ? ((int) $d->print_sides === 1 ? $w->price_single : $w->price_double) + (int) ($h?->price ?? 0) : 0;
                $min = (int) ($w?->min_qty ?? 1);
            }

            return [
                'id' => $i->public_id, 'kind' => $i->kind, 'quantity' => $i->quantity, 'unit_price' => $unit, 'min_qty' => $min,
                'product' => $i->product?->key, 'product_name' => $i->product?->name, 'form_factor' => $i->product?->form_factor,
                'design' => [
                    'id' => $d->public_id, 'name' => $d->name, 'kind' => $d->kind, 'orientation' => $d->orientation, 'lanyard' => (bool) $d->lanyard,
                    'ready' => $d->validated_at !== null, 'front' => $d->front_json, 'back' => $d->back_json, 'kit' => $d->brandKit?->toKit(),
                    'paired' => ($p = $d->kind === 'card' ? $d->pairedLanyard : $d->pairedCard) ? ['id' => $p->public_id, 'name' => $p->name] : null,
                    'lanyard_spec' => $d->kind === 'lanyard' ? ['width_mm' => $widths->get($d->lanyard_width_id)?->width_mm, 'print_sides' => (int) $d->print_sides,
                        'hardware' => $hardware->get($d->lanyard_hardware_id)?->name] : null,
                ],
            ];
        })->values()->all();
    }
}
