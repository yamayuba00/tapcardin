<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\StaffAccount;
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
 * Hanya akun staff_accounts (broker `staff`); pengguna workspace masuk dengan Google / kartu NFC.
 */
class StaffPasswordResetController extends Controller
{
    public const SENT = 'Bila email itu terdaftar sebagai staf aktif, tautan untuk membuat password baru sudah kami kirim. Periksa juga folder Spam.';

    /** POST /api/auth/forgot-password {email} */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $staff = self::staff($data['email']);
        $broker = Password::broker('staff');

        // satu email per 60 detik per akun (throttle broker) — sisanya diam-diam diabaikan
        if ($staff && ! $broker->getRepository()->recentlyCreatedToken($staff)) {
            $token = $broker->createToken($staff);
            $url = $request->getSchemeAndHttpHost().'/reset-password?'.http_build_query(['token' => $token, 'email' => $staff->email]);
            Notify::email($staff->email, $staff->name, 'password_reset', ['url' => $url, 'minutes' => config('auth.passwords.staff.expire')], now: true);
            Audit::platform('staff.password_reset_requested', $staff);
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
        $staff = self::staff($data['email']) ?? throw $invalid();

        $broker = Password::broker('staff');
        if (! $broker->tokenExists($staff, $data['token'])) {
            throw $invalid();
        }
        DB::transaction(function () use ($staff, $data, $broker) {
            $staff->forceFill(['password' => $data['password'], 'password_changed_at' => now(), 'remember_token' => null])->save();
            $broker->deleteToken($staff);
            DB::table('staff_sessions')->where('user_id', $staff->id)->delete(); // sesi lama (mungkin milik orang lain) berakhir
        });
        Audit::platform('staff.password_reset_done', $staff);

        return response()->json(['data' => ['message' => 'Password baru tersimpan. Silakan masuk.']]);
    }

    /** Hanya staf aktif. */
    private static function staff(string $email): ?StaffAccount
    {
        return StaffAccount::whereRaw('lower(email) = ?', [strtolower(trim($email))])->where('status', 'active')->first();
    }
}
