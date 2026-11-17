<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\HostTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * TRD §5.1 — login Google via Socialite. Callback selalu di app., lalu kembali ke path SPA asal.
 * `?link=1` saat sudah masuk (mis. lewat kartu NFC): hubungkan akun Google ke akun ini, bukan login baru.
 * Dari subdomain / domain kustom perusahaan: login lewat app., lalu kembali ke host asal dengan tiket sekali pakai.
 */
class GoogleController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(config('services.google.client_id'), 404);
        $host = $request->getHost();
        if (! $request->boolean('link') && HostTenant::resolve($host)) {
            // callback Google terdaftar di app.; sesi host perusahaan terpisah → mulai dari app. dengan host asal
            return redirect()->away($this->hostUrl($request, 'app.'.config('tapcard.base_domain'))
                .'/auth/google/redirect?'.http_build_query(['redirect' => $this->safePath($request->query('redirect')), 'origin' => $host]));
        }
        $origin = strtolower((string) $request->query('origin'));
        $request->session()->put('auth.origin', $origin && HostTenant::resolve($origin) ? $origin : null);
        $request->session()->put('auth.redirect', $this->safePath($request->query('redirect')));
        $request->session()->put('auth.link', $request->boolean('link') && Auth::check() ? Auth::id() : null);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, LoginUser $login): RedirectResponse
    {
        $linkUserId = $request->session()->pull('auth.link');
        $origin = $request->session()->pull('auth.origin');
        $origin = $origin && HostTenant::resolve($origin) ? $this->hostUrl($request, $origin) : '';
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect($linkUserId ? '/settings?google=failed' : $origin.'/login?error=google');
        }
        if (! ($google->user['email_verified'] ?? true)) {
            return redirect($linkUserId ? '/settings?google=unverified' : $origin.'/login?error=google_unverified');
        }

        if ($linkUserId && Auth::id() === $linkUserId) {
            return $this->link(Auth::user(), (string) $google->getId());
        }

        $user = $login->byEmail($google->getEmail(), $google->getName(), (string) $google->getId());
        $path = $request->session()->pull('auth.redirect', '/dashboard');
        if ($origin) {
            $ticket = Str::random(48);
            Cache::put(self::ticketKey($ticket), ['user' => $user->id, 'host' => parse_url($origin, PHP_URL_HOST), 'path' => $path], self::TICKET_TTL);

            return redirect()->away($origin.'/auth/handoff?ticket='.$ticket);
        }

        return redirect($path);
    }

    /** GET /auth/handoff?ticket= — di host perusahaan: tukar tiket (sekali pakai, 60 detik) dengan sesi di host ini. */
    public function handoff(Request $request, LoginUser $login): RedirectResponse
    {
        $data = Cache::pull(self::ticketKey((string) $request->query('ticket')));
        $user = $data && $data['host'] === strtolower($request->getHost()) ? User::find($data['user']) : null;
        if (! $user || $user->isBlocked()) {
            return redirect('/login?error=handoff');
        }
        $login->login($user, true);

        return redirect($this->safePath($data['path']));
    }

    public const TICKET_TTL = 60;

    public static function ticketKey(string $ticket): string
    {
        return 'auth:handoff:'.hash('sha256', $ticket);
    }

    /** URL host dengan skema & port permintaan ini (port non-standar dipertahankan untuk lokal). */
    private function hostUrl(Request $request, string $host): string
    {
        $port = $request->getPort();

        return $request->getScheme().'://'.$host.($port && ! in_array($port, [80, 443], true) ? ':'.$port : '');
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
