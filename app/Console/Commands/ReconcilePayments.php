<?php

namespace App\Console\Commands;

use App\Actions\Billing\ApplyPayment;
use App\Models\Invoice;
use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;

/**
 * Jaring pengaman bila webhook gateway terlambat/hilang: tanyakan status invoice terbuka (48 jam terakhir) yang
 * sudah punya sesi bayar langsung ke gateway, lalu proses lewat jalur yang sama dengan webhook (idempoten).
 */
class ReconcilePayments extends Command
{
    protected $signature = 'tapcard:payments-reconcile';

    protected $description = 'Cocokkan status invoice terbuka dengan gateway pembayaran';

    public function handle(ApplyPayment $apply): int
    {
        $checked = $paid = 0;
        Invoice::acrossTenants()->whereIn('status', ['issued', 'overdue'])->whereNotNull('payment_ref')
            ->where('created_at', '>=', now()->subHours(48))->orderBy('id')
            ->each(function (Invoice $invoice) use ($apply, &$checked, &$paid) {
                $gw = PaymentService::gatewayFor($invoice->payment_provider);
                $n = $gw?->fetchStatus($invoice);
                if (! $n) {
                    return;
                }
                $checked++;
                if ($apply->handle($n) === 'paid') {
                    $paid++;
                }
            });
        $this->info("$checked invoice dicek, $paid lunas.");

        return self::SUCCESS;
    }
}
