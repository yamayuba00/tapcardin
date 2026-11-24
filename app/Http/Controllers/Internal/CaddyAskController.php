<?php

namespace App\Http\Controllers\Internal;

use App\Authz\PlanFeatures;
use App\Enums\TenantType;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caddy (on-demand TLS) bertanya di sini sebelum menerbitkan sertifikat: 200 = boleh, 404 = tidak.
 * Hanya dapat dipanggil dari loopback tanpa X-Forwarded-For, supaya klien luar tidak bisa memicu penerbitan.
 */
class CaddyAskController extends Controller
{
    private const RESERVED = ['tap', 'app', 'panel', 'status', 'wbh', 'www', 'ws'];

    public function __invoke(Request $request): Response
    {
        $remote = (string) $request->server->get('REMOTE_ADDR');
        abort_unless(in_array($remote, ['127.0.0.1', '::1'], true) && ! $request->headers->has('X-Forwarded-For'), 404);

        $host = strtolower(trim((string) $request->query('domain', '')));
        $allowed = Cache::remember('caddy-ask:'.$host, 60, fn () => self::allowed($host));

        return response('', $allowed ? 200 : 404)->header('Cache-Control', 'no-store');
    }

    private static function allowed(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        $base = strtolower((string) config('tapcard.base_domain'));
        if ($host === $base) {
            return true;
        }
        if (! str_ends_with($host, '.'.$base)) {
            return self::activeCustomDomain($host);
        }

        $sub = substr($host, 0, -strlen('.'.$base));
        if (str_contains($sub, '.')) {
            return self::activeCustomDomain($host); // sub-sub-domain: hanya lewat domain kustom terdaftar
        }
        if (in_array($sub, [...self::RESERVED, (string) config('tapcard.panel_subdomain')], true)) {
            return true;
        }

        $tenant = Tenant::where('slug', $sub)->where('type', '!=', TenantType::Personal->value)->first();

        return $tenant !== null && ! $tenant->isSuspended();
    }

    private static function activeCustomDomain(string $host): bool
    {
        $domain = TenantDomain::where('domain', $host)->where('status', 'active')->first();
        $tenant = $domain ? Tenant::find($domain->tenant_id) : null;

        return $tenant !== null && ! $tenant->isSuspended() && PlanFeatures::has($tenant, 'custom_domain');
    }
}
