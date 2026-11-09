<?php

namespace App\Http\Controllers\Workspace;

use App\Enums\TenantType;
use App\Exceptions\ApiError;
use App\Models\BrandKit;
use App\Models\Card;
use App\Support\Audit;
use App\Support\Design\Palette;
use App\Support\ImageResizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Identitas workspace non-personal (perusahaan / komunitas / partai / organisasi): jenis organisasi + logo.
 * Logo disimpan di disk `public` (S3 di produksi), tampil di bio & halaman kartu, dan menjadi brand kit workspace
 * yang otomatis dipakai semua anggota saat mendesain kartu (slot logo template, {{brand}}, warna kit).
 */
class WorkspaceBrandController extends WorkspaceController
{
    public const ORG_KINDS = ['company' => 'Perusahaan', 'community' => 'Komunitas', 'party' => 'Partai politik', 'organization' => 'Organisasi'];

    /** GET /api/workspace/brand */
    public function show(): JsonResponse
    {
        $this->companyOnly();

        return response()->json(['data' => $this->present()]);
    }

    /** PATCH /api/workspace/brand {org_kind, primary_color?} */
    public function update(Request $request): JsonResponse
    {
        $this->companyOnly();
        $this->access()->authorize('workspace.settings');
        $data = $request->validate([
            'org_kind' => ['sometimes', Rule::in(array_keys(self::ORG_KINDS))],
            'primary_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $t = $this->tenant();
        if (isset($data['org_kind'])) {
            $t->update(['settings' => array_merge($t->settings ?? [], ['org_kind' => $data['org_kind']])]);
        }
        if (array_key_exists('primary_color', $data)) {
            $this->kit()->update(['primary_color' => $data['primary_color']]);
        }
        Audit::tenant($t->id, 'workspace.brand_updated', $t, null, $data);

        return response()->json(['data' => $this->present()]);
    }

    /** POST /api/workspace/brand/logo {image} — PNG/JPG/WebP ≤ 8 MB; diubah ke PNG (transparan dipertahankan, metadata dibuang). */
    public function logo(Request $request): JsonResponse
    {
        $this->companyOnly();
        $this->access()->authorize('workspace.settings');
        $request->validate(['image' => ['required', 'file', 'max:8192']], ['image.max' => 'Logo maksimal 8 MB.']);
        $img = ImageResizer::load($request->file('image'));
        imagesavealpha($img, true);

        $t = $this->tenant();
        $disk = Storage::disk('public');
        $path = "t{$t->id}/brand/logo-".Str::random(28).'.png';
        $disk->put($path, ImageResizer::png($img));
        $old = $t->logo_path;
        $t->update(['logo_path' => $path]);
        $this->kit()->update(['logo_path' => $path, 'logo_px_w' => imagesx($img), 'logo_px_h' => imagesy($img)]);
        if ($old && $old !== $path) {
            $disk->delete($old);
        }
        $this->forgetCards();
        Audit::tenant($t->id, 'workspace.logo_uploaded', $t);

        return response()->json(['data' => $this->present()]);
    }

    /** DELETE /api/workspace/brand/logo */
    public function removeLogo(): JsonResponse
    {
        $this->companyOnly();
        $this->access()->authorize('workspace.settings');
        $t = $this->tenant();
        if ($t->logo_path) {
            Storage::disk('public')->delete($t->logo_path);
        }
        $t->update(['logo_path' => null]);
        $this->kit()->update(['logo_path' => null, 'logo_px_w' => null, 'logo_px_h' => null]);
        $this->forgetCards();

        return response()->json(['data' => $this->present()]);
    }

    /** Brand kit workspace (dibuat sekali, dipakai semua anggota). */
    public static function kitFor(int $tenantId): ?BrandKit
    {
        return BrandKit::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_workspace', true)->first();
    }

    private function kit(): BrandKit
    {
        $t = $this->tenant();

        return self::kitFor($t->id) ?? BrandKit::create([
            'tenant_id' => $t->id, 'user_id' => $this->user()->id, 'is_workspace' => true,
            'name' => $t->name, 'display_name' => $t->name,
            'primary_color' => Palette::hex('onyx-950'), 'secondary_color' => Palette::hex('champagne-500'),
            'background_color' => Palette::hex('bone-50'), 'text_color' => Palette::hex('ink-950'),
        ]);
    }

    private function present(): array
    {
        $t = $this->tenant();
        $kind = $t->setting('org_kind') ?? 'company';

        return [
            'name' => $t->name,
            'org_kind' => $kind,
            'org_kind_label' => self::ORG_KINDS[$kind] ?? self::ORG_KINDS['company'],
            'org_kinds' => collect(self::ORG_KINDS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'logo_url' => $t->logo_path ? Storage::disk('public')->url($t->logo_path) : null,
            'kit' => self::kitFor($t->id)?->toKit(),
            'can_edit' => $this->access()->has('workspace.settings'),
        ];
    }

    private function companyOnly(): void
    {
        if ($this->tenant()->type === TenantType::Personal) {
            throw new ApiError('personal_workspace', 'Logo organisasi hanya untuk workspace perusahaan, komunitas, atau partai. Untuk akun pribadi, pakai brand kit di Design Studio.');
        }
    }

    /** Kartu anggota menampilkan logo baru di halaman tap tanpa menunggu cache habis. */
    private function forgetCards(): void
    {
        Card::where('tenant_id', $this->tenant()->id)->pluck('card_token')->each(function ($t) {
            Cache::forget("card:$t");
        });
    }
}
