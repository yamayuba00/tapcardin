<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * ALTCHA (format challenge v3, algoritma SHA-256) — anti-bot proof-of-work tanpa layanan pihak ketiga & tanpa
 * cookie pelacak. Sama dengan `createChallenge`/`verifySolution` dari paket `altcha/lib`:
 *   kunci   = SHA-256^cost( salt ‖ nonce ‖ counter[uint32 big-endian] )
 *   server memilih counter acak, menyimpan awalan kunci (keyPrefix) di parameter, lalu menandatangani parameter
 *   (HMAC-SHA256 atas JSON berkunci terurut). Browser mencoba counter 0,1,2,… sampai awalannya cocok.
 * Verifikasi: tanda tangan cocok, belum kedaluwarsa, kunci hasil hitung ulang sama & berawalan keyPrefix,
 * dan setiap challenge hanya bisa dipakai sekali (penanda di cache sampai kedaluwarsa).
 */
final class Altcha
{
    public const TTL = 600; // detik

    private const KEY_LENGTH = 32;

    private const PREFIX_BYTES = 16; // awalan kunci yang harus ditemukan browser

    public static function enabled(): bool
    {
        return (bool) config('tapcard.altcha.enabled', true);
    }

    public static function challenge(): array
    {
        $max = max(10, (int) config('tapcard.altcha.max_number', 100000));
        $p = [
            'algorithm' => 'SHA-256',
            'cost' => 1,
            'expiresAt' => now()->getTimestamp() + self::TTL,
            'keyLength' => self::KEY_LENGTH,
            'nonce' => bin2hex(random_bytes(16)),
            'salt' => bin2hex(random_bytes(16)),
        ];
        $p['keyPrefix'] = substr(self::derive($p, random_int((int) ($max / 10), $max)), 0, self::PREFIX_BYTES * 2);
        ksort($p);

        return ['parameters' => $p, 'signature' => self::sign($p)];
    }

    public static function valid(?string $payload): bool
    {
        $data = json_decode((string) base64_decode((string) $payload, true), true);
        $p = $data['challenge']['parameters'] ?? null;
        $sig = $data['challenge']['signature'] ?? null;
        $sol = $data['solution'] ?? null;
        if (! is_array($p) || ! is_string($sig) || ! is_array($sol) || ! empty($data['test'])
            || ($p['algorithm'] ?? null) !== 'SHA-256' || ! is_int($p['cost'] ?? null) || $p['cost'] < 1 || $p['cost'] > 100
            || ! is_int($p['expiresAt'] ?? null) || $p['expiresAt'] < now()->getTimestamp()
            || ! hash_equals(self::sign($p), $sig)) {
            return false;
        }
        $counter = $sol['counter'] ?? null;
        $key = $sol['derivedKey'] ?? null;
        if (! is_int($counter) || $counter < 0 || $counter > 0xFFFFFFFF || ! is_string($key) || ! preg_match('/^(?:[0-9a-f]{2})+$/', $key)) {
            return false;
        }
        $derived = self::derive($p, $counter);
        if (! hash_equals($derived, $key) || ! str_starts_with($derived, strtolower((string) $p['keyPrefix']))) {
            return false;
        }

        // sekali pakai: payload yang sama tidak bisa diputar ulang
        return Cache::add('altcha:'.$sig, 1, max(1, $p['expiresAt'] - now()->getTimestamp()));
    }

    /** Lempar galat validasi pada field `altcha` bila payload tidak sah. */
    public static function require(Request $request): void
    {
        if (self::enabled() && ! self::valid($request->input('altcha'))) {
            throw ValidationException::withMessages(['altcha' => 'Verifikasi anti-robot gagal atau kedaluwarsa. Centang lagi lalu coba ulang.']);
        }
    }

    /** Kunci (hex) untuk counter tertentu — sama dengan worker `sha` milik widget. */
    public static function derive(array $p, int $counter): string
    {
        $key = hex2bin($p['salt']).hex2bin($p['nonce']).pack('N', $counter);
        for ($i = 0; $i < max(1, (int) $p['cost']); $i++) {
            $key = hash('sha256', $key, true);
        }

        return bin2hex(substr($key, 0, (int) ($p['keyLength'] ?? self::KEY_LENGTH)));
    }

    /** HMAC atas JSON parameter berkunci terurut (setara canonicalJSON di altcha/lib). */
    private static function sign(array $p): string
    {
        ksort($p);
        $json = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $json, (string) (config('tapcard.altcha.key') ?: hash_hmac('sha256', 'altcha', (string) config('app.key'))));
    }
}
