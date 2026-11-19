<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Support\Webhooks\Webhooks;
use App\Support\Webhooks\WebhookSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Antrean `webhooks` (worker sendiri): kirim satu delivery; gagal → dilepas ulang ke antrean sesuai jeda Webhooks::BACKOFF. */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = WebhookSender::MAX_ATTEMPTS + 1;

    public int $timeout = 20;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue(Webhooks::QUEUE);
    }

    public function handle(WebhookSender $sender): void
    {
        $d = WebhookDelivery::acrossTenants()->with(['endpoint' => fn ($q) => $q->withoutGlobalScopes()])->find($this->deliveryId);
        if (! $d || in_array($d->status, ['success', 'failed'], true)) {
            return;
        }
        if ($delay = $sender->attempt($d)) {
            $this->release($delay);
        }
    }

    /** Worker mati / waktu habis berulang: tandai gagal agar tidak menggantung "menunggu" selamanya. */
    public function failed(?Throwable $e): void
    {
        WebhookDelivery::acrossTenants()->whereKey($this->deliveryId)->whereNotIn('status', ['success', 'failed'])
            ->update(['status' => 'failed', 'error' => 'Pengiriman dihentikan: '.mb_substr((string) $e?->getMessage(), 0, 200), 'next_retry_at' => null]);
    }
}
