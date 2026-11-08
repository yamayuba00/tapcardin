<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Hasil cek uptime → dasbor staf (`platform.uptime`) seketika. Langsung (tanpa antrean): pemeriksa berjalan di
 * scheduler, dan antrean bisa justru sedang bermasalah saat ada gangguan.
 */
class MonitorChecked implements ShouldBroadcastNow
{
    /** @param  ?array{from:string,to:string,reason:?string}  $change */
    public function __construct(public readonly array $monitor, public readonly array $check, public readonly ?array $change) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('platform.uptime')];
    }

    public function broadcastAs(): string
    {
        return 'checked';
    }
}
