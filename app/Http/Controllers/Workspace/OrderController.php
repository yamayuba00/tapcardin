<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Billing\ApplyPayment;
use App\Actions\Orders\CreateOrder;
use App\Exceptions\ApiError;
use App\Models\CardProduct;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShippingMethod;
use App\Services\Payment\PaymentService;
use App\Support\Audit;
use App\Support\Design\LanyardPricing;
use App\Support\Http\Table;
use App\Support\Qr\QrEncoder;
use App\Support\Qr\SvgRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pesan kartu fisik & lanyard dari desain sendiri: katalog, buat pesanan + bayar, lacak status, batal sebelum dibayar. */
class OrderController extends WorkspaceController
{
    /** GET /api/orders/catalog — produk kartu, harga lanyard, metode kirim, dan desain milik pengguna (dengan status siap cetak). */
    public function catalog(): JsonResponse
    {
        $this->access()->authorize('orders.create');
        $qr = QrEncoder::encode(config('tapcard.resolver_url').'/c/XXXXXXXXXX?src=qr');
        $designs = Design::where('user_id', $this->user()->id)->where('is_template', false)->with(['brandKit', 'lanyardWidth', 'lanyardHardware'])
            ->latest('updated_at')->get()->map(fn (Design $d) => [
                'id' => $d->public_id, 'kind' => $d->kind, 'name' => $d->name, 'orientation' => $d->orientation, 'ready' => $d->validated_at !== null,
                'front' => $d->front_json, 'back' => $d->back_json, 'kit' => $d->brandKit?->toKit(),
                'lanyard' => $d->kind === 'lanyard' ? ['width_mm' => $d->lanyardWidth?->width_mm, 'hardware' => $d->lanyardHardware?->key, 'print_sides' => (int) $d->print_sides] : null,
            ]);

        return response()->json(['data' => [
            'card_products' => CardProduct::where('is_active', true)->orderBy('sort')->get()->map(fn (CardProduct $p) => [
                'key' => $p->key, 'name' => $p->name, 'description' => $p->description, 'price' => $p->price, 'min_qty' => $p->min_qty, 'chip' => $p->chip_type->value,
            ]),
            'shipping' => ShippingMethod::where('is_active', true)->orderBy('sort')->get()->map(fn (ShippingMethod $m) => [
                'key' => $m->key, 'name' => $m->name, 'eta' => $m->eta, 'price' => $m->price, 'needs_address' => $m->needs_address,
            ]),
            'lanyard' => LanyardPricing::catalog(),
            'designs' => $designs,
            'qr' => ['modules' => $qr->size, 'path' => SvgRenderer::path($qr)],
            'payment' => PaymentService::info(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->access()->authorize('orders.create');
        $q = Order::query()->withCount('items')
            ->when(! $this->access()->has('billing.manage'), fn ($w) => $w->where('user_id', $this->user()->id))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));

        return Table::json(Table::paginate($q, $request, ['created_at' => 'created_at', 'total' => 'total', 'number' => 'number'], '-created_at', ['number'], 10),
            fn (Order $o) => self::summary($o), $request);
    }

    public function store(Request $request, CreateOrder $create): JsonResponse
    {
        $this->access()->authorize('orders.create');
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.kind' => ['required', Rule::in(['card', 'lanyard'])],
            'items.*.design' => ['required', 'string', 'size:26'],
            'items.*.product' => ['nullable', 'string', 'max:32', 'required_if:items.*.kind,card'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:5000'],
            'shipping.method' => ['required', 'string', 'max:32'],
            'shipping.recipient_name' => ['nullable', 'string', 'max:120'],
            'shipping.recipient_phone' => ['nullable', 'string', 'max:32'],
            'shipping.address_line' => ['nullable', 'string', 'max:300'],
            'shipping.city' => ['nullable', 'string', 'max:80'],
            'shipping.province' => ['nullable', 'string', 'max:80'],
            'shipping.postal_code' => ['nullable', 'string', 'max:10', 'regex:/^[0-9]{5}$/'],
            'shipping.notes' => ['nullable', 'string', 'max:300'],
        ], ['shipping.postal_code.regex' => 'Kode pos terdiri dari 5 angka.']);
        $r = $create->handle($this->tenant(), $this->user(), $data['items'], $data['shipping'], $request->getSchemeAndHttpHost());

        return response()->json(['data' => ['order' => self::detail($r['order']), 'payment_url' => $r['payment_url']]], 201);
    }

    public function show(Order $order): JsonResponse
    {
        $this->own($order);

        return response()->json(['data' => self::detail($order)]);
    }

    /** POST /api/orders/{id}/pay — buka (lagi) halaman bayar; invoice baru bila yang lama kedaluwarsa. */
    public function pay(Request $request, Order $order): JsonResponse
    {
        $this->own($order);
        if ($order->status !== 'pending_payment') {
            throw new ApiError('order_not_payable', 'Pesanan ini sudah dibayar atau dibatalkan.');
        }

        return response()->json(['data' => ['payment_url' => CreateOrder::payUrl($order, $this->user(), $request->getSchemeAndHttpHost())]]);
    }

    /** POST /api/orders/{id}/check — tanyakan status pembayaran ke gateway (setelah kembali dari halaman bayar). */
    public function check(Order $order, ApplyPayment $apply): JsonResponse
    {
        $this->own($order);
        $inv = Invoice::withoutGlobalScopes()->where('order_id', $order->id)->latest('id')->first();
        if ($order->status === 'pending_payment' && $inv?->isPayable() && ($gw = PaymentService::gatewayFor($inv->payment_provider)) && ($n = $gw->fetchStatus($inv))) {
            $apply->handle($n);
        }

        return response()->json(['data' => self::detail($order->fresh())]);
    }

    public function cancel(Order $order): JsonResponse
    {
        $this->own($order);
        if ($order->status !== 'pending_payment') {
            throw new ApiError('order_not_cancellable', 'Pesanan yang sudah dibayar tidak bisa dibatalkan sendiri. Hubungi kami untuk bantuan.');
        }
        Invoice::withoutGlobalScopes()->where('order_id', $order->id)->whereIn('status', ['issued', 'overdue'])->update(['status' => 'void']);
        $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'Dibatalkan pemesan']);
        Audit::tenant($order->tenant_id, 'order.cancelled', $order, null, ['by' => 'customer']);

