<?php

namespace App\Http\Controllers\Platform;

use App\Authz\PlatformAccess;
use App\Console\Commands\Health;
use App\Models\PlatformSetting;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PL-16 — kesehatan sistem (ambang Redis TRD §3.2) & pengaturan umum. */
class SystemController extends PlatformController
{
    public function show(PlatformAccess $access): JsonResponse
    {
        $this->can('dashboard.ops');

        return $this->ok([
            'health' => Health::collect(),
            'settings' => [
                'brand_name' => PlatformSetting::get('brand.name', 'TapCard'),
                'support_email' => PlatformSetting::get('support.email', ''),
                'support_whatsapp' => PlatformSetting::get('support.whatsapp', ''),
            ],
            'can_manage' => $access->has('system.manage'),
        ]);
    }

    public function settings(Request $request, PlatformAccess $access): JsonResponse
    {
        $this->can('system.manage');
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:60'],
            'support_email' => ['nullable', 'email'],
            'support_whatsapp' => ['nullable', 'string', 'max:32'],
        ]);
        foreach (['brand.name' => 'brand_name', 'support.email' => 'support_email', 'support.whatsapp' => 'support_whatsapp'] as $key => $field) {
            PlatformSetting::put($key, $data[$field] ?? null, auth()->id());
        }
        Audit::platform('system.settings_updated', PlatformSetting::find('brand.name'), null, $data);

        return $this->show($access);
    }
}
