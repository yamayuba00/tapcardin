<?php

namespace App\Http\Controllers\Workspace;

use App\Exceptions\ApiError;
use App\Models\Addon;
use App\Models\AddonInterest;
use App\Models\ProductEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Add-on berbayar di luar paket (integrasi CRM per alat). Selama "segera hadir": daftar / batal daftar minat per workspace
 * (izin `billing.view`). Webhook sudah tersedia sebagai fitur sendiri (WebhookController).
 */
class AddonController extends WorkspaceController
{
    /** GET /api/addons?group=crm */
    public function index(Request $request): JsonResponse
    {
        $group = $request->query('group');
        $this->access()->authorize('billing.view');
        $mine = AddonInterest::where('tenant_id', $this->tenant()->id)->pluck('created_at', 'addon_id');

        return response()->json(['data' => Addon::where('status', '!=', 'retired')
            ->when($group, fn ($q) => $q->where('group', $group), fn ($q) => $q->where('group', '!=', 'webhook'))->orderBy('sort')->get()
            ->map(fn (Addon $a) => self::present($a) + ['interested_at' => $mine->get($a->id)?->toIso8601String()])]);
    }

    /** POST /api/addons/{key}/interest {note?} — "Beri tahu saya" saat add-on tersedia. Idempoten. */
    public function interest(Request $request, Addon $addon): JsonResponse
    {
        $this->access()->authorize('billing.view');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);
        if ($addon->status !== 'coming_soon') {
            throw new ApiError('addon_not_coming_soon', 'Add-on ini tidak dalam masa pendaftaran minat.');
        }
        $i = AddonInterest::firstOrCreate(['addon_id' => $addon->id, 'tenant_id' => $this->tenant()->id],
            ['user_id' => $this->user()->id, 'note' => $data['note'] ?? null]);
        if ($i->wasRecentlyCreated) {
            ProductEvent::record('addon_interest', $this->tenant()->id, $this->user()->id, ['addon' => $addon->key]);
        }

        return response()->json(['data' => self::present($addon) + ['interested_at' => $i->created_at->toIso8601String()]]);
    }

    public function withdraw(Addon $addon): JsonResponse
    {
        $this->access()->authorize('billing.view');
        AddonInterest::where(['addon_id' => $addon->id, 'tenant_id' => $this->tenant()->id])->delete();

        return response()->json(['data' => self::present($addon) + ['interested_at' => null]]);
    }

    public static function present(Addon $a): array
    {
        return ['id' => $a->public_id, 'key' => $a->key, 'group' => $a->group, 'name' => $a->name, 'description' => $a->description,
            'features' => $a->features ?? [], 'price_monthly' => $a->price_monthly, 'status' => $a->status];
    }
}
