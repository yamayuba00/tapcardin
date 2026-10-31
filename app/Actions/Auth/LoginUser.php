<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Masuk: Google (temukan/buat akun dari email terverifikasi Google) atau akun yang sudah dicek password-nya.
 * "Ingat saya" = cookie login berlaku `tapcard.remember_minutes` (7 hari), bukan bawaan Laravel (400 hari).
 */
final class LoginUser
{
    public function __construct(private readonly RegisterUser $register) {}

    public function byEmail(string $email, ?string $name = null, ?string $googleId = null, bool $remember = true): User
    {
        $user = User::whereRaw('lower(email) = ?', [strtolower($email)])->first();
        if ($googleId) {
            $user = User::where('google_id', $googleId)->first() ?? $user;
        }

        if ($user === null) {
            $user = $this->register->handle($email, $name, $googleId);
        } elseif ($googleId && $user->google_id === null) {
            // email Google sama dengan akun OTP lama → tautkan
            $user->forceFill(['google_id' => $googleId])->save();
        }

        return $this->login($user, $remember);
    }

    public function login(User $user, bool $remember): User
    {
        if ($user->isBlocked()) {
            throw ValidationException::withMessages(['email' => 'Akun ini diblokir. Hubungi support TapCard.']);
        }

        $guard = Auth::guard('web');
        $guard->setRememberDuration((int) config('tapcard.remember_minutes'));
        $guard->login($user, $remember);
        request()->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }
}
