<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Billing\ApplyPayment;
use App\Authz\PlanFeatures;
use App\Exceptions\ApiError;
use App\Http\Controllers\Workspace\BillingController;
use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Payment\PaymentNotification;
use App\Support\Audit;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Keuangan (platform.finance.*): ringkasan pendapatan, invoice lintas workspace, tandai lunas manual (transfer bank),
 * batalkan invoice, log event gateway, daftar langganan, dan beri/perpanjang paket manual (kompensasi, mitra).
 */
class FinanceController extends PlatformController
{
    public function summary(): JsonResponse
    {
        $this->can('finance.view');

        return $this->ok(self::metrics());
    }

    /** Dipakai juga oleh dashboard panel. */
    public static function metrics(): array
    {
        $tz = 'Asia/Jakarta';
        $active = Subscription::acrossTenants()->with('plan')->whereIn('status', ['active', 'past_due'])->get();
        $mrr = (int) round($active->sum(fn ($s) => $s->cycle === 'yearly' ? $s->plan->price_yearly / 12 : $s->plan->price_monthly));
        $paid = fn ($from, $to) => (int) Invoice::acrossTenants()->where('status', 'paid')->whereBetween('paid_at', [$from, $to])->sum('total');
        $series = Invoice::acrossTenants()->where('status', 'paid')->where('paid_at', '>=', now($tz)->subDays(29)->startOfDay())
            ->selectRaw('(paid_at at time zone ?)::date as d, sum(total) as v', [$tz])->groupBy('d')->pluck('v', 'd');

        return [
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'revenue_month' => $paid(now($tz)->startOfMonth(), now()),
            'revenue_prev_month' => $paid(now($tz)->subMonthNoOverflow()->startOfMonth(), now($tz)->subMonthNoOverflow()->endOfMonth()),
            'active_subscriptions' => $active->where('status', 'active')->count(),
            'past_due' => $active->where('status', 'past_due')->count(),
            'open_invoices' => Invoice::acrossTenants()->whereIn('status', ['issued', 'overdue'])->count(),
            'open_amount' => (int) Invoice::acrossTenants()->whereIn('status', ['issued', 'overdue'])->sum('total'),
            'series' => collect(range(29, 0))->map(fn ($i) => ['date' => $d = now($tz)->subDays($i)->toDateString(), 'value' => (int) ($series[$d] ?? 0)])->all(),
        ];
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->can('finance.view');
        $q = Invoice::acrossTenants()->with(['plan', 'tenant'])
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))
            ->when($request->query('tenant'), fn ($w, $t) => $w->whereHas('tenant', fn ($x) => $x->where('public_id', $t)))
            ->when(trim((string) $request->query('q')), fn ($w, $s) => $w->where(fn ($x) => $x->where('number', 'ilike', "%$s%")
                ->orWhereHas('tenant', fn ($t) => $t->where('name', 'ilike', "%$s%"))));
        $sorts = ['number' => 'number', 'total' => 'total', 'created_at' => 'created_at', 'paid_at' => 'paid_at', 'status' => 'status'];

        return Table::json(Table::paginate($q, $request, $sorts, '-created_at', [], 25), fn (Invoice $i) => self::invoiceRow($i), $request);
    }

    public function invoice(string $id): JsonResponse
    {
        $this->can('finance.view');
        $i = $this->find($id);

        return $this->ok(self::invoiceRow($i) + [
            'lines' => $i->lines, 'billing_snapshot' => $i->billing_snapshot, 'payment_ref' => $i->payment_ref, 'payment_url' => $i->payment_url,
            'events' => PaymentEvent::where('invoice_id', $i->id)->latest('id')->get()->map(fn ($e) => self::eventRow($e)),
        ]);
    }

    /** POST /api/finance/invoices/{id}/mark-paid {method, reference} — transfer manual; jalur sama dengan webhook. */
    public function markPaid(Request $request, string $id, ApplyPayment $apply): JsonResponse
    {
        $this->can('finance.invoices');
        $data = $request->validate(['method' => ['required', 'string', 'max:40'], 'reference' => ['nullable', 'string', 'max:80']]);
        $i = $this->find($id);
        if (! $i->isPayable()) {
            throw new ApiError('invoice_not_payable', 'Hanya invoice terbuka yang bisa ditandai lunas.');
        }
        $outcome = $apply->handle(new PaymentNotification('manual', 'manual-'.Str::ulid(), $i->number, PaymentNotification::PAID, (int) $i->total,
            mb_substr($data['method'], 0, 40), true, ['by' => auth()->id(), 'reference' => $data['reference'] ?? null]));
        Audit::platform('invoice.marked_paid', $i, null, ['method' => $data['method'], 'reference' => $data['reference'] ?? null, 'outcome' => $outcome]);

        return $this->invoice($id);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $this->can('finance.invoices');
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $i = $this->find($id);
        if (! $i->isPayable()) {
            throw new ApiError('invoice_not_payable', 'Hanya invoice terbuka yang bisa dibatalkan.');
        }
        $i->update(['status' => 'void']);
        Audit::platform('invoice.voided', $i, null, ['reason' => $data['reason']]);

        return $this->invoice($id);
    }

    public function events(Request $request): JsonResponse
    {
        $this->can('finance.view');
        $q = PaymentEvent::with('invoice')->when($request->query('provider'), fn ($w, $p) => $w->where('provider', $p))
            ->when($request->has('invalid'), fn ($w) => $w->where('signature_valid', false));

        return Table::json(Table::paginate($q, $request, ['created_at' => 'created_at', 'amount' => 'amount'], '-created_at', ['external_id'], 25),
            fn (PaymentEvent $e) => self::eventRow($e), $request);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $this->can('finance.view');
        $q = Subscription::acrossTenants()->with(['plan', 'tenant'])->when($request->query('status'), fn ($w, $s) => $w->where('status', $s));

        return Table::json(Table::paginate($q, $request, ['current_period_end' => 'current_period_end', 'created_at' => 'created_at', 'status' => 'status'], 'current_period_end', [], 25),
            fn (Subscription $s) => self::subscriptionRow($s), $request);
    }

    /**
     * POST /api/finance/grant {tenant, plan, months, reason} — beri / perpanjang paket tanpa pembayaran
     * (kompensasi, mitra, uji coba). Tercatat sebagai invoice penyesuaian Rp0 + audit.
     */
    public function grant(Request $request): JsonResponse
    {
        $this->can('finance.invoices');
        $data = $request->validate([
            'tenant' => ['required', 'string', 'size:26'],
            'plan' => ['required', Rule::exists('plans', 'key')],
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'reason' => ['required', 'string', 'max:200'],
        ]);
        $tenant = Tenant::where('public_id', $data['tenant'])->firstOrFail();
        $plan = Plan::where('key', $data['plan'])->firstOrFail();
        if ($plan->tenant_type !== $tenant->type->value) {
            throw new ApiError('plan_unavailable', 'Paket ini untuk tipe workspace lain.');
        }

        $sub = DB::transaction(function () use ($tenant, $plan, $data) {
            $sub = Subscription::acrossTenants()->where('tenant_id', $tenant->id)->lockForUpdate()->first();
            $extend = $sub && $sub->plan_id === $plan->id && $sub->current_period_end->isFuture() && $sub->status !== 'expired';
            $end = ($extend ? $sub->current_period_end : now())->copy()->addMonthsNoOverflow($data['months']);
            $attrs = ['plan_id' => $plan->id, 'cycle' => $sub->cycle ?? 'monthly', 'status' => 'active', 'current_period_start' => $extend ? $sub->current_period_start : now(),
                'current_period_end' => $end, 'grace_until' => null, 'cancel_at_period_end' => false, 'cancelled_at' => null];
            $sub ? $sub->update($attrs) : $sub = Subscription::create(['tenant_id' => $tenant->id] + $attrs);
            $inv = Invoice::create([
                'tenant_id' => $tenant->id, 'number' => 'TMP-'.Str::random(16), 'type' => 'adjustment', 'status' => 'paid',
                'subscription_id' => $sub->id, 'plan_id' => $plan->id, 'cycle' => $sub->cycle,
                'lines' => [['code' => 'grant', 'label' => "Paket {$plan->name} — {$data['months']} bulan (diberikan: {$data['reason']})", 'qty' => 1, 'amount' => 0]],
                'subtotal' => 0, 'total' => 0, 'issued_at' => now(), 'paid_at' => now(), 'payment_method' => 'Diberikan staf',
            ]);
            $inv->update(['number' => sprintf('INV/%s/%06d', now('Asia/Jakarta')->format('Y/m'), $inv->id)]);
            $tenant->update(['plan_id' => $plan->id, 'status' => 'active']);
            PlanFeatures::forget($tenant);

            return $sub;
        });
        Audit::platform('subscription.granted', $tenant, null, ['plan' => $plan->key, 'months' => $data['months'], 'reason' => $data['reason']]);

        return $this->ok(self::subscriptionRow($sub->load(['plan', 'tenant'])));
    }

    private function find(string $id): Invoice
    {
        return Invoice::acrossTenants()->with(['plan', 'tenant'])->where('public_id', $id)->firstOrFail();
    }

    public static function invoiceRow(Invoice $i): array
    {
        return BillingController::presentInvoice($i) + [
            'tenant' => $i->tenant ? ['id' => $i->tenant->public_id, 'name' => $i->tenant->name] : null,
            'provider' => $i->payment_provider, 'created_at' => self::dt($i->created_at),
        ];
    }

    public static function subscriptionRow(Subscription $s): array
    {
        return [
            'id' => $s->public_id, 'tenant' => ['id' => $s->tenant->public_id, 'name' => $s->tenant->name], 'plan' => $s->plan->name, 'plan_key' => $s->plan->key,
            'cycle' => $s->cycle, 'status' => $s->status, 'current_period_end' => self::dt($s->current_period_end),
            'grace_until' => self::dt($s->grace_until), 'cancel_at_period_end' => $s->cancel_at_period_end,
        ];
    }

    private static function eventRow(PaymentEvent $e): array
    {
        return [
            'id' => $e->id, 'provider' => $e->provider, 'external_id' => $e->external_id, 'type' => $e->type, 'amount' => $e->amount,
            'signature_valid' => $e->signature_valid, 'processed' => $e->processed_at !== null, 'created_at' => self::dt($e->created_at),
            'invoice' => $e->invoice ? ['id' => $e->invoice->public_id, 'number' => $e->invoice->number] : null,
        ];
    }
}
