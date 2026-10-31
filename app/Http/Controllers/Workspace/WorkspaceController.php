<?php

namespace App\Http\Controllers\Workspace;

use App\Authz\Access;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

abstract class WorkspaceController extends Controller
{
    protected function tenant(): Tenant
    {
        return app(CurrentTenant::class)->require();
    }

    protected function access(): Access
    {
        return app(Access::class);
    }

    protected function user(): User
    {
        return auth()->user();
    }
}
