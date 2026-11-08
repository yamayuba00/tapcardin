<?php

namespace App\Console\Commands;

use App\Authz\PlanFeatures;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Payment\PaymentService;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * BSD `tapcard:subscriptions` (harian): invoice perpanjangan H-7, jatuh tempo → past_due + tenggang 14 hari,
 * dibatalkan / lewat tenggang → kembali ke paket gratis, invoice checkout yang ditinggal (lewat 24 jam) → void.
 */
class Subscriptions extends Command
{
    protected $signature = 'tapcard:subscriptions';

    protected $description = 'Perpanjangan, tenggang, dan turun paket langganan';

    public const GRACE_DAYS = 14;

    public function handle(): int
    {
        $n = ['renewal' => 0, 'past_due' => 0, 'grace_ending' => 0, 'downgraded' => 0]; // invoice kedaluwarsa: tapcard:expire-invoices

        // invoice perpanjangan 7 hari sebelum periode berakhir
        Subscription::acrossTenants()->with('plan')->where('status', 'active')->where('cancel_at_period_end', false)
            ->whereBetween('current_period_end', [now(), now()->addDays(7)])->each(function (Subscription $s) use (&$n) {
                $exists = Invoice::acrossTenants()->where('subscription_id', $s->id)->whereIn('status', ['issued', 'overdue'])->exists();
                $amount = PaymentService::price($s->plan, $s->cycle);
                if ($exists || $amount <= 0) {
                    return;
                }
                $label = "Perpanjangan {$s->plan->name} — ".($s->cycle === 'yearly' ? '1 tahun' : '1 bulan');
                $inv = Invoice::create([
                    'tenant_id' => $s->tenant_id, 'number' => 'TMP-'.Str::random(16), 'type' => 'subscription', 'status' => 'issued',
                    'subscription_id' => $s->id, 'plan_id' => $s->plan_id, 'cycle' => $s->cycle,
                    'lines' => [['code' => "{$s->plan->key}-{$s->cycle}", 'label' => $label, 'qty' => 1, 'amount' => $amount]],
                    'subtotal' => $amount, 'total' => $amount, 'issued_at' => now(), 'due_at' => $s->current_period_end,
                ]);
                $inv->update(['number' => sprintf('INV/%s/%06d', now('Asia/Jakarta')->format('Y/m'), $inv->id)]);
                $owner = Tenant::find($s->tenant_id)?->owner_user_id;
                if ($owner) {
                    Notify::user($owner, $s->tenant_id, 'invoice_due', ['id' => $inv->public_id, 'number' => $inv->number, 'due' => $inv->due_at->toIso8601String(), 'total' => $amount]);
                }
                $n['renewal']++;
            });

        // periode berakhir
        Subscription::acrossTenants()->whereIn('status', ['active', 'trialing'])->where('current_period_end', '<', now())->each(function (Subscription $s) use (&$n) {
            if ($s->cancel_at_period_end) {
                $this->downgrade($s, 'cancelled');
                $n['downgraded']++;

                return;
            }
            $s->update(['status' => 'past_due', 'grace_until' => $s->current_period_end->copy()->addDays(self::GRACE_DAYS)]);
            Invoice::acrossTenants()->where('subscription_id', $s->id)->where('status', 'issued')->update(['status' => 'overdue']);
            Tenant::whereKey($s->tenant_id)->update(['status' => 'past_due']);
            $this->notifyOwner($s, 'subscription_past_due');
            $n['past_due']++;
        });

        // pengingat 3 hari sebelum masa tenggang habis (perintah ini jalan sekali sehari → terkirim sekali)
        Subscription::acrossTenants()->with('plan')->where('status', 'past_due')
            ->whereBetween('grace_until', [now()->addDays(2), now()->addDays(3)])->each(function (Subscription $s) use (&$n) {
                $this->notifyOwner($s, 'grace_ending');
                $n['grace_ending']++;
            });

        // lewat masa tenggang
        Subscription::acrossTenants()->where('status', 'past_due')->where('grace_until', '<', now())->each(function (Subscription $s) use (&$n) {
            $this->downgrade($s, 'expired');
            $n['downgraded']++;
        });

        $this->info(json_encode($n));

        return self::SUCCESS;
    }

    /** Masa tenggang dimulai / hampir habis → pemilik workspace (in-app + email), dengan tautan ke tagihan yang terbuka. */
    private function notifyOwner(Subscription $s, string $type): void
    {
        $owner = Tenant::find($s->tenant_id)?->owner_user_id;
        if (! $owner) {
            return;
        }
        $inv = Invoice::acrossTenants()->where('subscription_id', $s->id)->whereIn('status', ['issued', 'overdue'])->latest('id')->first();
        Notify::user($owner, $s->tenant_id, $type, array_filter([
            'plan' => $s->plan?->name, 'grace_until' => $s->grace_until?->toIso8601String(),
            'id' => $inv?->public_id, 'number' => $inv?->number,
        ]));
    }

    private function downgrade(Subscription $s, string $status): void
    {
        $tenant = Tenant::find($s->tenant_id);
        $free = Plan::where('tenant_type', $tenant->type->value)->where('price_monthly', 0)->where('is_public', true)->orderBy('sort')->first();
        $s->update(['status' => $status, 'cancelled_at' => now()]);
        Invoice::acrossTenants()->where('subscription_id', $s->id)->whereIn('status', ['issued', 'overdue'])->update(['status' => 'void']);
        $tenant->update(['plan_id' => $free?->id ?? $tenant->plan_id, 'status' => 'active']);
        PlanFeatures::forget($tenant);
        if ($tenant->owner_user_id) {
            Notify::user($tenant->owner_user_id, $tenant->id, 'plan_changed', ['plan' => $free?->name, 'reason' => $status]);
        }
    }
}
