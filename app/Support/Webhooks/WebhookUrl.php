<?php

namespace App\Support\Webhooks;

/**
 * Validasi URL webhook + perlindungan SSRF: https wajib (http hanya di APP_ENV=local), tanpa user:pass, port 80/443/≥1024,
 * dan SEMUA IP hasil resolusi harus publik (tolak loopback, privat, link-local/metadata, CGNAT, reserved).
 * Dipanggil saat disimpan DAN tepat sebelum tiap pengiriman; IP yang lolos dipakai langsung (anti DNS rebinding).
 */
final class WebhookUrl
{
    /** Rentang yang tidak tertangkap FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE. */
    private const EXTRA_BLOCKED_V4 = ['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24'];

    /**
     * @return array{host:string, port:int, ip:string}
     *
     * @throws InvalidWebhookUrl
     */
    public static function resolve(string $url): array
    {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = strtolower(trim($p['host'] ?? '', '[]'));
        if (! $host || ! in_array($scheme, ['https', 'http'], true)) {
            throw new InvalidWebhookUrl('Tulis URL lengkap, mis. https://contoh.com/webhook.');
        }
        if ($scheme === 'http' && ! app()->isLocal()) {
            throw new InvalidWebhookUrl('URL harus memakai https://.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new InvalidWebhookUrl('URL tidak boleh berisi nama pengguna atau password.');
        }
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, [80, 443], true) && $port < 1024) {
            throw new InvalidWebhookUrl('Port tidak diizinkan. Pakai 443, 80, atau port ≥ 1024.');
        }

        $ips = app(HostResolver::class)->ips($host);
        if (! $ips) {
            throw new InvalidWebhookUrl('Domain tujuan tidak ditemukan (DNS).');
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip) && ! self::allowPrivate()) {
                throw new InvalidWebhookUrl('URL mengarah ke alamat jaringan internal dan tidak diizinkan.');
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublic(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7); // IPv4 yang dibungkus IPv6
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::EXTRA_BLOCKED_V4 as $cidr) {
                [$net, $bits] = explode('/', $cidr);
                $mask = -1 << (32 - (int) $bits);
                if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Hanya untuk pengembangan lokal (mis. penerima di localhost): APP_ENV=local + WEBHOOKS_ALLOW_PRIVATE=true. */
    private static function allowPrivate(): bool
    {
        return app()->isLocal() && (bool) config('tapcard.webhooks.allow_private');
    }
}
