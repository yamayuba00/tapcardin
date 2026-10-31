<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Temukan atau buat akun dari email terverifikasi, lalu masuk (AFD F-01). */
final class LoginUser
{
    public function __construct(private readonly RegisterUser $register) {}

    public function byEmail(string $email, ?string $name = null, ?string $googleId = null): User
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

        if ($user->isBlocked()) {
            throw ValidationException::withMessages(['email' => 'Akun ini diblokir. Hubungi support TapCard.']);
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }
}
