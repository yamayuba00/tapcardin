<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Billing\ApplyPayment;
use App\Actions\Billing\CreateCheckout;
use App\Actions\Billing\CreateTapTopup;
use App\Actions\Cards\CreateDigitalCard;
use App\Authz\PlanFeatures;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Payment\Gateways\SimulatorGateway;
use App\Services\Payment\PaymentService;
use App\Support\Audit;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** OR-05 — Paket & tagihan: paket aktif + pemakaian, perbandingan paket, checkout, invoice, batal/lanjutkan langganan. */
class BillingController extends WorkspaceController
{
    /** Daftar fitur yang dibandingkan di halaman paket: [label, kunci limit | fitur, satuan]. */
    private const COMPARE = [
        ['Kartu aktif', 'limit:cards_active', 'kartu'],
        ['Koneksi tersimpan', 'limit:connections', 'koneksi'],
        ['Riwayat analitik', 'limit:analytics_days', 'hari'],
        ['QR aman (dinamis) sekali pakai', 'feature:dynamic_qr', null],
        ['Pengingat follow-up', 'feature:reminders', null],
        ['Mode Event & pipeline lead', 'feature:event_mode', null],
    ];

    public function show(): JsonResponse
    {
        $this->access()->authorize('billing.view');
        $tenant = $this->tenant()->load('plan');
        $sub = Subscription::where('tenant_id', $tenant->id)->with('plan')->first();
        $limits = PlanFeatures::effective($tenant)['limits'];
        $plans = Plan::where('tenant_type', $tenant->type->value)->where('is_public', true)->orderBy('sort')->get();
        $open = Invoice::where('tenant_id', $tenant->id)->whereIn('status', ['issued', 'overdue'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->latest('id')->with('plan')->first();

        return response()->json(['data' => [
            'plan' => self::presentPlan($tenant->plan),
            'subscription' => $sub ? [
                'id' => $sub->public_id, 'plan' => $sub->plan->name, 'cycle' => $sub->cycle, 'status' => $sub->status,
                'current_period_end' => $sub->current_period_end->toIso8601String(), 'grace_until' => $sub->grace_until?->toIso8601String(),
                'cancel_at_period_end' => $sub->cancel_at_period_end,
            ] : null,
            'usage' => [
                ['label' => 'Kartu aktif', 'used' => Card::where('form', Card::PHYSICAL)->whereIn('status', ['active', 'paused'])->count(), 'limit' => $limits['cards_active'] ?? null],
                ['label' => 'Koneksi', 'used' => Connection::whereNull('merged_into_id')->count(), 'limit' => $limits['connections'] ?? null],
            ],
            'plans' => $plans->map(fn (Plan $p) => self::presentPlan($p) + ['compare' => self::compare($p)])->values(),
            'open_invoice' => $open ? self::presentInvoice($open) : null,
            'gateway' => PaymentService::info(),
        ]]);
    }

    /** POST /api/billing/checkout {plan, cycle} → invoice + URL halaman bayar. */
    public function checkout(Request $request, CreateCheckout $create): JsonResponse
    {
        $this->access()->authorize('billing.manage');
        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'key')->where('is_public', true)],
            'cycle' => ['required', Rule::in(array_keys(PaymentService::CYCLES))],
            'voucher' => ['nullable', 'string', 'max:32'],
        ]);
        $plan = Plan::where('key', $data['plan'])->firstOrFail();
        $r = $create->handle($this->tenant(), $this->user(), $plan, $data['cycle'], $request->getSchemeAndHttpHost(), $data['voucher'] ?? null);

        return response()->json(['data' => ['invoice' => self::presentInvoice($r['invoice']), 'payment_url' => $r['payment_url']]], 201);
    }

    /**
     * POST /api/billing/tap-topup — tambahan kuota tap kartu digital milik pengguna ini di workspace ini. Kartunya milik
     * pribadi, jadi pemegang boleh membeli tanpa izin kelola tagihan workspace.
     */
    public function topup(Request $request, CreateTapTopup $create): JsonResponse
    {
        $card = CreateDigitalCard::of($this->user(), $this->tenant()) ?? throw new ApiError('topup_unavailable', 'Anda belum punya kartu digital di workspace ini.');
        $r = $create->handle($card, $this->user(), $request->getSchemeAndHttpHost());

        return response()->json(['data' => ['invoice' => self::presentInvoice($r['invoice']), 'payment_url' => $r['payment_url']]], 201);
    }

    /** POST /api/billing/voucher {code, plan, cycle} -> pratinjau potongan sebelum checkout. */
    public function voucher(Request $request): JsonResponse
    {
        $this->access()->authorize('billing.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'plan' => ['required', Rule::exists('plans', 'key')->where('is_public', true)],
            'cycle' => ['required', Rule::in(array_keys(PaymentService::CYCLES))],
        ]);
        $plan = Plan::where('key', $data['plan'])->firstOrFail();
        $v = CreateCheckout::voucher($data['code'], $this->tenant(), $plan);
        $price = PaymentService::price($plan, $data['cycle']);
        $discount = $v->discountFor($price);

        return response()->json(['data' => ['code' => $v->code, 'description' => $v->description, 'price' => $price, 'discount' => $discount, 'total' => $price - $discount]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->access()->authorize('billing.view');
        $q = Invoice::with('plan')->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));

        return Table::json(Table::paginate($q, $request, ['number' => 'number', 'total' => 'total', 'created_at' => 'created_at', 'paid_at' => 'paid_at'], '-created_at', ['number'], 10),
            fn (Invoice $i) => self::presentInvoice($i), $request);
    }

    public function invoice(Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($invoice, 'billing.view');

        return response()->json(['data' => self::presentInvoice($invoice->load('plan')) + [
            'tenant' => $this->tenant()->name,
            'bill_to' => $invoice->billing_snapshot,
            'simulator' => $invoice->payment_provider === 'simulator',
            // halaman tujuan setelah bayar (pesanan kartu → halaman pesanan, tambahan tap → pengaturan)
            'return_to' => match (true) {
                $invoice->type === 'topup' => '/settings',
                $invoice->order_id && ($o = Order::withoutGlobalScopes()->find($invoice->order_id)) !== null => "/orders/{$o->public_id}",
                default => "/billing/invoices/{$invoice->public_id}",
            },
        ]]);
    }

    /** POST /api/billing/invoices/{id}/pay — buka (lagi) halaman bayar invoice yang masih terbuka. */
    public function pay(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($invoice, 'billing.manage');
        if (! $invoice->isPayable()) {
            throw new ApiError('invoice_not_payable', $invoice->status === 'paid' ? 'Invoice ini sudah lunas.' : 'Invoice ini sudah tidak berlaku. Buat pembayaran baru dari halaman Paket.');
        }
        $back = $invoice->order_id && ($order = Order::withoutGlobalScopes()->find($invoice->order_id))
            ? "/orders/{$order->public_id}" : "/billing/invoices/{$invoice->public_id}";

        return response()->json(['data' => ['payment_url' => PaymentService::startPayment($invoice, $this->user(), $request->getSchemeAndHttpHost().$back)]]);
    }

    /**
     * POST /api/billing/invoices/{id}/check — tanya status pembayaran langsung ke gateway (dipakai saat pengguna kembali
     * dari halaman bayar, sebelum/tanpa menunggu webhook). Hasilnya lewat jalur yang sama: ApplyPayment.
     */
    public function check(Invoice $invoice, ApplyPayment $apply): JsonResponse
    {
        $this->authorizeInvoice($invoice, 'billing.view');
        if ($invoice->isPayable() && ($gw = PaymentService::gatewayFor($invoice->payment_provider)) && ($n = $gw->fetchStatus($invoice))) {
            $apply->handle($n);
        }

        return response()->json(['data' => self::presentInvoice($invoice->fresh('plan'))]);
    }

    /** POST /api/billing/invoices/{id}/simulate {result, method} — hanya gateway simulator (lokal/test). */
    public function simulate(Request $request, Invoice $invoice, ApplyPayment $apply): JsonResponse
    {
        $this->authorizeInvoice($invoice, 'billing.manage');
        abort_unless($invoice->payment_provider === 'simulator' && ! app()->isProduction(), 404);
        $data = $request->validate(['result' => ['required', Rule::in(['paid', 'failed'])], 'method' => ['required', 'string', 'max:40']]);
        $outcome = $apply->handle(SimulatorGateway::notification($invoice, $data['result'], $data['method']));

        return response()->json(['data' => ['outcome' => $outcome] + self::presentInvoice($invoice->fresh('plan'))]);
    }

    /** POST /api/billing/cancel — berhenti di akhir periode (fitur berbayar tetap jalan sampai tanggal itu). */
    public function cancel(Request $request): JsonResponse
    {
        $this->access()->authorize('billing.manage');
        $resume = $request->boolean('resume');
        $sub = Subscription::where('tenant_id', $this->tenant()->id)->whereIn('status', ['active', 'trialing'])->firstOrFail();
        $sub->update(['cancel_at_period_end' => ! $resume]);
        Audit::tenant($this->tenant()->id, $resume ? 'billing.resumed' : 'billing.cancelled', $sub);

        return $this->show();
    }

    public static function presentPlan(?Plan $p): ?array
    {
        return $p ? [
            'key' => $p->key, 'name' => $p->name, 'price_monthly' => (int) $p->price_monthly, 'price_yearly' => (int) $p->price_yearly,
            'yearly_saving' => PaymentService::yearlySaving($p), 'free' => (int) $p->price_monthly === 0,
        ] : null;
    }

    private static function compare(Plan $p): array
    {
        return array_map(function ($row) use ($p) {
            [$label, $key, $unit] = $row;
            [$kind, $k] = explode(':', $key);
            $v = $kind === 'limit' ? ($p->limits[$k] ?? null) : (bool) ($p->features[$k] ?? false);

            return ['label' => $label, 'value' => $kind === 'limit' ? ($v === null ? 'Tanpa batas' : number_format($v, 0, ',', '.').' '.$unit) : $v];
        }, self::COMPARE);
    }

    /** Pembeli tambahan tap boleh melihat & membayar invoicenya sendiri walau tanpa izin tagihan workspace. */
    private function authorizeInvoice(Invoice $invoice, string $permission): void
    {
        if ($invoice->type !== 'topup' || $invoice->user_id !== $this->user()->id) {
            $this->access()->authorize($permission);
        }
    }

    public static function presentInvoice(Invoice $i): array
    {
        return [
            'id' => $i->public_id, 'number' => $i->number, 'status' => $i->status, 'type' => $i->type,
            'plan' => $i->plan?->name, 'cycle' => $i->cycle, 'lines' => $i->lines,
            'subtotal' => (int) $i->subtotal, 'discount' => (int) $i->discount, 'tax' => (int) $i->tax, 'total' => (int) $i->total,
            'issued_at' => $i->issued_at?->toIso8601String(), 'due_at' => $i->due_at?->toIso8601String(), 'paid_at' => $i->paid_at?->toIso8601String(),
            'payment_method' => $i->payment_method, 'payment_url' => $i->isPayable() ? $i->payment_url : null,
        ];
    }
}
