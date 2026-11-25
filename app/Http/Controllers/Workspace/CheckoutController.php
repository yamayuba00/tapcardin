<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Orders\CreateOrder;
use App\Exceptions\ApiError;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Draf checkout wizard: GET/PATCH menyimpan pilihan (item keranjang, alamat, promo) di server;
 * POST …/pay membuat pesanan dari draf (item & harga dihitung ulang dari keranjang saat itu).
 */
class CheckoutController extends WorkspaceController
{
    private const TTL_HOURS = 24;

    /** POST /api/checkouts — {cart?: [...], shipping?, promo?}; cart kosong = semua item keranjang. */
    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize('orders.create');
        $data = $request->validate($this->rules());
        $cart = $data['cart'] ?? CartItem::where('user_id', $this->user()->id)->pluck('public_id')->all();
        $c = Checkout::create([
            'tenant_id' => $this->tenant()->id, 'user_id' => $this->user()->id, 'status' => 'open', 'cart_ids' => $this->ownCart($cart),
            'shipping' => $data['shipping'] ?? null, 'promo' => $data['promo'] ?? null, 'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        return response()->json(['data' => $this->detail($c)], 201);
    }

    /** GET /api/checkouts/{checkout} */
    public function show(string $checkout): JsonResponse
    {
        $this->access()->authorize('orders.create');

        return response()->json(['data' => $this->detail($this->open($checkout))]);
    }

    /** PATCH /api/checkouts/{checkout} — simpan sebagian: cart, shipping, promo. */
    public function update(Request $request, string $checkout): JsonResponse
    {
        $this->access()->authorize('orders.create');
        $c = $this->open($checkout);
        $data = $request->validate($this->rules());
        if (array_key_exists('cart', $data)) {
            $c->cart_ids = $this->ownCart($data['cart']);
        }
        if (array_key_exists('shipping', $data)) {
            $c->shipping = $data['shipping'];
        }
        if (array_key_exists('promo', $data)) {
            $c->promo = $data['promo'];
        }
        $c->save();

        return response()->json(['data' => $this->detail($c)]);
    }

    /** POST /api/checkouts/{checkout}/pay — membuat pesanan dari draf lalu mengembalikan tautan bayar. */
    public function pay(string $checkout, CreateOrder $create, Request $request): JsonResponse
    {
        $this->access()->authorize('orders.create');
        $c = $this->open($checkout);
        $origin = $request->getSchemeAndHttpHost();

        // baris draf dikunci: klik ganda atau dua tab tidak bisa membuat dua pesanan dari draf yang sama
        $out = DB::transaction(function () use ($c, $create, $origin) {
            $locked = Checkout::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') {
                $order = Order::findOrFail($locked->order_id);

                return ['order' => $order, 'payment_url' => CreateOrder::payUrl($order, $this->user(), $origin), 'created' => false];
            }
            $ship = $locked->shipping ?? [];
            if (blank($ship['method'] ?? null)) {
                throw new ApiError('checkout_shipping', 'Pilih metode pengiriman dulu.', 422, ['field' => 'shipping.method']);
            }
            $items = CartItem::where('user_id', $this->user()->id)->whereIn('public_id', $locked->cart_ids ?? [])->with(['design', 'product'])->get();
            if ($items->isEmpty()) {
                throw new ApiError('checkout_empty', 'Tidak ada item yang dipilih. Pilih item dari keranjang.', 422);
            }
            $payload = $items->map(fn (CartItem $i) => array_filter([
                'kind' => $i->kind, 'design' => $i->design->public_id, 'product' => $i->product?->key, 'quantity' => $i->quantity,
            ], fn ($v) => $v !== null))->values()->all();

            $r = $create->handle($this->tenant(), $this->user(), $payload, $ship, $origin, $locked->promo, $locked->cart_ids ?? []);
            $locked->update(['status' => 'paid', 'order_id' => $r['order']->id]);

            return $r + ['created' => true];
        });

        return response()->json(['data' => ['order' => OrderController::detail($out['order']), 'payment_url' => $out['payment_url']]], $out['created'] ? 201 : 200);
    }

    private function rules(): array
    {
        return [
            'cart' => ['sometimes', 'array', 'max:20'],
            'cart.*' => ['string', 'size:26'],
            'shipping' => ['sometimes', 'nullable', 'array'],
            'shipping.method' => ['nullable', 'string', 'max:32'],
            'shipping.recipient_name' => ['nullable', 'string', 'max:120'],
            'shipping.recipient_phone' => ['nullable', 'string', 'max:32'],
            'shipping.address_line' => ['nullable', 'string', 'max:300'],
            'shipping.district' => ['nullable', 'string', 'max:80'],
            'shipping.city' => ['nullable', 'string', 'max:80'],
            'shipping.province' => ['nullable', 'string', 'max:80'],
            'shipping.postal_code' => ['nullable', 'string', 'max:10'],
            'shipping.notes' => ['nullable', 'string', 'max:300'],
            'shipping.courier' => ['nullable', 'string', 'max:20'],
            'shipping.service' => ['nullable', 'string', 'max:20'],
            'promo' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    /** Draf milik pengguna ini; kedaluwarsa → 410. */
    private function open(string $id): Checkout
    {
        $c = Checkout::where('user_id', $this->user()->id)->where('public_id', $id)->first()
            ?? throw new ApiError('checkout_not_found', 'Checkout tidak ditemukan.', 404);
        if ($c->status === 'open' && $c->expires_at->isPast()) {
            throw new ApiError('checkout_expired', 'Sesi checkout sudah berakhir. Mulai lagi dari keranjang.', 410);
        }

        return $c;
    }

    /** Hanya item keranjang milik pengguna ini yang disimpan. */
    private function ownCart(array $ids): array
    {
        return CartItem::where('user_id', $this->user()->id)->whereIn('public_id', $ids)->pluck('public_id')->values()->all();
    }

    private function detail(Checkout $c): array
    {
        return [
            'id' => $c->public_id, 'status' => $c->status, 'expires_at' => $c->expires_at->toIso8601String(),
            'cart' => $c->cart_ids ?? [], 'shipping' => $c->shipping, 'promo' => $c->promo,
            'order' => $c->order_id ? ['id' => $c->order?->public_id, 'number' => $c->order?->number] : null,
        ];
    }
}
