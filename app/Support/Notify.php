<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;

/** Menulis notifikasi in-app; SSE membacanya dari tabel (TRD §9). */
final class Notify
{
    public static function user(int $userId, ?int $tenantId, string $type, array $data = []): ?Notification
    {
        $user = User::find($userId);
        if (! $user || ! $user->wantsNotification($type, 'inapp')) {
            return null;
        }

        return Notification::create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'type' => $type,
            'data' => $data,
        ]);
    }
}
