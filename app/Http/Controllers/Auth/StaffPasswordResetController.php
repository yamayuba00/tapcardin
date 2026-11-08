<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Lupa password staf panel: tautan reset lewat email (token broker Laravel, berlaku 60 menit, sekali pakai).
 * Jawaban permintaan selalu sama — tidak membocorkan email mana yang terdaftar sebagai staf.
 * Pengguna workspace tidak punya password (Google / kartu NFC), jadi hanya berlaku di panel.
 */
class StaffPasswordResetController extends Controller
{
    public const SENT = 'Bila email itu terdaftar sebagai staf aktif, tautan untuk membuat password baru sudah kami kirim. Periksa juga folder Spam.';

    /** POST /api/auth/forgot-password {email} */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $user = self::staff($data['email']);
        $broker = Password::broker();

        // satu email per 60 detik per akun (throttle broker) — sisanya diam-diam diabaikan
        if ($user && ! $broker->getRepository()->recentlyCreatedToken($user)) {
            $token = $broker->createToken($user);
            $url = $request->getSchemeAndHttpHost().'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
            Notify::email($user->email, $user->name, 'password_reset', ['url' => $url, 'minutes' => config('auth.passwords.users.expire')], now: true);
            Audit::platform('staff.password_reset_requested', $user);
        }

        return response()->json(['data' => ['message' => self::SENT]]);
    }

    /** POST /api/auth/reset-password {email, token, password, password_confirmation} */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/\d/'],
        ], [
            'password.confirmed' => 'Konfirmasi password tidak sama.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.regex' => 'Password harus berisi huruf dan angka.',
        ]);
        $invalid = fn () => ValidationException::withMessages(['token' => 'Tautan reset tidak berlaku atau sudah kedaluwarsa. Minta tautan baru.']);
        $user = self::staff($data['email']) ?? throw $invalid();

        $broker = Password::broker();
        if (! $broker->tokenExists($user, $data['token'])) {
            throw $invalid();
        }
        DB::transaction(function () use ($user, $data, $broker) {
            $user->forceFill(['password' => $data['password'], 'password_changed_at' => now(), 'remember_token' => null])->save();
            $broker->deleteToken($user);
            DB::table('sessions')->where('user_id', $user->id)->delete(); // sesi lama (mungkin milik orang lain) berakhir
        });
        Audit::platform('staff.password_reset_done', $user);

        return response()->json(['data' => ['message' => 'Password baru tersimpan. Silakan masuk.']]);
    }

    /** Hanya staf aktif yang tidak diblokir. */
    private static function staff(string $email): ?User
    {
        $user = User::whereRaw('lower(email) = ?', [strtolower(trim($email))])->first();

        return $user && ! $user->isBlocked() && PlatformStaff::where('user_id', $user->id)->where('status', 'active')->exists() ? $user : null;
    }
}
