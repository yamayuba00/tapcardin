<?php

namespace App\Actions\Auth;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardLoginKey;
use App\Models\User;
use App\Support\Hasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Login dengan kartu NFC = dua faktor: kartu fisik (token di URL chip + UID chip yang dibaca Web NFC,
 * dicocokkan dengan UID yang diikat saat encode) dan PIN 6 digit milik pemegang.
 *
 * Token kartu bersifat publik (tercetak di QR), jadi UID wajib cocok sebelum PIN diperiksa: penyerang
 * tanpa kartu di tangan tidak bisa menebak PIN maupun mengunci kunci orang lain. Semua kegagalan
 * memakai pesan yang sama agar tidak membocorkan kartu mana yang punya kunci login.
 */
final class CardLogin
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    private const GENERIC = 'Kartu atau PIN salah.';

    /** Status kartu yang masih boleh dipakai login (hilang/dicabut tidak). */
    private const USABLE = [CardStatus::Active, CardStatus::Paused];

    public function handle(string $token, string $uid, string $pin): User
    {
        $card = Card::acrossTenants()->where('card_token', $token)->first();
        $key = $card ? CardLoginKey::where('card_id', $card->id)->first() : null;
        $uid = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $uid));

        if (! $key || ! in_array($card->status, self::USABLE, true) || $key->user_id !== $card->holder_user_id
            || ! $card->chip_uid_hash || $uid === '' || ! hash_equals($card->chip_uid_hash, Hasher::uid($uid))) {
            throw ValidationException::withMessages(['pin' => self::GENERIC]);
        }

        if ($key->locked_until?->isFuture()) {
            throw ValidationException::withMessages([
                'pin' => 'Terlalu banyak PIN salah. Coba lagi dalam '.max(1, (int) ceil(now()->diffInSeconds($key->locked_until) / 60)).' menit.',
            ])->status(423);
        }

        if (! Hash::check($pin, $key->pin_hash)) {
            $attempts = $key->failed_attempts + 1;
            $key->forceFill($attempts >= self::MAX_ATTEMPTS
                ? ['failed_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]
                : ['failed_attempts' => $attempts])->save();
            throw ValidationException::withMessages(['pin' => self::GENERIC]);
        }

        $key->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_used_at' => now()])->save();

        return $key->user;
    }

    /** PIN 6 digit yang tidak mudah ditebak (bukan angka kembar / berurutan). */
    public static function weakPin(string $pin): bool
    {
        return preg_match('/^(\d)\1{5}$/', $pin) === 1
            || str_contains('0123456789', $pin) || str_contains('9876543210', $pin);
    }
}
