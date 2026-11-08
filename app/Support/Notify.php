<?php

namespace App\Support;

use App\Enums\TenantType;
use App\Events\NotificationCreated;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Mail\EmailContent;
use Illuminate\Support\Facades\Mail;

/**
 * Menulis notifikasi in-app lalu menyiarkannya realtime (Reverb); SSE tetap membaca tabel sebagai cadangan (TRD §9).
 * Email dikirim terpisah lewat antrean bila saklar "Email" jenis itu menyala (default: EmailContent::DEFAULT_ON).
 */
final class Notify
{
    public static function user(int $userId, ?int $tenantId, string $type, array $data = []): ?Notification
    {
        $user = User::find($userId);
        if (! $user) {
            return null;
        }
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        $n = null;
        if ($user->wantsNotification($type, 'inapp')) {
            $n = Notification::create([
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'type' => $type,
                'data' => $data,
            ]);
            // siaran gagal (mis. server WebSocket mati) tidak boleh menggagalkan aksi; notifikasi tetap tersimpan
            rescue(fn () => broadcast(new NotificationCreated($n, $user->public_id, $tenant?->public_id)), report: true);
        }
        if ($user->wantsNotification($type, 'email', EmailContent::defaultOn($type)) && EmailContent::for($type, $data) !== null) {
            self::email($user->email, $user->name, $type, $data, $tenant);
        }

        return $n;
    }

    /**
     * Email langsung ke alamat tertentu (mis. akun yang baru dihapus — barisnya sudah tidak punya email).
     * `$now` = kirim saat itu juga tanpa antrean (alarm sistem: antrean bisa ikut bermasalah).
     */
    public static function email(string $to, string $name, string $type, array $data, ?Tenant $tenant = null, bool $now = false): void
    {
        if (str_ends_with($to, '@invalid')) {
            return;
        }
        $mail = new NotificationMail($type, $data, $name, self::baseUrl($tenant));
        rescue(fn () => $now ? Mail::to($to, $name)->sendNow($mail) : Mail::to($to, $name)->queue($mail), report: true);
    }

    /** Tautan di email membuka workspace yang benar: subdomain perusahaan, atau app. untuk workspace personal. */
    private static function baseUrl(?Tenant $tenant): string
    {
        $app = rtrim((string) config('app.url'), '/');
        if (! $tenant || $tenant->type === TenantType::Personal || ! $tenant->slug) {
            return $app;
        }
        $u = parse_url($app);

        return ($u['scheme'] ?? 'https').'://'.$tenant->slug.'.'.config('tapcard.base_domain').(isset($u['port']) ? ':'.$u['port'] : '');
    }
}
