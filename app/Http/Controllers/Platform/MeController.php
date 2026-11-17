<?php

namespace App\Http\Controllers\Platform;

use App\Authz\PlatformAccess;
use Illuminate\Http\JsonResponse;

/** GET /api/me di admin. — staf yang login, peran, dan izin platform (untuk menyusun menu). */
class MeController extends PlatformController
{
    public function __invoke(PlatformAccess $access): JsonResponse
    {
        $staff = auth('staff')->user()->load('role');

        return $this->ok([
            'id' => $staff->public_id,
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => $staff->role->name,
            'permissions' => $access->list(),
        ]);
    }
}
