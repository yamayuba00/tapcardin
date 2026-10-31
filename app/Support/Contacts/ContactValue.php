<?php

namespace App\Support\Contacts;

use App\Support\Phone;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat aturan isian kontak: normalisasi & validasi per jenis input, tautan tujuan,
 * teks tampilan, dan pemetaan vCard. Dipakai editor (simpan), resolver (bio link), redirect klik, vCard.
 */
final class ContactValue
{
    /**
     * @return array{value:string, meta:array}
     *
     * @throws ValidationException
     */
    public static function normalize(array $type, string $value, array $meta, string $key, ?int $tenantId = null): array
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages([$key => $msg]);
        $value = trim($value);

        return match ($type['input']) {
            'phone', 'wallet' => ['value' => Phone::toE164($value) ?? $fail('Nomor belum valid (8–15 digit).'), 'meta' => []],
            'email' => ['value' => filter_var($value, FILTER_VALIDATE_EMAIL) && strlen($value) <= 190 ? strtolower($value) : $fail('Email belum valid.'), 'meta' => []],
            'url' => ['value' => self::safeUrl($value) ?? $fail('Tautan harus diawali https:// dan bukan alamat lokal.'), 'meta' => []],
            'handle' => ['value' => self::handle($type, $value) ?? $fail('Isi username saja (huruf, angka, titik, garis bawah, atau strip), atau tempel tautan profilnya.'), 'meta' => []],
            'text' => ['value' => mb_strlen($value) <= 300 ? $value : $fail('Maksimal 300 karakter.'), 'meta' => []],
            'bank' => (function () use ($value, $meta, $fail, $key) {
                $digits = preg_replace('/[\s.-]/', '', $value);
                if (! preg_match('/^\d{5,20}$/', $digits)) {
                    $fail('Nomor rekening 5–20 digit.');
                }
                $bank = trim((string) ($meta['bank'] ?? ''));
                $holder = trim((string) ($meta['holder'] ?? ''));
                if ($bank === '' || mb_strlen($bank) > 60) {
                    throw ValidationException::withMessages([str_replace('.value', '.meta.bank', $key) => 'Pilih bank.']);
                }
                if ($holder === '' || mb_strlen($holder) > 120) {
                    throw ValidationException::withMessages([str_replace('.value', '.meta.holder', $key) => 'Isi nama pemilik rekening.']);
                }

                return ['value' => $digits, 'meta' => ['bank' => $bank, 'holder' => $holder]];
            })(),
            // gambar (QRIS) diunggah dulu lewat endpoint field-image; nilainya path milik workspace ini
            'image' => ['value' => $tenantId && str_starts_with($value, "t{$tenantId}/fields/") && ! str_contains($value, '..')
                ? $value : $fail('Unggah gambar QRIS terlebih dahulu.'), 'meta' => []],
            default => $fail('Jenis kontak tidak dikenal.'),
        };
    }

    /** Tautan tujuan saat diketuk; null = tidak bisa dibuka (salin / teks saja). */
    public static function href(array $type, string $value, array $meta = []): ?string
    {
        if (in_array($type['action'], ['copy', 'none'], true)) {
            return null;
        }
        if ($type['action'] === 'image') {
            return Storage::disk('public')->url($value);
        }
        if ($type['url_template']) {
            return strtr($type['url_template'], [
                '{v}' => $type['input'] === 'text' ? rawurlencode($value) : $value,
                '{digits}' => Phone::waDigits($value),
            ]);
        }

        return $value; // url biasa
    }

    /** Teks yang tampil di bio link (mis. "@rina", "BCA · 1234567890"). */
    public static function display(array $type, string $value, array $meta = []): string
    {
        return match ($type['input']) {
            'handle' => '@'.$value,
            'bank' => ($meta['bank'] ?? '').' · '.$value.(isset($meta['holder']) ? ' a.n. '.$meta['holder'] : ''),
            'url' => preg_replace('#^https?://(www\.)?#', '', rtrim($value, '/')),
            'image' => $type['label'],
            default => $value,
        };
    }

    /** Teks yang disalin oleh tombol "Salin" (nomor rekening / e-wallet / id). */
    public static function copyText(array $type, string $value): ?string
    {
        return $type['action'] === 'copy' ? ($type['input'] === 'wallet' ? Phone::local($value) : $value) : null;
    }

    private static function handle(array $type, string $value): ?string
    {
        // tempel tautan profil → ambil username-nya (mis. https://instagram.com/rina?igsh=… → rina)
        if (preg_match('#^https?://#i', $value)) {
            $path = trim((string) parse_url($value, PHP_URL_PATH), '/');
            $value = $type['key'] === 'linkedin' ? preg_replace('#^in/#', '', $path) : $path;
        }
        $value = ltrim(preg_replace('/\s+/', '', $value), '@~');

        return preg_match('/^[A-Za-z0-9._-]{1,60}$/', $value) ? $value : null;
    }

    /** Hanya https, bukan localhost / IP privat (mencegah tautan ke jaringan internal). */
    public static function safeUrl(string $value): ?string
    {
        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }
        $parts = parse_url($value);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || strlen($value) > 500) {
            return null;
        }
        $host = $parts['host'];
        if ($host === 'localhost' || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return null;
        }

        return $value;
    }
}
