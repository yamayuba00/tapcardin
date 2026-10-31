<?php

namespace App\Actions\Auth;

use App\Mail\AuthCodeMail;
use App\Models\LoginOtp;
use App\Support\Hasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Kode email 6 digit (hash, berlaku 10 menit) — hanya untuk verifikasi pendaftaran manual (`register`) dan
 * lupa password (`reset`); login biasa memakai password atau Google. Throttle 3/email/10 menit & 10/IP/jam.
 */
final class RequestOtp
{
    public const PURPOSES = ['register', 'reset'];

    public function handle(string $email, string $ip, string $purpose, ?array $payload = null): void
    {
        $email = strtolower(trim($email));
        $limits = self::devCode() ? [] : [["otp:$purpose:$email", 3, 600], ['otp:ip:'.Hasher::ip($ip), 10, 3600]];
        foreach ($limits as [$key, $max, $decay]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => 'Terlalu banyak permintaan kode. Coba lagi dalam '.ceil(RateLimiter::availableIn($key) / 60).' menit.',
                ])->status(429);
            }
            RateLimiter::hit($key, $decay);
        }

        // Pengembangan lokal saja: kode tetap (DEV_LOGIN_CODE) agar tidak perlu membuka log email.
        $code = self::devCode() ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Kode lama untuk email & tujuan yang sama dinonaktifkan agar hanya kode terbaru yang berlaku.
        LoginOtp::where('email', $email)->where('purpose', $purpose)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        LoginOtp::create([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'payload' => $payload,
            'expires_at' => now()->addMinutes(10),
            'ip_hash' => Hasher::ip($ip),
        ]);

        Mail::to($email)->send(new AuthCodeMail($code, $purpose));
    }

    /** Hanya aktif saat APP_ENV=local dan DEV_LOGIN_CODE berisi 6 digit; selain itu null. */
    public static function devCode(): ?string
    {
        $code = (string) config('tapcard.dev_login_code');

        return app()->isLocal() && preg_match('/^\d{6}$/', $code) ? $code : null;
    }
}
