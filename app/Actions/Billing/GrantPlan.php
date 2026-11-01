<?php

namespace App\Actions\Billing;

use App\Authz\PlanFeatures;
use App\Exceptions\ApiError;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Beri / perpanjang paket tanpa pembayaran (kompensasi, mitra, uji coba). Tercatat sebagai invoice penyesuaian Rp0.
 * Hanya dijalankan setelah permintaan persetujuan disetujui (lihat Actions\Finance\Approvals).
 */
final class GrantPlan
{
    public function handle(Tenant $tenant, Plan $plan, int $months, string $reason): Subscription
    {
        if ($plan->tenant_type !== $tenant->type->value) {
            throw new ApiError('plan_unavailable', 'Paket ini untuk tipe workspace lain.');
        }

        return DB::transaction(function () use ($tenant, $plan, $months, $reason) {
            $sub = Subscription::acrossTenants()->where('tenant_id', $tenant->id)->lockForUpdate()->first();
            $extend = $sub && $sub->plan_id === $plan->id && $sub->current_period_end->isFuture() && $sub->status !== 'expired';
            $end = ($extend ? $sub->current_period_end : now())->copy()->addMonthsNoOverflow($months);
            $attrs = ['plan_id' => $plan->id, 'cycle' => $sub->cycle ?? 'monthly', 'status' => 'active', 'current_period_start' => $extend ? $sub->current_period_start : now(),
                'current_period_end' => $end, 'grace_until' => null, 'cancel_at_period_end' => false, 'cancelled_at' => null];
            $sub ? $sub->update($attrs) : $sub = Subscription::create(['tenant_id' => $tenant->id] + $attrs);
            $inv = Invoice::create([
                'tenant_id' => $tenant->id, 'number' => 'TMP-'.Str::random(16), 'type' => 'adjustment', 'status' => 'paid',
                'subscription_id' => $sub->id, 'plan_id' => $plan->id, 'cycle' => $sub->cycle,
                'lines' => [['code' => 'grant', 'label' => "Paket {$plan->name} — {$months} bulan (diberikan: {$reason})", 'qty' => 1, 'amount' => 0]],
                'subtotal' => 0, 'total' => 0, 'issued_at' => now(), 'paid_at' => now(), 'payment_method' => 'Diberikan staf',
            ]);
            $inv->update(['number' => sprintf('INV/%s/%06d', now('Asia/Jakarta')->format('Y/m'), $inv->id)]);
            $tenant->update(['plan_id' => $plan->id, 'status' => 'active']);
            PlanFeatures::forget($tenant);

            return $sub;
        });
    }
}
