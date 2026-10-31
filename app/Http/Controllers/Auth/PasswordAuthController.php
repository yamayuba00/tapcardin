<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginUser;
use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\RequestOtp;
use App\Actions\Auth\VerifyOtp;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Altcha;
use App\Support\Hasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login pengguna workspace (app. & {slug}.): email + password (atau Google, lihat GoogleController).
 * Kode email hanya dikirim saat daftar manual (verifikasi kepemilikan email) dan lupa password.
 * Semua formulir publik dilindungi ALTCHA; login dibatasi 5 percobaan / 15 menit per email+IP.
 */
class PasswordAuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /** Aturan password: sama dengan panel (min. 8, huruf + angka). */
    public const PASSWORD_RULES = ['required', 'string', 'min:8', 'max:200', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/\d/'];

    public const PASSWORD_MESSAGES = [
        'password.confirmed' => 'Konfirmasi password tidak sama.',
        'password.min' => 'Password minimal 8 karakter.',
        'password.regex' => 'Password harus berisi huruf dan angka.',
    ];

    /** GET /api/auth/altcha — challenge baru untuk widget (tidak boleh di-cache). */
    public function altcha(): JsonResponse
    {
        return response()->json(Altcha::challenge())->header('Cache-Control', 'no-store');
    }

    public function login(Request $request, LoginUser $login): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['boolean'],
        ]);
        $email = strtolower(trim($data['email']));
        $key = 'login:'.$email.'|'.Hasher::ip($request->ip());
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi dalam '.ceil(RateLimiter::availableIn($key) / 60).' menit.',
            ])->status(429);
        }
        Altcha::require($request);

        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if ($user && ! $user->password) {
            // akun lama (kode email) atau akun Google: belum pernah membuat password
            throw ValidationException::withMessages(['email' => $user->google_id
                ? 'Akun ini terdaftar lewat Google. Masuk dengan Google, atau buat password lewat "Lupa password".'
                : 'Akun ini belum punya password. Buat password lewat "Lupa password".']);
        }
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        RateLimiter::clear($key);
        $login->login($user, (bool) ($data['remember'] ?? false));

        return $this->userResponse($user);
    }

    /** POST /api/auth/register — simpan pendaftaran tertunda & kirim kode verifikasi ke email. */
    public function register(Request $request, RequestOtp $otp): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => self::PASSWORD_RULES,
        ], self::PASSWORD_MESSAGES);
        Altcha::require($request);
        $email = strtolower(trim($data['email']));
        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'Email ini sudah terdaftar. Silakan masuk, atau gunakan "Lupa password".']);
        }

        $otp->handle($email, (string) $request->ip(), 'register', ['name' => trim($data['name']), 'password_hash' => Hash::make($data['password'])]);

        return response()->json(['data' => ['sent' => true, 'email' => $email]]);
    }

    /** POST /api/auth/register/verify — kode cocok → akun dibuat (workspace personal + kartu digital) & langsung masuk. */
    public function verifyRegistration(Request $request, VerifyOtp $verify, RegisterUser $register, LoginUser $login): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190'], 'code' => ['required', 'string', 'max:12'], 'remember' => ['boolean']]);
        $otp = $verify->handle($data['email'], $data['code'], 'register');
        if (User::whereRaw('lower(email) = ?', [$otp->email])->exists()) {
            throw ValidationException::withMessages(['email' => 'Email ini sudah terdaftar. Silakan masuk.']);
        }
        $user = $register->handle($otp->email, $otp->payload['name'] ?? null, null, $otp->payload['password_hash'] ?? null);
        $login->login($user, (bool) ($data['remember'] ?? false));

        return $this->userResponse($user, 201);
    }

    /** POST /api/auth/password/forgot — selalu jawaban sama (tidak membocorkan email mana yang terdaftar). */
    public function forgot(Request $request, RequestOtp $otp): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        Altcha::require($request);
        $email = strtolower(trim($data['email']));
        $user = User::whereRaw('lower(email) = ?', [$email])->whereNull('blocked_at')->first();
        if ($user) {
            $otp->handle($email, (string) $request->ip(), 'reset');
        }

        return response()->json(['data' => ['sent' => true, 'email' => $email]]);
    }

    /** POST /api/auth/password/reset — kode + password baru; sesi lain di perangkat lain dikeluarkan. */
    public function reset(Request $request, VerifyOtp $verify, LoginUser $login): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'code' => ['required', 'string', 'max:12'],
            'password' => self::PASSWORD_RULES,
            'remember' => ['boolean'],
        ], self::PASSWORD_MESSAGES);
        $otp = $verify->handle($data['email'], $data['code'], 'reset');
        $user = User::whereRaw('lower(email) = ?', [$otp->email])->firstOrFail();

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => $data['password'], 'password_changed_at' => now(), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
        $login->login($user, (bool) ($data['remember'] ?? false));

        return $this->userResponse($user);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function userResponse(User $user, int $status = 200): JsonResponse
    {
        return response()->json(['data' => ['user' => ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email]]], $status);
    }
}
