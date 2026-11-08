<?php

namespace App\Support;

use App\Models\PlatformStaff;
use App\Models\Role;
use Throwable;

/** Penerima & alamat untuk pemberitahuan operasional (alarm kesehatan, uptime) — staf Pemilik Platform + ALERT_EMAILS. */
final class PlatformAlerts
{
    /** @return array<string,string> email => nama. ALERT_EMAILS dulu (tetap jalan walau database mati), lalu Pemilik Platform. */
    public static function recipients(): array
    {
        $list = array_fill_keys(config('tapcard.alert.emails'), 'Tim TapCard');
        try {
            PlatformStaff::with('user')->where('status', 'active')->where('role_id', Role::system('platform_owner')->id)->get()
                ->each(function ($s) use (&$list) {
                    if ($s->user && ! $s->user->trashed()) {
                        $list[$s->user->email] = $s->user->name;
                    }
                });
        } catch (Throwable) {
            // database mati → hanya alamat dari ALERT_EMAILS
        }

        return $list;
    }

    /** Kirim email operasional ke semua penerima, langsung (tanpa antrean). @return bool ada penerima */
    public static function email(string $type, array $data): bool
    {
        $to = self::recipients();
        foreach ($to as $email => $name) {
            Notify::email($email, $name, $type, $data, now: true);
        }

        return $to !== [];
    }

    public static function panelUrl(string $path = ''): string
    {
        $u = parse_url((string) config('app.url'));

        return ($u['scheme'] ?? 'https').'://'.config('tapcard.panel_subdomain').'.'.config('tapcard.base_domain').(isset($u['port']) ? ':'.$u['port'] : '').$path;
    }

    public static function statusUrl(): string
    {
        $u = parse_url((string) config('app.url'));

        return ($u['scheme'] ?? 'https').'://status.'.config('tapcard.base_domain').(isset($u['port']) ? ':'.$u['port'] : '');
    }
}
