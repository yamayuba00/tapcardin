<?php

namespace App\Support\Mail;

/**
 * Bagian bersama tata letak email (resources/views/mail/layout.blade.php): logo, warna nada, footer.
 * Semua email transaksional (notifikasi & undangan) memakai bentuk data yang sama:
 *
 * @phpstan-type Mail array{subject:string, preheader:string, title:string, tone:string, name:?string, lines:list<string>,
 *     facts:list<array{0:string,1:string}>, items?:list<array{label:string,qty:int,amount:?string}>,
 *     action:?array{label:string,url:string}, notes?:list<string>, footer:array}
 */
final class MailLayout
{
    /** Palet TapCard per nada: garis aksen & badge (terang), varian gelap untuk prefers-color-scheme. */
    public const TONES = [
        'default' => ['label' => 'Info', 'bar' => '#C9A96E', 'ink' => '#7D6230', 'soft' => '#F6EFE1', 'dark' => '#E2CFA6'],
        'ok' => ['label' => 'Berhasil', 'bar' => '#2F6E58', 'ink' => '#2F6E58', 'soft' => '#E6F1EC', 'dark' => '#7FB8A4'],
        'warn' => ['label' => 'Perlu perhatian', 'bar' => '#E0A458', 'ink' => '#8A5A12', 'soft' => '#FBF0DF', 'dark' => '#E0A458'],
        'danger' => ['label' => 'Penting', 'bar' => '#A63A2B', 'ink' => '#A63A2B', 'soft' => '#F8E6E3', 'dark' => '#E07A6A'],
    ];

    public static function tone(string $tone): array
    {
        return self::TONES[$tone] ?? self::TONES['default'];
    }

    /** URL absolut aset email (gambar harus bisa diunduh klien email, jadi tidak boleh relatif). */
    public static function asset(string $path): string
    {
        $base = config('tapcard.mail.asset_url') ?: config('app.url');

        return rtrim((string) $base, '/').'/'.ltrim($path, '/');
    }

    public static function logoUrl(): string
    {
        return self::asset('email/tapcard-logo.png');
    }

    public static function helpUrl(): string
    {
        if ($url = config('tapcard.mail.help_url')) {
            return $url;
        }
        if ($wa = config('tapcard.sales_whatsapp')) {
            return 'https://wa.me/'.$wa;
        }
        $app = parse_url((string) config('app.url'));

        return ($app['scheme'] ?? 'https').'://'.config('tapcard.base_domain').(isset($app['port']) ? ':'.$app['port'] : '');
    }

    public static function sender(): string
    {
        return (string) config('mail.from.address');
    }

    /**
     * Footer: untuk siapa, kenapa email ini dikirim, dan tautan pengaturan (hanya untuk jenis yang bisa dimatikan).
     *
     * @return array{recipient:?string, workspace:?string, reason:string, settings_url:?string, settings_label:string, help_url:string, sender:string}
     */
    public static function footer(?string $recipient, ?string $workspace, string $reason, ?string $settingsUrl, string $settingsLabel = 'Pengaturan notifikasi'): array
    {
        return [
            'recipient' => $recipient ?: null,
            'workspace' => $workspace ?: null,
            'reason' => $reason,
            'settings_url' => $settingsUrl,
            'settings_label' => $settingsLabel,
            'help_url' => self::helpUrl(),
            'sender' => self::sender(),
        ];
    }

    /** Lebar tombol VML Outlook (tidak bisa menyesuaikan isi) — perkiraan dari panjang label. */
    public static function buttonWidth(string $label): int
    {
        return max(180, min(420, mb_strlen($label) * 9 + 56));
    }
}
