<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CardLogin;
use App\Actions\Auth\LoginUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Altcha;
use Database\Seeders\DummyAccountSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Login pengguna workspace (app. & {slug}.): Google (lihat GoogleController) atau kartu NFC + PIN.
 * Tidak ada email + password untuk pengguna; akun baru dibuat otomatis saat pertama masuk dengan Google.
 */
class AuthController extends Controller
{
    /** GET /api/auth/altcha — challenge baru untuk widget (tidak boleh di-cache). */
    public function altcha(): JsonResponse
    {
        return response()->json(Altcha::challenge())->header('Cache-Control', 'no-store');
    }

    /** POST /api/auth/card — token & UID dari chip (Web NFC) + PIN, dilindungi ALTCHA. */
    public function card(Request $request, CardLogin $card, LoginUser $login): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[0-9A-Za-z]{10}$/'],
            'uid' => ['required', 'string', 'max:40'],
            'pin' => ['required', 'string', 'regex:/^\d{6}$/'],
            'remember' => ['boolean'],
        ], ['token.regex' => 'Kartu ini bukan kartu TapCard.', 'pin.regex' => 'PIN terdiri dari 6 angka.']);
        Altcha::require($request);

        $user = $card->handle($data['token'], $data['uid'], $data['pin']);
        $login->login($user, (bool) ($data['remember'] ?? false));

        return $this->userResponse($user);
    }

    /** POST /api/auth/dev — hanya APP_ENV=local + DEV_LOGIN_CODE: masuk sebagai akun dummy tanpa Google. */
    public function dev(Request $request, LoginUser $login): JsonResponse
    {
        abort_unless(self::devLogin(), 404);
        $email = (string) $request->validate(['email' => ['required', 'email']])['email'];
        $user = array_key_exists($email, DummyAccountSeeder::ACCOUNTS) ? User::where('email', $email)->first() : null;
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'Akun dummy tidak ditemukan. Jalankan db:seed.']);
        }
        $login->login($user, true);

        return $this->userResponse($user);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Login dummy hanya aktif saat APP_ENV=local dan DEV_LOGIN_CODE berisi 6 digit. */
    public static function devLogin(): bool
    {
        return app()->isLocal() && preg_match('/^\d{6}$/', (string) config('tapcard.dev_login_code')) === 1;
    }

    private function userResponse(User $user): JsonResponse
    {
        return response()->json(['data' => ['user' => ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email]]]);
    }
}
