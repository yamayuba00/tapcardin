<?php

namespace Database\Seeders;

use App\Authz\Catalog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class AuthzSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['tenant' => Catalog::TENANT, 'platform' => Catalog::PLATFORM] as $scope => $catalog) {
            foreach ($catalog as $key => $def) {
                Permission::updateOrCreate(['key' => $key], [
                    'scope' => $scope,
                    'area' => $def[0],
                    'label' => $def[1],
                    'plan_feature' => $def[2] ?? null,
                ]);
            }
        }

        foreach (Catalog::systemRoles() as $key => $def) {
            $role = Role::updateOrCreate(
                ['key' => $key, 'is_system' => true],
                ['scope' => $def['scope'], 'name' => $def['name'], 'description' => $def['description']],
            );
            RolePermission::where('role_id', $role->id)->delete();
            RolePermission::insert(array_map(
                fn ($p) => ['role_id' => $role->id, 'permission_key' => $p],
                $def['permissions'],
            ));
        }

        Cache::forget('authz:feature-map');
    }
}
