<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Production\CreateBatch;
use App\Actions\Production\ShipBatch;
use App\Enums\BatchStatus;
use App\Exceptions\ApiError;
use App\Http\Controllers\Workspace\OrderController as WorkspaceOrders;
use App\Models\Batch;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Audit;
use App\Support\Http\Table;
use App\Support\Notify;
use App\Support\Qr\QrEncoder;
use App\Support\Qr\SvgRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel → Pesanan (platform.orders.*): pesanan kartu & lanyard dari pelanggan.
 *   dibayar → "Mulai produksi" (batch per item kartu: masuk workspace pemesan, bisa diaktifkan pemesan tanpa kode)
 *   → token, encode, QC di menu Produksi → "Kirim" (resi; kartu batch yang lolos QC ikut dikirim) → "Diterima".
 */
class OrderController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('orders.view');
        $q = Order::acrossTenants()->with(['tenant', 'user'])->withCount('items')
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))
            ->when(trim((string) $request->query('q')), fn ($w, $s) => $w->where(fn ($x) => $x->where('number', 'ilike', "%$s%")
                ->orWhere('recipient_name', 'ilike', "%$s%")->orWhereHas('tenant', fn ($t) => $t->where('name', 'ilike', "%$s%"))));

        return Table::json(Table::paginate($q, $request, ['created_at' => 'created_at', 'total' => 'total', 'number' => 'number', 'paid_at' => 'paid_at'], '-created_at', [], 25),
            fn (Order $o) => WorkspaceOrders::summary($o) + [
                'tenant' => $o->tenant ? ['id' => $o->tenant->public_id, 'name' => $o->tenant->name] : null,
                'customer' => $o->user?->email, 'paid_at' => self::dt($o->paid_at),
            ], $request);
    }

    public function show(string $order): JsonResponse
    {
        $this->can('orders.view');
        $o = $this->find($order);
        $qr = QrEncoder::encode(config('tapcard.resolver_url').'/c/XXXXXXXXXX?src=qr');
        $batches = Batch::whereIn('order_item_id', $o->items->pluck('id'))->get();

        return $this->ok(WorkspaceOrders::detail($o) + [
            'tenant' => ['id' => $o->tenant->public_id, 'name' => $o->tenant->name],
            'customer' => $o->user ? ['id' => $o->user->public_id, 'name' => $o->user->name, 'email' => $o->user->email] : null,
            'batches' => $batches->map(fn (Batch $b) => [
                'id' => $b->public_id, 'code' => $b->code, 'quantity' => $b->quantity, 'status' => $b->status->value, 'status_label' => $b->status->label(),
                'item' => OrderItem::find($b->order_item_id)?->public_id,
            ])->values(),
            'qr' => ['modules' => $qr->size, 'path' => SvgRenderer::path($qr)],
        ]);
    }

    /** Dibayar → buat batch produksi untuk setiap item kartu (lanyard diproduksi vendor, dicatat di pesanan). */
    public function startProduction(string $order, CreateBatch $create): JsonResponse
    {
        $this->can('orders.manage');
        $o = $this->find($order);
        if ($o->status !== 'paid') {
            throw new ApiError('order_not_paid', 'Produksi hanya bisa dimulai untuk pesanan yang sudah dibayar.');
        }
        DB::transaction(function () use ($o, $create) {
            foreach ($o->items->where('kind', 'card') as $item) {
                if (! Batch::where('order_item_id', $item->id)->exists()) {
                    $create->handle($item->cardProduct->chip_type, $item->quantity, $o->user, "Pesanan {$o->number}", null, $item);
                }
            }
            $o->update(['status' => 'in_production', 'production_started_at' => now()]);
        });
        $this->notifyCustomer($o);
        Audit::platform('order.production_started', $o, null, ['number' => $o->number]);

        return $this->show($order);
    }

    /** Kirim: kartu yang lolos QC di batch pesanan ikut ditandai terkirim (siap diaktifkan). */
    public function ship(Request $request, string $order, ShipBatch $shipBatch): JsonResponse
    {
        $this->can('orders.manage');
        $data = $request->validate(['courier' => ['nullable', 'string', 'max:40'], 'tracking_number' => ['nullable', 'string', 'max:60']]);
        $o = $this->find($order);
        if ($o->status !== 'in_production') {
            throw new ApiError('order_not_in_production', 'Pesanan harus dalam produksi sebelum dikirim.');
        }
        if ($o->shippingMethod?->needs_address && blank($data['tracking_number'] ?? null)) {
            throw new ApiError('tracking_required', 'Isi nomor resi pengiriman.', 422, ['field' => 'tracking_number']);
        }
        $batches = Batch::whereIn('order_item_id', $o->items->pluck('id'))->get();
        foreach ($batches as $b) {
            if ($b->status !== BatchStatus::Completed) {
                $shipBatch->handle($b, $data['tracking_number'] ?? null); // gagal bila belum ada kartu yang lolos QC
            }
            if ($b->fresh()->status !== BatchStatus::Completed) {
                throw new ApiError('batch_not_ready', "Batch {$b->code} masih punya kartu yang belum lolos QC. Selesaikan QC dulu.");
            }
        }
        $o->update(['status' => 'shipped', 'shipped_at' => now(), 'courier' => $data['courier'] ?? null, 'tracking_number' => $data['tracking_number'] ?? null]);
        $this->notifyCustomer($o);
        Audit::platform('order.shipped', $o, null, ['courier' => $o->courier, 'tracking' => $o->tracking_number]);

        return $this->show($order);
    }

    public function deliver(string $order): JsonResponse
    {
        $this->can('orders.manage');
        $o = $this->find($order);
        if ($o->status !== 'shipped') {
            throw new ApiError('order_not_shipped', 'Hanya pesanan yang sudah dikirim yang bisa ditandai diterima.');
        }
        $o->update(['status' => 'delivered', 'delivered_at' => now()]);
        $this->notifyCustomer($o);
        Audit::platform('order.delivered', $o);

        return $this->show($order);
    }

    /** Batal sebelum produksi. Pesanan yang sudah dibayar: dana dikembalikan manual oleh tim keuangan (dicatat di alasan). */
    public function cancel(Request $request, string $order): JsonResponse
    {
        $this->can('orders.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], ['reason.required' => 'Alasan wajib diisi.']);
        $o = $this->find($order);
        if (! in_array($o->status, ['pending_payment', 'paid'], true)) {
            throw new ApiError('order_not_cancellable', 'Pesanan yang sudah masuk produksi tidak bisa dibatalkan.');
        }
        Invoice::withoutGlobalScopes()->where('order_id', $o->id)->whereIn('status', ['issued', 'overdue'])->update(['status' => 'void']);
        $o->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $data['reason']]);
        $this->notifyCustomer($o);
        Audit::platform('order.cancelled', $o, null, ['reason' => $data['reason'], 'was_paid' => $o->paid_at !== null]);

        return $this->show($order);
    }

    private function find(string $id): Order
    {
        return Order::acrossTenants()->with(['tenant', 'user', 'items.cardProduct', 'shippingMethod'])->where('public_id', $id)->firstOrFail();
    }

    private function notifyCustomer(Order $o): void
    {
        if ($o->user_id) {
            Notify::user($o->user_id, $o->tenant_id, 'order_status', ['id' => $o->public_id, 'number' => $o->number, 'status' => $o->status, 'label' => $o->label(), 'tracking' => $o->tracking_number]);
        }
    }
}
