<?php

namespace App\Http\Controllers\Workspace;

use App\Exceptions\ApiError;
use App\Models\Addon;
use App\Models\AddonInterest;
use App\Models\ProductEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Add-on berbayar di luar paket (integrasi CRM per alat) & fitur segera hadir (Webhook). Selama "segera hadir":
 * daftar / batal daftar minat per workspace. Webhook: izin `integrations.manage` / `workspace.settings`, sisanya `billing.view`.
 */
class AddonController extends WorkspaceController
{
    /** GET /api/addons?group=webhook — tanpa `group`: semua kecuali webhook (punya halamannya sendiri). */
    public function index(Request $request): JsonResponse
    {
        $group = $request->query('group');
        $this->authorizeGroup($group === 'webhook' ? 'webhook' : 'addon');
        $mine = AddonInterest::where('tenant_id', $this->tenant()->id)->pluck('created_at', 'addon_id');

        return response()->json(['data' => Addon::where('status', '!=', 'retired')
            ->when($group, fn ($q) => $q->where('group', $group), fn ($q) => $q->where('group', '!=', 'webhook'))->orderBy('sort')->get()
            ->map(fn (Addon $a) => self::present($a) + ['interested_at' => $mine->get($a->id)?->toIso8601String()])]);
    }

    /** POST /api/addons/{key}/interest {note?} — "Beri tahu saya" saat add-on tersedia. Idempoten. */
    public function interest(Request $request, Addon $addon): JsonResponse
    {
        $this->authorizeGroup($addon->group);
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
        $this->authorizeGroup($addon->group);
        AddonInterest::where(['addon_id' => $addon->id, 'tenant_id' => $this->tenant()->id])->delete();

        return response()->json(['data' => self::present($addon) + ['interested_at' => null]]);
    }

    /** Webhook segera hadir: owner/admin (pengaturan workspace) boleh mendaftar minat walau paketnya belum Business. */
    private function authorizeGroup(?string $group): void
    {
        $group === 'webhook'
            ? $this->access()->authorize('integrations.manage', 'workspace.settings')
            : $this->access()->authorize('billing.view');
    }

    public static function present(Addon $a): array
    {
        return ['id' => $a->public_id, 'key' => $a->key, 'group' => $a->group, 'name' => $a->name, 'description' => $a->description,
            'features' => $a->features ?? [], 'price_monthly' => $a->price_monthly, 'status' => $a->status];
    }
}
