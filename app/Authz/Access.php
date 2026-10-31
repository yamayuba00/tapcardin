<?php

namespace App\Authz;

use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Izin efektif pengguna di workspace aktif (scoped per request).
 * Mendukung wildcard cakupan: `leads.view.*` cocok dengan own/team/event/all.
 */
final class Access
{
    /** @var array<string, array> dikunci per pengguna+tenant agar tidak bocor antar-request di proses yang berumur panjang */
    private array $ctx = [];

    public function __construct(private readonly CurrentTenant $tenant) {}

    /** @return array{perms: array<string,true>, team_ids: int[], managed_team_ids: int[], role: ?string} */
    public function context(?User $user = null): array
    {
        $user ??= auth()->user();
        if ($user === null || $this->tenant->get() === null) {
            return ['perms' => [], 'team_ids' => [], 'managed_team_ids' => [], 'role' => null];
        }

        $tenant = $this->tenant->get();

        return $this->ctx[$user->id.':'.$tenant->id] ??= app(PermissionResolver::class)->for($user, $tenant);
    }

    public function has(string $permission): bool
    {
        $perms = $this->context()['perms'];
        if (str_ends_with($permission, '.*')) {
            $prefix = substr($permission, 0, -1);
            foreach ($perms as $key => $_) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        return isset($perms[$permission]);
    }

    public function hasAny(string ...$permissions): bool
    {
        foreach ($permissions as $p) {
            if ($this->has($p)) {
                return true;
            }
        }

        return false;
    }

    public function authorize(string ...$permissions): void
    {
        if (! $this->hasAny(...$permissions)) {
            throw new AuthorizationException('Anda tidak punya izin untuk aksi ini.');
        }
    }

    /** @return list<string> */
    public function list(): array
    {
        return array_keys($this->context()['perms']);
    }

    public function reset(): void
    {
        $this->ctx = [];
    }
}
