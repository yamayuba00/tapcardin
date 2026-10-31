<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/** TRD §5.1 — login Google via Socialite. Callback selalu di app., lalu kembali ke path SPA asal. */
class GoogleController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(config('services.google.client_id'), 404);
        $request->session()->put('auth.redirect', $this->safePath($request->query('redirect')));

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, LoginUser $login): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect('/login?error=google');
        }
        if (! ($google->user['email_verified'] ?? true)) {
            return redirect('/login?error=google_unverified');
        }

        $login->byEmail($google->getEmail(), $google->getName(), (string) $google->getId());

        return redirect($request->session()->pull('auth.redirect', '/dashboard'));
    }

    /** Hanya path relatif yang diizinkan (cegah open redirect). */
    private function safePath(?string $path): string
    {
        return $path && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : '/dashboard';
    }
}
