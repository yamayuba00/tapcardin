<?php

namespace App\Http\Controllers\Platform;

use App\Authz\PlatformAccess;
use App\Models\PlatformStaff;
use Illuminate\Http\JsonResponse;

/** GET /api/me di admin. — staf yang login, peran, dan izin platform (untuk menyusun menu). */
class MeController extends PlatformController
{
    public function __invoke(PlatformAccess $access): JsonResponse
    {
        $user = auth()->user();
        $staff = PlatformStaff::with('role')->where('user_id', $user->id)->first();

        return $this->ok([
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $staff->role->name,
            'permissions' => $access->list(),
        ]);
    }
}
