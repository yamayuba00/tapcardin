<?php

namespace App\Actions\Orders;

use App\Actions\Cards\CreateDigitalCard;
use App\Exceptions\ApiError;
use App\Models\CardProduct;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\LanyardHardware;
use App\Models\LanyardWidth;
use App\Models\Order;
use App\Models\Profile;
use App\Models\ShippingMethod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Support\Audit;
use App\Support\Design\CardVisual;
use App\Support\Design\Variables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pesan kartu fisik / lanyard dari desain sendiri → pesanan + invoice (type `order`) → sesi bayar payment service.
 * Harga selalu dari katalog server (card_products, lanyard_widths/hardware, shipping_methods), bukan dari klien.
 * Desain wajib "siap cetak" dan dibekukan (snapshot) agar yang diproduksi sama dengan yang dibayar.
 */
final class CreateOrder
{
    /**
     * @param  list<array{kind:string, design:string, product?:string, quantity:int}>  $items
     * @param  array{method:string, recipient_name?:?string, recipient_phone?:?string, address_line?:?string, city?:?string, province?:?string, postal_code?:?string, notes?:?string}  $ship
     * @return array{order: Order, payment_url: string}
     */
    public function handle(Tenant $tenant, User $user, array $items, array $ship, string $origin): array
    {
        $method = ShippingMethod::where('key', $ship['method'])->where('is_active', true)->first()
            ?? throw new ApiError('shipping_unavailable', 'Metode pengiriman tidak tersedia.');
        if ($method->needs_address) {
            foreach (['recipient_name' => 'nama penerima', 'recipient_phone' => 'nomor HP penerima', 'address_line' => 'alamat', 'city' => 'kota', 'postal_code' => 'kode pos'] as $k => $label) {
                if (blank($ship[$k] ?? null)) {
                    throw new ApiError('address_incomplete', "Lengkapi $label untuk pengiriman.", 422, ['field' => $k]);
                }
            }
        }

        $vars = $this->printVars($tenant, $user);
        $lines = array_map(fn (array $i) => $i['kind'] === 'card' ? $this->cardLine($user, $i, $vars) : $this->lanyardLine($user, $i, $vars), $items);
        $subtotal = array_sum(array_column($lines, 'total'));

        $order = DB::transaction(function () use ($tenant, $user, $lines, $subtotal, $method, $ship) {
            $order = Order::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 'TMP-'.Str::random(16), 'status' => 'pending_payment',
                'subtotal' => $subtotal, 'shipping_fee' => $method->price, 'total' => $subtotal + $method->price,
                'shipping_method_id' => $method->id,
                ...array_map(fn ($v) => $v === null ? null : trim((string) $v), array_intersect_key($ship, array_flip(['recipient_name', 'recipient_phone', 'address_line', 'city', 'province', 'postal_code', 'notes']))),
            ]);
            $order->update(['number' => sprintf('ORD/%s/%06d', now('Asia/Jakarta')->format('Y/m'), $order->id)]);
            foreach ($lines as $l) {
                $order->items()->create($l);
            }
            self::issueInvoice($order->fresh('items'), $user);
            Audit::tenant($tenant->id, 'order.created', $order, null, ['number' => $order->number, 'total' => $order->total, 'items' => count($lines)]);

            return $order;
        });

        return ['order' => $order->fresh(['items', 'shippingMethod']), 'payment_url' => self::payUrl($order, $user, $origin)];
    }

    /** Invoice baru untuk pesanan (pertama kali, atau bila invoice lama kedaluwarsa). */
    public static function issueInvoice(Order $order, User $user): Invoice
    {
        $lines = $order->items->map(fn ($i) => ['code' => $i->kind, 'label' => $i->label, 'qty' => $i->quantity, 'amount' => $i->total, 'note' => 'Rp '.number_format($i->unit_price, 0, ',', '.').' / pcs'])->all();
        if ($order->shipping_fee > 0 || $order->shippingMethod) {
            $lines[] = ['code' => 'shipping', 'label' => $order->shippingMethod?->name ?? 'Pengiriman', 'qty' => 1, 'amount' => $order->shipping_fee];
        }
        $inv = Invoice::create([
            'tenant_id' => $order->tenant_id, 'user_id' => $user->id, 'number' => 'TMP-'.Str::random(16), 'type' => 'order', 'status' => 'issued',
            'order_id' => $order->id, 'lines' => $lines, 'subtotal' => $order->total, 'discount' => 0, 'tax' => 0, 'total' => $order->total,
            'billing_snapshot' => ['name' => $user->name, 'email' => $user->email, 'order' => $order->number,
                'ship_to' => array_filter([$order->recipient_name, $order->address_line, $order->city, $order->province, $order->postal_code])],
            'issued_at' => now(), 'due_at' => now()->addDay(),
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
        $qty = (int) $i['quantity'];
        if ($qty < $p->min_qty) {
            throw new ApiError('quantity_too_low', "Minimal pesan {$p->min_qty} kartu untuk {$p->name}.");
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
