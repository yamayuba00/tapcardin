<?php

namespace App\Support;

use App\Enums\TenantType;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** Host workspace perusahaan: `{slug}.{base}` atau domain kustom aktif → tenant (null untuk host lain). */
final class HostTenant
{
    private const SYSTEM = ['app', 'tap', 'panel', 'admin', 'status', 'www', 'ws'];

    public static function resolve(string $host): ?Tenant
    {
        $host = strtolower($host);
        $base = config('tapcard.base_domain');
        if ($host === $base) {
            return null;
        }
        if (str_ends_with($host, '.'.$base)) {
            $slug = substr($host, 0, -strlen('.'.$base));
            if (str_contains($slug, '.') || in_array($slug, [...self::SYSTEM, config('tapcard.panel_subdomain')], true)) {
                return null;
            }
            $id = Cache::remember("tenant:slug:$slug", 300, fn () => Tenant::where('slug', $slug)
                ->where('type', '!=', TenantType::Personal->value)->value('id'));
        } else {
            $id = Cache::remember("tenant:domain:$host", 300, fn () => TenantDomain::where('domain', $host)->where('status', 'active')->value('tenant_id') ?? 0);
        }

        return $id ? Tenant::find($id) : null;
    }

    /** Merek publik minimal untuk halaman login (tanpa data lain). */
    public static function brand(Tenant $tenant): array
    {
        return ['name' => $tenant->name, 'logo' => $tenant->logo_path ? Storage::disk('public')->url($tenant->logo_path) : null];
    }
}
