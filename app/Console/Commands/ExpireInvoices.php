<?php

namespace App\Console\Commands;

use App\Actions\Billing\ApplyPayment;
use App\Models\Invoice;
use App\Services\Payment\PaymentService;
use Illuminate\Console\Command;

/**
 * Invoice yang tidak dibayar sampai `expires_at` (config payment.invoice_ttl_minutes) dibatalkan. Sebelumnya status
 * ditanyakan sekali lagi ke gateway agar pembayaran di menit terakhir (webhook terlambat) tidak ikut batal.
 * Pesanan tetap "menunggu pembayaran" — tombol Bayar membuat invoice baru (CreateOrder::payUrl).
 */
class ExpireInvoices extends Command
{
    protected $signature = 'tapcard:expire-invoices';

    protected $description = 'Batalkan invoice yang lewat batas waktu bayar';

    public function handle(ApplyPayment $apply): int
    {
        $n = 0;
        Invoice::acrossTenants()->where('status', 'issued')->where('expires_at', '<', now())->orderBy('id')
            ->each(function (Invoice $invoice) use ($apply, &$n) {
                if ($invoice->payment_ref && ($n2 = PaymentService::gatewayFor($invoice->payment_provider)?->fetchStatus($invoice))
                    && $apply->handle($n2) === 'paid') {
                    return;
                }
                $n += Invoice::acrossTenants()->whereKey($invoice->id)->where('status', 'issued')->update(['status' => 'void']);
            });
        $this->info("$n invoice kedaluwarsa dibatalkan.");

        return self::SUCCESS;
    }
}
