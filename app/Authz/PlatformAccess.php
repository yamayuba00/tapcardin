<?php

namespace App\Authz;

use Illuminate\Auth\Access\AuthorizationException;

/** Izin staf platform (panel pemilik). Staf tidak otomatis mendapat akses ke data workspace. */
final class PlatformAccess
{
    /** @var array<int, array<string,true>> per pengguna */
    private array $perms = [];

    /** @return array<string,true> */
    public function perms(): array
    {
        $user = auth()->user();

        return $user ? ($this->perms[$user->id] ??= app(PermissionResolver::class)->forStaff($user)) : [];
    }

    public function has(string $permission): bool
    {
        return isset($this->perms()['platform.'.$permission]) || isset($this->perms()[$permission]);
    }

    public function authorize(string $permission): void
    {
        if (! $this->has($permission)) {
            throw new AuthorizationException('Peran Anda tidak mengizinkan aksi ini.');
        }
    }

    /** @return list<string> tanpa prefix `platform.` */
    public function list(): array
    {
        return array_map(fn ($k) => substr($k, 9), array_keys($this->perms()));
    }
}
