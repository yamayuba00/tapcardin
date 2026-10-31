<?php

namespace App\Providers;

use App\Authz\Access;
use App\Authz\PlatformAccess;
use App\Support\Crockford;
use App\Support\Hasher;
use App\Tenancy\CurrentTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Satu instance per request (aman untuk Octane/queue worker yang berumur panjang).
        $this->app->scoped(CurrentTenant::class);
        $this->app->scoped(Access::class);
        $this->app->scoped(PlatformAccess::class);
    }

    public function boot(): void
    {
        // TRD §11 — batas laju
        $ip = fn (Request $r) => Hasher::ip($r->ip());
        RateLimiter::for('tap', fn (Request $r) => Limit::perMinute(60)->by($ip($r).'|'.$r->route('token')));
        RateLimiter::for('vcard', fn (Request $r) => Limit::perMinute(30)->by($ip($r)));
        RateLimiter::for('connect', fn (Request $r) => Limit::perMinutes(10, 5)->by($ip($r)));
        RateLimiter::for('shortcode', fn (Request $r) => Limit::perMinute(20)->by($ip($r)));
        RateLimiter::for('activate', fn (Request $r) => [
            // dinormalisasi agar variasi penulisan kode pendek tidak menghindari batas
            Limit::perHour(5)->by('card:'.($r->input('token') ?? Crockford::normalize((string) $r->input('short_code')))),
            Limit::perHour(20)->by($ip($r)),
        ]);
    }
}
