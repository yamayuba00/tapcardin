<?php

namespace App\Console\Commands;

use App\Authz\PlanFeatures;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\RolePermission;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Services\Payment\PaymentService;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * BSD `tapcard:subscriptions` (harian): pengingat perusahaan H-30 / H-1 (uji coba: H-1), invoice perpanjangan H-7,
 * jatuh tempo → past_due + tenggang 14 hari,
 * dibatalkan / lewat tenggang → kembali ke paket gratis, invoice checkout yang ditinggal (lewat 24 jam) → void.
 * Perusahaan: tidak turun paket, langganan `expired` (uji coba habis langsung expired, tanpa tenggang).
 */
class Subscriptions extends Command
{
    protected $signature = 'tapcard:subscriptions';

    protected $description = 'Perpanjangan, tenggang, dan turun paket langganan';

    public const GRACE_DAYS = 14;

    public function handle(): int
    {
        $n = ['renewal' => 0, 'reminders' => 0, 'past_due' => 0, 'grace_ending' => 0, 'downgraded' => 0, 'expired' => 0]; // invoice kedaluwarsa: tapcard:expire-invoices

        // pengingat masa aktif perusahaan: H-30 & H-1 (H-7 = invoice perpanjangan di bawah); uji coba: H-1 saja
        Subscription::acrossTenants()->with('plan')->whereIn('status', ['active', 'trialing'])->where('cancel_at_period_end', false)
            ->whereBetween('current_period_end', [now(), now()->addDays(30)])->each(function (Subscription $s) use (&$n) {
                if ($s->plan?->tenant_type !== 'company') {
                    return;
                }
                $daysLeft = now()->diffInSeconds($s->current_period_end) / 86400;
                $stage = match (true) {
                    $s->status === 'trialing' => $daysLeft <= 1 ? 'trial' : null,
                    $daysLeft <= 1 => 'h1',
                    $daysLeft > 7 => 'h30',
                    default => null,
                };
                if ($stage && ! $s->reminded($stage)) {
                    $this->remind($s, $stage);
                    $n['reminders']++;
                }
            });

        // invoice perpanjangan 7 hari sebelum periode berakhir
        Subscription::acrossTenants()->with('plan')->where('status', 'active')->where('cancel_at_period_end', false)
            ->whereBetween('current_period_end', [now(), now()->addDays(7)])->each(function (Subscription $s) use (&$n) {
                $exists = Invoice::acrossTenants()->where('subscription_id', $s->id)->whereIn('status', ['issued', 'overdue'])->exists();
                $amount = PaymentService::price($s->plan, $s->cycle);
                $company = $s->plan?->tenant_type === 'company';
                if ($exists || $amount <= 0) {
                    // perusahaan tetap diingatkan H-7 walau invoice sudah dibuat sendiri / paket tanpa harga
                    if ($company && ! $s->reminded('h7')) {
                        $this->remind($s, 'h7');
                        $n['reminders']++;
                    }

                    return;
                }
                $label = "Perpanjangan {$s->plan->name} — ".PaymentService::CYCLE_LABELS[$s->cycle];
                $inv = Invoice::create([
                    'tenant_id' => $s->tenant_id, 'number' => 'TMP-'.Str::random(16), 'type' => 'subscription', 'status' => 'issued',
                    'subscription_id' => $s->id, 'plan_id' => $s->plan_id, 'cycle' => $s->cycle,
                    'lines' => [['code' => "{$s->plan->key}-{$s->cycle}", 'label' => $label, 'qty' => 1, 'amount' => $amount]],
                    'subtotal' => $amount, 'total' => $amount, 'issued_at' => now(), 'due_at' => $s->current_period_end,
                ]);
                $inv->update(['number' => sprintf('INV/%s/%06d', now('Asia/Jakarta')->format('Y/m'), $inv->id)]);
                $data = ['id' => $inv->public_id, 'number' => $inv->number, 'due' => $inv->due_at->toIso8601String(), 'total' => $amount];
                // perusahaan: owner + peran tagihan, ditandai h7 agar tidak dobel; personal: pemilik saja seperti sebelumnya
                $recipients = $company ? self::billingRecipients($s->tenant_id) : array_filter([Tenant::find($s->tenant_id)?->owner_user_id]);
                foreach ($recipients as $uid) {
                    Notify::user($uid, $s->tenant_id, 'invoice_due', $data);
                }
                if ($company) {
                    $s->markReminded('h7');
                }
                $n['renewal']++;
            });

        // periode berakhir
        Subscription::acrossTenants()->with('plan')->whereIn('status', ['active', 'trialing'])->where('current_period_end', '<', now())->each(function (Subscription $s) use (&$n) {
            $company = $s->plan?->tenant_type === 'company';
            if ($company && ($s->cancel_at_period_end || $s->status === 'trialing')) {
                $this->expire($s, $s->status === 'trialing' ? 'trial' : 'cancelled'); // uji coba habis: langsung terkunci, tanpa tenggang
                $n['expired']++;

                return;
            }
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
        Subscription::acrossTenants()->with('plan')->where('status', 'past_due')->where('grace_until', '<', now())->each(function (Subscription $s) use (&$n) {
            if ($s->plan?->tenant_type === 'company') {
                $this->expire($s, 'expired');
                $n['expired']++;

                return;
            }
            $this->downgrade($s, 'expired');
            $n['downgraded']++;
        });

        $this->info(json_encode($n));

        return self::SUCCESS;
    }

    /** Pengingat masa aktif perusahaan (h30 / h7 / h1 / trial) ke owner + peran tagihan, lalu tandai agar tidak terkirim ulang. */
    private function remind(Subscription $s, string $stage): void
    {
        $tenant = Tenant::find($s->tenant_id);
        $inv = Invoice::acrossTenants()->where('subscription_id', $s->id)->whereIn('status', ['issued', 'overdue'])->latest('id')->first();
        $data = array_filter([
            'stage' => $stage, 'days' => max(1, (int) ceil(now()->diffInSeconds($s->current_period_end) / 86400)), 'plan' => $s->plan?->name,
            'workspace' => $tenant?->name, 'end' => $s->current_period_end->toIso8601String(), 'id' => $inv?->public_id, 'number' => $inv?->number,
        ]);
        foreach (self::billingRecipients($s->tenant_id) as $uid) {
            Notify::user($uid, $s->tenant_id, $stage === 'trial' ? 'trial_ending' : 'subscription_reminder', $data);
        }
        $s->markReminded($stage);
    }

    /** Owner + anggota aktif yang perannya punya izin `billing.manage` (mis. Billing). Anggota biasa tidak. */
    public static function billingRecipients(int $tenantId): array
    {
        $owner = Tenant::whereKey($tenantId)->value('owner_user_id');
        $billing = TenantMember::acrossTenants()->where('tenant_id', $tenantId)->where('status', 'active')
            ->whereIn('role_id', RolePermission::where('permission_key', 'billing.manage')->select('role_id'))->pluck('user_id')->all();

        return array_values(array_unique(array_filter([$owner, ...$billing])));
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
            'plan' => $s->plan?->name, 'grace_until' => $s->grace_until?->toIso8601String(), 'company' => $s->plan?->tenant_type === 'company',
            'id' => $inv?->public_id, 'number' => $inv?->number,
        ]));
    }

    /** Perusahaan tidak turun ke paket gratis: langganan berakhir, dashboard terkunci (ResolveWorkspace), kartu tetap jalan. */
    private function expire(Subscription $s, string $reason): void
    {
        $tenant = Tenant::find($s->tenant_id);
        $s->update(['status' => 'expired', 'cancelled_at' => now()]);
        Invoice::acrossTenants()->where('subscription_id', $s->id)->whereIn('status', ['issued', 'overdue'])->update(['status' => 'void']);
        $tenant->update(['status' => 'active']);
        PlanFeatures::forget($tenant);
        if ($tenant->owner_user_id) {
            Notify::user($tenant->owner_user_id, $tenant->id, 'workspace_expired', ['plan' => $s->plan?->name, 'reason' => $reason]);
        }
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
