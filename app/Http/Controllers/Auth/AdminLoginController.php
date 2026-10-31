<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Audit;
use App\Support\Hasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Login panel pemilik (admin.) dengan email + password. Hanya staf aktif.
 * Sesi tetap terpisah (cookie admin) dan wajib login ulang setiap 12 jam (TRD §5.4).
 */
class AdminLoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        $email = strtolower(trim($data['email']));
        $key = 'admin-login:'.$email.'|'.Hasher::ip($request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi dalam '.ceil(RateLimiter::availableIn($key) / 60).' menit.',
            ])->status(429);
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        $staff = $user ? PlatformStaff::where('user_id', $user->id)->where('status', 'active')->first() : null;

        // Pesan sama untuk email tak dikenal, bukan staf, atau password salah — jangan bocorkan siapa staf.
        if (! $staff || ! $user->password || ! Hash::check($data['password'], $user->password) || $user->isBlocked()) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        $staff->update(['last_reverified_at' => now()]);
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::platform('staff.logged_in', $staff);

        return response()->json(['data' => ['user' => ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email]]]);
    }

    /** PUT /api/me/password — staf mengganti password sendiri. */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'confirmed', 'regex:/[A-Za-z]/', 'regex:/\d/'],
        ], [
            'password.confirmed' => 'Konfirmasi password tidak sama.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.regex' => 'Password harus berisi huruf dan angka.',
        ]);
        if (! $user->password || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Password saat ini salah.']);
        }

        $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();
        Audit::platform('staff.password_changed', $user);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['ok' => true]]);
    }
}