        return response()->json(['data' => self::detail($order->fresh())]);
    }

    private function own(Order $order): void
    {
        $this->access()->authorize('orders.create');
        abort_unless($order->user_id === $this->user()->id || $this->access()->has('billing.manage'), 404);
    }

    public static function summary(Order $o): array
    {
        return [
            'id' => $o->public_id, 'number' => $o->number, 'status' => $o->status, 'status_label' => $o->label(),
            'total' => $o->total, 'items_count' => $o->items_count ?? $o->items()->count(),
            'tracking_number' => $o->tracking_number, 'created_at' => $o->created_at?->toIso8601String(),
        ];
    }

    public static function detail(Order $o): array
    {
        $o->loadMissing(['items.cardProduct', 'items.lanyardWidth', 'items.lanyardHardware', 'shippingMethod']);
        $inv = Invoice::withoutGlobalScopes()->where('order_id', $o->id)->latest('id')->first();

        return self::summary($o) + [
            'subtotal' => $o->subtotal, 'shipping_fee' => $o->shipping_fee,
            'shipping' => [
                'method' => $o->shippingMethod?->name, 'eta' => $o->shippingMethod?->eta, 'needs_address' => (bool) $o->shippingMethod?->needs_address,
                'recipient_name' => $o->recipient_name, 'recipient_phone' => $o->recipient_phone, 'address_line' => $o->address_line,
                'city' => $o->city, 'province' => $o->province, 'postal_code' => $o->postal_code, 'notes' => $o->notes,
                'courier' => $o->courier, 'tracking_number' => $o->tracking_number,
            ],
            'items' => $o->items->map(fn (OrderItem $i) => [
                'id' => $i->public_id, 'kind' => $i->kind, 'label' => $i->label, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'total' => $i->total,
                'design' => $i->design_snapshot,
            ])->values(),
            'timeline' => collect([
                ['created', 'Pesanan dibuat', $o->created_at], ['paid', 'Pembayaran diterima', $o->paid_at],
                ['in_production', 'Produksi dimulai', $o->production_started_at], ['shipped', 'Dikirim', $o->shipped_at],
                ['delivered', 'Diterima', $o->delivered_at], ['cancelled', 'Dibatalkan', $o->cancelled_at],
            ])->map(fn ($t) => ['key' => $t[0], 'label' => $t[1], 'at' => $t[2]?->toIso8601String()])->values(),
            'cancel_reason' => $o->cancel_reason,
            'invoice' => $inv ? ['id' => $inv->public_id, 'number' => $inv->number, 'status' => $inv->status, 'due_at' => $inv->due_at?->toIso8601String()] : null,
        ];
    }
}
