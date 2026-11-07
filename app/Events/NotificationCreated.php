<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Notifikasi in-app baru → dikirim realtime lewat Reverb ke channel privat pemiliknya (`user.{public_id}`).
 * Dikirim setelah transaksi selesai & lewat antrean `notify`, jadi request tidak menunggu server WebSocket.
 * Klien memfilter `tenant` sesuai workspace yang sedang dibuka (sama dengan SSE).
 */
class NotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public string $queue = 'notify';

    public function __construct(public readonly Notification $notification, public readonly string $userPublicId, public readonly ?string $tenantPublicId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userPublicId}")];
    }

    public function broadcastAs(): string
    {
        return 'notification';
    }

    public function broadcastWith(): array
    {
        $n = $this->notification;

        return ['id' => $n->id, 'type' => $n->type, 'tenant' => $this->tenantPublicId,
            'data' => ($n->data ?? []) + ['at' => $n->created_at?->toIso8601String()]];
    }
}
