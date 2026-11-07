<?php

namespace App\Support;

/** Hanya keluarga perangkat & browser yang disimpan (TRD §11). */
final class UserAgent
{
    public static function device(?string $ua): string
    {
        $ua = (string) $ua;

        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'ios',
            (bool) preg_match('/Android/i', $ua) => 'android',
            (bool) preg_match('/Windows|Macintosh|Linux|CrOS/i', $ua) => 'desktop',
            default => 'other',
        };
    }

    /**
     * Bot, crawler, & pratinjau tautan (WhatsApp/Telegram/Facebook membuka link untuk membuat preview) — bukan
     * interaksi orang, jadi tidak dihitung sebagai tap. UA kosong juga dianggap bot: browser selalu mengirim UA.
     */
    public static function isBot(?string $ua): bool
    {
        return trim((string) $ua) === '' || (bool) preg_match(
            // `bot/` (Googlebot/2.1, bingbot/2.0), bukan sekadar "bot": ponsel CUBOT tetap dihitung
            '/\bbot\b|bot\/|crawl|spider|slurp|preview|facebookexternalhit|facebookcatalog|WhatsApp\/|TelegramBot|Discordbot|'
            .'Slackbot|LinkedInBot|Twitterbot|Pinterest|SkypeUriPreview|vkShare|HeadlessChrome|PhantomJS|curl\/|Wget|'
            .'python-requests|python-urllib|Go-http-client|okhttp|Java\/|libwww|axios\/|node-fetch|Postman/i',
            (string) $ua,
        );
    }

    public static function browser(?string $ua): ?string
    {
        $ua = (string) $ua;

        return match (true) {
            str_contains($ua, 'Edg/') => 'edge',
            str_contains($ua, 'SamsungBrowser') => 'samsung',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') => 'chrome',
            str_contains($ua, 'FxiOS') || str_contains($ua, 'Firefox/') => 'firefox',
            str_contains($ua, 'Safari/') => 'safari',
            $ua === '' => null,
            default => 'other',
        };
    }
}
