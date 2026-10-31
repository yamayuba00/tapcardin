<?php

namespace App\Support;

/** TRD §11 — IP & UID disimpan sebagai HMAC dengan pepper, bukan nilai mentah. */
final class Hasher
{
    public static function ip(?string $ip): string
    {
        return hash_hmac('sha256', (string) $ip, (string) config('tapcard.ip_pepper'));
    }

    /** Pengunjung unik per hari: HMAC(ip + UA + tanggal). */
    public static function visitor(?string $ip, ?string $userAgent, string $date): string
    {
        return hash_hmac('sha256', $ip.'|'.$userAgent.'|'.$date, (string) config('tapcard.ip_pepper'));
    }

    public static function uid(string $uid): string
    {
        return hash_hmac('sha256', strtoupper($uid), (string) config('tapcard.uid_pepper'));
    }
}
