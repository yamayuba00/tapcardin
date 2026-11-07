<?php

namespace App\Support;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;

/** Menulis notifikasi in-app lalu menyiarkannya realtime (Reverb); SSE tetap membaca tabel sebagai cadangan (TRD §9). */
final class Notify
{
    public static function user(int $userId, ?int $tenantId, string $type, array $data = []): ?Notification
    {
        $user = User::find($userId);
        if (! $user || ! $user->wantsNotification($type, 'inapp')) {
            return null;
        }

        $n = Notification::create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'type' => $type,
            'data' => $data,
        ]);
        // siaran gagal (mis. server WebSocket mati) tidak boleh menggagalkan aksi; notifikasi tetap tersimpan
        rescue(fn () => broadcast(new NotificationCreated($n, $user->public_id, $tenantId ? Tenant::whereKey($tenantId)->value('public_id') : null)), report: true);

        return $n;
    }
}
