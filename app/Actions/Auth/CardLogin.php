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
 * Masuk dengan kartu NFC = dua faktor: bukti memegang kartu fisik + kode akun 6 angka milik pemegang.
 * Bukti kartu salah satu dari:
 *  - UID chip yang dibaca Web NFC (Chrome Android), dicocokkan dengan UID yang diikat saat encode;
 *  - kunci chip (?k=) dari URL di chip — terbawa saat kartu di-tap di HP mana pun, termasuk iPhone.
 *
 * Token kartu bersifat publik (tercetak di QR), jadi bukti kartu wajib cocok sebelum kode diperiksa:
 * penyerang tanpa kartu di tangan tidak bisa menebak kode maupun mengunci kunci orang lain. Semua
 * kegagalan memakai pesan yang sama agar tidak membocorkan kartu mana yang punya kunci login.
 */
final class CardLogin
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    private const GENERIC = 'Kartu atau kode akun salah.';

    /** Status kartu yang masih boleh dipakai login (hilang/dicabut tidak). */
    private const USABLE = [CardStatus::Active, CardStatus::Paused];

    public function handle(string $token, ?string $uid, ?string $key, string $code): User
    {
        $card = Card::acrossTenants()->where('card_token', $token)->first();
        $login = $card ? CardLoginKey::where('card_id', $card->id)->first() : null;

        if (! $login || ! in_array($card->status, self::USABLE, true) || $login->user_id !== $card->holder_user_id
            || ! self::holdsCard($card, $uid, $key)) {
            throw ValidationException::withMessages(['code' => self::GENERIC]);
        }

        if ($login->locked_until?->isFuture()) {
            throw ValidationException::withMessages([
                'code' => 'Terlalu banyak kode salah. Coba lagi dalam '.max(1, (int) ceil(now()->diffInSeconds($login->locked_until) / 60)).' menit.',
            ])->status(423);
        }

        if (! Hash::check($code, $login->pin_hash)) {
            $attempts = $login->failed_attempts + 1;
            $login->forceFill($attempts >= self::MAX_ATTEMPTS
                ? ['failed_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)]
                : ['failed_attempts' => $attempts])->save();
            throw ValidationException::withMessages(['code' => self::GENERIC]);
        }

        $login->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_used_at' => now()])->save();

        return $login->user;
    }

    private static function holdsCard(Card $card, ?string $uid, ?string $key): bool
    {
        if ($card->isDigital()) {
            return false;
        }
        if ($key !== null && $key !== '' && hash_equals($card->chipKey(), $key)) {
            return true;
        }
        $uid = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $uid));

        return $uid !== '' && $card->chip_uid_hash !== null && hash_equals($card->chip_uid_hash, Hasher::uid($uid));
    }

    /** Kode 6 angka yang tidak mudah ditebak (bukan angka kembar / berurutan). */
    public static function weakCode(string $code): bool
    {
        return preg_match('/^(\d)\1{5}$/', $code) === 1
            || str_contains('0123456789', $code) || str_contains('9876543210', $code);
    }

    /** Kode akun acak untuk aktivasi di tempat (pemegang bisa menggantinya nanti). */
    public static function randomCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::weakCode($code));

        return $code;
    }

    /** Pasang / ganti kode akun kartu untuk pemegangnya (kunci lama kartu ini gugur). */
    public static function setCode(Card $card, User $user, string $code): CardLoginKey
    {
        CardLoginKey::where('card_id', $card->id)->delete();

        return CardLoginKey::create(['card_id' => $card->id, 'user_id' => $user->id, 'pin_hash' => Hash::make($code)]);
    }
}
