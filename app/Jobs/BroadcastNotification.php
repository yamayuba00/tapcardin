<?php

namespace App\Jobs;

use App\Events\NotificationCreated;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Siaran realtime satu notifikasi — upaya terbaik: sekali coba, tanpa ulang, tidak masuk failed_jobs.
 * Notifikasi sudah tersimpan; layar tetap menerimanya lewat SSE / saat dibuka. Galat dilaporkan maks sekali per menit.
 */
class BroadcastNotification implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public int $notificationId, public string $userPublicId, public ?string $tenantPublicId)
    {
        $this->onQueue('broadcast');
    }

    public function handle(): void
    {
        $n = Notification::find($this->notificationId);
        if (! $n) {
            return;
        }
        try {
            broadcast(new NotificationCreated($n, $this->userPublicId, $this->tenantPublicId));
        } catch (Throwable $e) {
            if (Cache::add('reverb:broadcast-failed', 1, 60)) {
                report($e);
            }
        }
    }
}
