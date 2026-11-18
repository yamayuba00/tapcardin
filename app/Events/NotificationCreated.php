<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Notifikasi in-app baru → dikirim realtime lewat Reverb ke channel privat pemiliknya (`user.{public_id}`).
 * Disiarkan oleh job BroadcastNotification di antrean `broadcast` (worker sendiri), jadi Reverb lambat/mati
 * tidak menahan pencatatan tap, lead, atau email. Klien memfilter `tenant` sesuai workspace (sama dengan SSE).
 */
class NotificationCreated implements ShouldBroadcastNow
{
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
