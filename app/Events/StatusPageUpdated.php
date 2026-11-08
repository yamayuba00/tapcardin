<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Halaman status publik perlu dimuat ulang (status monitor publik berubah / insiden diperbarui). Tanpa data sensitif. */
class StatusPageUpdated implements ShouldBroadcastNow
{
    public function broadcastOn(): array
    {
        return [new Channel('status')];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    public function broadcastWith(): array
    {
        return ['at' => now()->toIso8601String()];
    }
}
