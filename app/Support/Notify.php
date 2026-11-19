<?php

namespace App\Support;

use App\Enums\TenantType;
use App\Jobs\BroadcastNotification;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Mail\EmailContent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            // siaran lewat antrean `broadcast` setelah commit; gagal antre tidak boleh menggagalkan aksi
            rescue(fn () => BroadcastNotification::dispatch($n->id, $user->public_id, $tenant?->public_id)->afterCommit(), report: true);
        }
        if ($user->wantsNotification($type, 'email', EmailContent::defaultOn($type)) && EmailContent::for($type, $data) !== null) {
            self::email($user->email, $user->name, $type, $data, $tenant);
        }

        return $n;
    }

    /** Jendela penggabungan notifikasi tap per kartu (menit, dihitung dari tap pertama). */
    public const TAP_WINDOW_MINUTES = 60;

    /**
     * Notifikasi tap digabung per (pengguna, workspace, kartu): tap berikutnya dalam 60 menit menambah `count` pada
     * notifikasi belum dibaca yang sama, lalu disiarkan ulang dengan id yang sama (klien memperbarui di tempat).
     * Advisory lock per kunci → beberapa worker antrean tidak membuat baris ganda / kehilangan hitungan.
     * Email hanya saat notifikasi baru dibuat, tidak pada setiap penambahan.
     *
     * @param  array{card:string,label:?string,city:?string,source:string,tap_id:int,at:string}  $tap
     */
    public static function tap(int $userId, int $tenantId, array $tap): ?Notification
    {
        $user = User::find($userId);
        if (! $user) {
            return null;
        }
        $tenant = Tenant::find($tenantId);
        $created = false;
        $n = null;
        $inapp = $user->wantsNotification('tap', 'inapp');
        if ($inapp) {
            [$n, $created] = DB::transaction(function () use ($userId, $tenantId, $tap) {
                DB::select('select pg_advisory_xact_lock(hashtext(?), hashtext(?))', ['tap-notif', "$userId:$tenantId:{$tap['card']}"]);
                $open = Notification::where('user_id', $userId)->where('tenant_id', $tenantId)->whereNull('read_at')
                    ->where('type', 'tap')->where('data->card', $tap['card'])
                    ->where('created_at', '>=', now()->subMinutes(self::TAP_WINDOW_MINUTES))
                    ->latest('id')->first();
                if (! $open) {
                    return [Notification::create(['user_id' => $userId, 'tenant_id' => $tenantId, 'type' => 'tap', 'data' => self::tapData(null, $tap)]), true];
                }
                $open->update(['data' => self::tapData($open->data, $tap)]);

                return [$open, false];
            });
            rescue(fn () => BroadcastNotification::dispatch($n->id, $user->public_id, $tenant?->public_id)->afterCommit(), report: true);
        }
        // email sekali per jendela: saat notifikasi dibuat, atau (in-app dimatikan) maks sekali per kartu per 60 menit
        $mailDue = $inapp ? $created : Cache::add("tap-mail:$userId:$tenantId:{$tap['card']}", 1, self::TAP_WINDOW_MINUTES * 60);
        if ($mailDue && $user->wantsNotification('tap', 'email', EmailContent::defaultOn('tap')) && EmailContent::for('tap', $tap) !== null) {
            self::email($user->email, $user->name, 'tap', $tap, $tenant);
        }

        return $n;
    }

    /** Data gabungan: count, kota (≤ 3 berbeda, terbaru dulu), sumber & waktu terakhir; baris lama tanpa count = 1. */
    private static function tapData(?array $prev, array $tap): array
    {
        $cities = array_values(array_unique(array_filter([$tap['city'] ?? null, ...($prev['cities'] ?? [$prev['city'] ?? null])])));

        return [
            'card' => $tap['card'],
            'label' => $tap['label'] ?? $prev['label'] ?? null,
            'count' => $prev ? (int) ($prev['count'] ?? 1) + 1 : 1,
            'city' => $tap['city'] ?? null,
            'cities' => array_slice($cities, 0, 3),
            'source' => $tap['source'],
            'first_at' => $prev['first_at'] ?? $prev['at'] ?? $tap['at'],
            'at' => $tap['at'],
            'tap_id' => $tap['tap_id'],
        ];
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
    public static function baseUrl(?Tenant $tenant): string
    {
        $app = rtrim((string) config('app.url'), '/');
        if (! $tenant || $tenant->type === TenantType::Personal || ! $tenant->slug) {
            return $app;
        }
        $u = parse_url($app);

        return ($u['scheme'] ?? 'https').'://'.$tenant->slug.'.'.config('tapcard.base_domain').(isset($u['port']) ? ':'.$u['port'] : '');
    }
}
