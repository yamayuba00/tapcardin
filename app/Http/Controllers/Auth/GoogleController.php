<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * TRD §5.1 — login Google via Socialite. Callback selalu di app., lalu kembali ke path SPA asal.
 * `?link=1` saat sudah masuk (mis. lewat kartu NFC): hubungkan akun Google ke akun ini, bukan login baru.
 */
class GoogleController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(config('services.google.client_id'), 404);
        $request->session()->put('auth.redirect', $this->safePath($request->query('redirect')));
        $request->session()->put('auth.link', $request->boolean('link') && Auth::check() ? Auth::id() : null);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, LoginUser $login): RedirectResponse
    {
        $linkUserId = $request->session()->pull('auth.link');
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect($linkUserId ? '/settings?google=failed' : '/login?error=google');
        }
        if (! ($google->user['email_verified'] ?? true)) {
            return redirect($linkUserId ? '/settings?google=unverified' : '/login?error=google_unverified');
        }

        if ($linkUserId && Auth::id() === $linkUserId) {
            return $this->link(Auth::user(), (string) $google->getId());
        }

        $login->byEmail($google->getEmail(), $google->getName(), (string) $google->getId());

        return redirect($request->session()->pull('auth.redirect', '/dashboard'));
    }

    /** Akun Google yang sama tidak boleh tertaut ke dua akun TapCard. */
    private function link(User $user, string $googleId): RedirectResponse
    {
        if (User::where('google_id', $googleId)->whereKeyNot($user->id)->exists()) {
            return redirect('/settings?google=taken');
        }
        $user->forceFill(['google_id' => $googleId])->save();
        Audit::platform('user.google_linked', $user);

        return redirect('/settings?google=linked');
    }

    /** Hanya path relatif yang diizinkan (cegah open redirect). */
    private function safePath(?string $path): string
    {
        return $path && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : '/dashboard';
    }
}
