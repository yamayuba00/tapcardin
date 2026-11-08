<?php

namespace App\Actions\Orders;

use App\Actions\Cards\CreateDigitalCard;
use App\Exceptions\ApiError;
use App\Models\CardProduct;
use App\Models\CartItem;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\LanyardHardware;
use App\Models\LanyardWidth;
use App\Models\Order;
use App\Models\Profile;
use App\Models\ShippingMethod;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Payment\PaymentService;
use App\Support\Audit;
use App\Support\Design\CardVisual;
use App\Support\Design\Variables;
use App\Support\Indonesia;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pesan kartu fisik, ID badge, & lanyard dari desain sendiri → pesanan + invoice (type `order`) → bayar QRIS (payment service).
 * Harga selalu dari katalog server (card_products, lanyard_widths/hardware, shipping_methods) & kode promo (vouchers
 * applies_to order|any), bukan dari klien. `quote()` dipakai juga untuk ringkasan harga di keranjang.
 * Desain wajib "siap cetak" dan dibekukan (snapshot) agar yang diproduksi sama dengan yang dibayar.
 */
final class CreateOrder
{
    /**
     * @param  list<array{kind:string, design:string, product?:string, quantity:int}>  $items
     * @param  array{method:string, recipient_name?:?string, recipient_phone?:?string, address_line?:?string, district?:?string, city?:?string, province?:?string, postal_code?:?string, notes?:?string}  $ship
     * @param  list<string>  $cartIds  item keranjang yang ikut dipesan → dihapus dari keranjang setelah pesanan dibuat
     * @return array{order: Order, payment_url: string}
     */
    public function handle(Tenant $tenant, User $user, array $items, array $ship, string $origin, ?string $promo = null, array $cartIds = []): array
    {
        $q = $this->quote($tenant, $user, $items, $ship['method'], $promo);
        if ($q['method']->needs_address) {
            self::validateAddress($ship);
        }
        $method = $q['method'];

        $order = DB::transaction(function () use ($tenant, $user, $q, $method, $ship, $cartIds) {
            $order = Order::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 'TMP-'.Str::random(16), 'status' => 'pending_payment',
                'subtotal' => $q['subtotal'], 'shipping_fee' => $q['shipping'], 'discount' => $q['discount'], 'voucher_id' => $q['voucher']?->id,
                'total' => $q['total'], 'shipping_method_id' => $method->id,
                ...array_map(fn ($v) => $v === null ? null : trim((string) $v), array_intersect_key($ship, array_flip(['recipient_name', 'recipient_phone', 'address_line', 'district', 'city', 'province', 'postal_code', 'notes']))),
            ]);
            $order->update(['number' => sprintf('ORD/%s/%06d', now('Asia/Jakarta')->format('Y/m'), $order->id)]);
            foreach ($q['lines'] as $l) {
                $order->items()->create($l);
            }
            if ($cartIds) {
                CartItem::where('user_id', $user->id)->whereIn('public_id', $cartIds)->delete();
            }
            self::issueInvoice($order->fresh('items'), $user);
            Audit::tenant($tenant->id, 'order.created', $order, null, ['number' => $order->number, 'total' => $order->total, 'items' => count($q['lines']), 'promo' => $q['voucher']?->code]);

            return $order;
        });

        return ['order' => $order->fresh(['items', 'shippingMethod']), 'payment_url' => self::payUrl($order, $user, $origin)];
    }

    /**
     * Rincian harga final (server): baris pesanan, subtotal, ongkir, potongan promo, total.
     *
     * @return array{lines: list<array>, subtotal: int, shipping: int, discount: int, total: int, voucher: ?Voucher, method: ShippingMethod}
     */
    public function quote(Tenant $tenant, User $user, array $items, string $methodKey, ?string $promo = null): array
    {
        $method = ShippingMethod::where('key', $methodKey)->where('is_active', true)->first()
            ?? throw new ApiError('shipping_unavailable', 'Metode pengiriman tidak tersedia.');
        $vars = $this->printVars($tenant, $user);
        $lines = array_map(fn (array $i) => $i['kind'] === 'card' ? $this->cardLine($user, $i, $vars) : $this->lanyardLine($user, $i, $vars), $items);
        $subtotal = array_sum(array_column($lines, 'total'));
        $voucher = filled($promo) ? self::promo((string) $promo, $tenant) : null;
        $discount = $voucher?->discountFor($subtotal) ?? 0;

        return ['lines' => $lines, 'subtotal' => $subtotal, 'shipping' => (int) $method->price, 'discount' => $discount,
            'total' => $subtotal + (int) $method->price - $discount, 'voucher' => $voucher, 'method' => $method];
    }

    /** Kode promo yang sah untuk pesanan cetak workspace ini; selain itu galat pada kolom `promo`. */
    public static function promo(string $code, Tenant $tenant): Voucher
    {
        $v = Voucher::where('code', strtoupper(trim($code)))->first();
        $error = match (true) {
            ! $v || ! $v->is_active || $v->applies_to === 'plan' => 'Kode promo tidak ditemukan.',
            ($v->starts_at && $v->starts_at->isFuture()) || ($v->ends_at && $v->ends_at->isPast()) => 'Promo sudah tidak berlaku.',
            $v->max_redemptions && $v->redeemed_count >= $v->max_redemptions => 'Kuota promo sudah habis.',
            VoucherRedemption::where('voucher_id', $v->id)->where('tenant_id', $tenant->id)->exists() => 'Kode promo ini sudah pernah Anda pakai.',
            default => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['promo' => $error]);
        }

        return $v;
    }

    /** Alamat kirim lengkap & wajar; galat per kolom (`shipping.*`) agar formulir menandai kolom yang kurang. */
    public static function validateAddress(array $ship): void
    {
        $e = [];
        $need = ['recipient_name' => 'Isi nama penerima.', 'recipient_phone' => 'Isi nomor HP penerima.', 'province' => 'Pilih provinsi.',
            'city' => 'Isi kota/kabupaten.', 'district' => 'Isi kecamatan.', 'address_line' => 'Isi alamat lengkap.', 'postal_code' => 'Isi kode pos.'];
        foreach ($need as $k => $msg) {
            if (blank($ship[$k] ?? null)) {
                $e["shipping.$k"] = $msg;
            }
        }
        if (! isset($e['shipping.recipient_phone']) && ! Phone::toE164((string) $ship['recipient_phone'])) {
            $e['shipping.recipient_phone'] = 'Nomor HP belum valid, mis. 0812 3456 7890.';
        }
        if (! isset($e['shipping.province']) && ! in_array($ship['province'], Indonesia::PROVINCES, true)) {
            $e['shipping.province'] = 'Pilih provinsi dari daftar.';
        }
        if (! isset($e['shipping.address_line']) && mb_strlen(trim((string) $ship['address_line'])) < 10) {
            $e['shipping.address_line'] = 'Tulis alamat lebih lengkap: jalan, nomor, RT/RW, patokan.';
        }
        if (! isset($e['shipping.postal_code']) && ! preg_match('/^[0-9]{5}$/', (string) $ship['postal_code'])) {
            $e['shipping.postal_code'] = 'Kode pos terdiri dari 5 angka.';
        }
        if ($e) {
            throw ValidationException::withMessages($e);
        }
    }

    /** Invoice baru untuk pesanan (pertama kali, atau bila invoice lama kedaluwarsa). */
    public static function issueInvoice(Order $order, User $user): Invoice
    {
        $lines = $order->items->map(fn ($i) => ['code' => $i->kind, 'label' => $i->label, 'qty' => $i->quantity, 'amount' => $i->total, 'note' => 'Rp '.number_format($i->unit_price, 0, ',', '.').' / pcs'])->all();
        if ($order->shipping_fee > 0 || $order->shippingMethod) {
            $lines[] = ['code' => 'shipping', 'label' => $order->shippingMethod?->name ?? 'Pengiriman', 'qty' => 1, 'amount' => $order->shipping_fee];
        }
        if ($order->discount > 0) {
            $code = $order->voucher_id ? Voucher::whereKey($order->voucher_id)->value('code') : null;
            $lines[] = ['code' => 'discount', 'label' => 'Promo'.($code ? " $code" : ''), 'qty' => 1, 'amount' => -$order->discount];
        }
        $inv = Invoice::create([
            'tenant_id' => $order->tenant_id, 'user_id' => $user->id, 'number' => 'TMP-'.Str::random(16), 'type' => 'order', 'status' => 'issued',
            'order_id' => $order->id, 'lines' => $lines, 'subtotal' => $order->total + $order->discount, 'discount' => $order->discount,
            'voucher_id' => $order->voucher_id, 'tax' => 0, 'total' => $order->total,
            'billing_snapshot' => ['name' => $user->name, 'email' => $user->email, 'order' => $order->number,
                'ship_to' => array_filter([$order->recipient_name, $order->address_line, $order->district, $order->city, $order->province, $order->postal_code])],
            'issued_at' => now(), 'due_at' => $expires = now()->addMinutes((int) config('payment.invoice_ttl_minutes')), 'expires_at' => $expires,
        ]);
        $inv->update(['number' => sprintf('INV/%s/%06d', now('Asia/Jakarta')->format('Y/m'), $inv->id)]);

        return $inv;
    }

    /** URL halaman bayar invoice pesanan yang masih berlaku (invoice baru bila yang lama kedaluwarsa). */
    public static function payUrl(Order $order, User $user, string $origin): string
    {
        $inv = Invoice::withoutGlobalScopes()->where('order_id', $order->id)->latest('id')->first();
        if (! $inv || ! $inv->isPayable() || $inv->due_at?->isPast()) {
            if ($inv?->isPayable()) {
                $inv->update(['status' => 'void']);
            }
            $inv = self::issueInvoice($order->loadMissing(['items', 'shippingMethod']), $user);
        }

        return PaymentService::startPayment($inv, $user, "$origin/orders/{$order->public_id}");
    }

    private function design(User $user, string $id, string $kind): Design
    {
        $d = Design::where('public_id', $id)->where('user_id', $user->id)->where('kind', $kind)->first()
            ?? throw new ApiError('design_not_found', 'Desain tidak ditemukan.', 404);
        if (! $d->validated_at) {
            throw new ApiError('design_not_ready', "Desain “{$d->name}” belum lolos cek cetak. Buka editor → tab Cek cetak, perbaiki, lalu simpan.", 422, ['design' => $d->public_id]);
        }

        return $d;
    }

    private function cardLine(User $user, array $i, array $vars): array
    {
        $d = $this->design($user, $i['design'], 'card');
        $p = CardProduct::where('key', $i['product'] ?? '')->where('is_active', true)->first()
            ?? throw new ApiError('product_unavailable', 'Jenis kartu tidak tersedia.');
        if ($p->form_factor === 'id_badge' && ($d->orientation !== 'portrait' || ! $d->lanyard)) {
            throw new ApiError('id_badge_design', "{$p->name} butuh desain kartu potret dengan lubang lanyard. Ubah “{$d->name}” di editor (potret + aktifkan lanyard) atau pilih kartu biasa.");
        }
        $qty = (int) $i['quantity'];
        if ($qty < $p->min_qty) {
            throw new ApiError('quantity_too_low', "Minimal pesan {$p->min_qty} untuk {$p->name}.");
        }
        $kit = $d->brandKit?->toKit();

        return [
            'kind' => 'card', 'design_id' => $d->id, 'card_product_id' => $p->id, 'quantity' => $qty, 'unit_price' => $p->price, 'total' => $p->price * $qty,
            'label' => "{$p->name} — {$d->name}",
            'design_snapshot' => ['name' => $d->name, 'orientation' => $d->orientation, 'lanyard' => (bool) $d->lanyard,
                'front' => $d->front_json, 'back' => $d->back_json, 'kit' => $kit, 'vars' => Variables::forKit($kit) + $vars],
        ];
    }

    private function lanyardLine(User $user, array $i, array $vars): array
    {
        $d = $this->design($user, $i['design'], 'lanyard');
        $w = $d->lanyard_width_id ? LanyardWidth::where('is_active', true)->find($d->lanyard_width_id) : null;
        $h = $d->lanyard_hardware_id ? LanyardHardware::where('is_active', true)->find($d->lanyard_hardware_id) : null;
        if (! $w || ! $h) {
            throw new ApiError('product_unavailable', "Lebar tali atau pengait desain “{$d->name}” sudah tidak tersedia. Pilih ulang di editor lanyard.");
        }
        $qty = (int) $i['quantity'];
        if ($qty < $w->min_qty) {
            throw new ApiError('quantity_too_low', "Minimal pesan {$w->min_qty} lanyard untuk tali {$w->label}.");
        }
        $sides = (int) $d->print_sides === 1 ? 1 : 2;
        $unit = ($sides === 2 ? $w->price_double : $w->price_single) + $h->price;
        $kit = $d->brandKit?->toKit();

        return [
            'kind' => 'lanyard', 'design_id' => $d->id, 'lanyard_width_id' => $w->id, 'lanyard_hardware_id' => $h->id, 'print_sides' => $sides,
            'quantity' => $qty, 'unit_price' => $unit, 'total' => $unit * $qty,
            'label' => "Lanyard {$w->label}, {$sides} sisi, {$h->name} — {$d->name}",
            'design_snapshot' => ['name' => $d->name, 'width_mm' => $w->width_mm, 'tile_mm' => $d->tile_mm, 'print_sides' => $sides,
                'front' => $d->front_json, 'back' => $d->back_json, 'kit' => $kit, 'vars' => Variables::forKit($kit) + $vars],
        ];
    }

    /** Data cetak dari bio pemesan (kartu digitalnya di workspace ini, atau profil pertamanya) — tanpa teks contoh. */
    private function printVars(Tenant $tenant, User $user): array
    {
        $card = CreateDigitalCard::of($user, $tenant);
        $profile = Profile::withoutGlobalScopes()->with('fields')->where('user_id', $user->id)
            ->when($card?->profile_id, fn ($q, $id) => $q->whereKey($id))->orderBy('id')->first();

        return CardVisual::profileVars($profile);
    }
}
