<?php

namespace App\Console\Commands;

use App\Actions\Billing\ApplyPayment;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Payment\PaymentService;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pesanan yang tidak dibayar (config tapcard.orders.unpaid_cancel_days, default 3 hari): pengingat sehari sebelumnya,
 * lalu batal otomatis. Sebelum batal, invoice yang masih terbuka ditanyakan sekali lagi ke gateway agar pembayaran di
 * detik terakhir tetap diproses. Bila uang tetap masuk setelah batal, ApplyPayment memasukkannya ke antrean tinjauan
 * (refund), seperti pesanan yang dibatalkan pemesan.
 */
class ExpireOrders extends Command
{
    protected $signature = 'tapcard:expire-orders';

    protected $description = 'Ingatkan lalu batalkan pesanan yang tidak dibayar';

    public function handle(ApplyPayment $apply): int
    {
        $days = (int) config('tapcard.orders.unpaid_cancel_days');
        $pending = fn () => Order::withoutGlobalScopes()->where('status', 'pending_payment');
        [$reminded, $cancelled] = [0, 0];

        $pending()->whereNull('payment_reminded_at')->where('created_at', '<', now()->subDays($days - 1))
            ->where('created_at', '>=', now()->subDays($days))->each(function (Order $o) use ($days, &$reminded) {
                $o->update(['payment_reminded_at' => now()]);
                if ($o->user_id) {
                    Notify::user($o->user_id, $o->tenant_id, 'order_unpaid', ['id' => $o->public_id, 'number' => $o->number,
                        'total' => (int) $o->total, 'cancel_at' => $o->created_at->copy()->addDays($days)->toIso8601String()]);
                }
                $reminded++;
            });

        $pending()->where('created_at', '<', now()->subDays($days))->each(function (Order $o) use ($apply, $days, &$cancelled) {
            Invoice::withoutGlobalScopes()->where('order_id', $o->id)->where('status', 'issued')->whereNotNull('payment_ref')->get()
                ->each(fn (Invoice $i) => ($n = PaymentService::gatewayFor($i->payment_provider)?->fetchStatus($i)) && $apply->handle($n));

            $done = DB::transaction(function () use ($o, $days) {
                $o = Order::withoutGlobalScopes()->whereKey($o->id)->lockForUpdate()->first();
                if ($o->status !== 'pending_payment') {
                    return false; // baru saja lunas
                }
                Invoice::withoutGlobalScopes()->where('order_id', $o->id)->whereIn('status', ['issued', 'overdue'])->update(['status' => 'void']);
                $o->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => "Tidak dibayar dalam $days hari"]);
                Audit::tenant($o->tenant_id, 'order.cancelled', $o, null, ['by' => 'system', 'reason' => 'unpaid']);

                return true;
            });
            if ($done) {
                if ($o->user_id) {
                    Notify::user($o->user_id, $o->tenant_id, 'order_status', ['id' => $o->public_id, 'number' => $o->number,
                        'status' => 'cancelled', 'label' => "Dibatalkan — tidak dibayar dalam $days hari"]);
                }
                $cancelled++;
            }
        });

        $this->info("$reminded pengingat, $cancelled pesanan dibatalkan.");

        return self::SUCCESS;
    }
}
