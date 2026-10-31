<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Cards\CardView;
use App\Authz\PlanFeatures;
use App\Exceptions\ApiError;
use App\Http\Resources\Present;
use App\Http\Resources\PublicProfile;
use App\Models\Card;
use App\Models\Profile;
use App\Models\ProfileField;
use App\Support\Bio\BioTheme;
use App\Support\Contacts\ContactCatalog;
use App\Support\Contacts\ContactTypes;
use App\Support\Contacts\ContactValue;
use App\Support\ImageResizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** U-04/U-05 — multi-profil per pengguna (PF-03), field kontak (BSD §9), foto. */
class ProfileController extends WorkspaceController
{
    /** Data profil publik yang sama dengan resolver, untuk preview di editor (tanpa mencatat tap). */
    public function preview(Profile $profile): JsonResponse
    {
        $this->own($profile);

        return response()->json(['data' => PublicProfile::from(CardView::preview($profile), preview: true)]);
    }

    /** Preset & opsi tampilan bio link untuk editor. */
    public function themes(): JsonResponse
    {
        return response()->json(['data' => BioTheme::catalog()]);
    }

    public function list(): JsonResponse
    {
        $profiles = Profile::where('user_id', $this->user()->id)->whereNull('archived_at')
            ->withCount('cards')->orderBy('id')->get();

        return response()->json(['data' => $profiles->map(fn ($p) => Present::profile($p)), 'meta' => [
            'limit' => PlanFeatures::limit($this->tenant(), 'profiles_per_user'),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->access()->authorize('profiles.edit.own', 'profiles.edit.all');
        $data = $request->validate(['label' => ['required', 'string', 'max:40']]);
        $limit = PlanFeatures::limit($this->tenant(), 'profiles_per_user');
        $count = Profile::where('user_id', $this->user()->id)->whereNull('archived_at')->count();
        if ($limit !== null && $count >= $limit) {
            throw ApiError::planLimit("Paket Anda mendukung $limit profil. Upgrade untuk menambah profil.", 'profiles_per_user');
        }
        $profile = Profile::create([
            'user_id' => $this->user()->id,
            'label' => $data['label'],
            'display_name' => $this->user()->name,
            'theme' => BioTheme::normalize(null),
            'locale' => $this->user()->locale ?? 'id',
        ]);

        return response()->json(['data' => Present::profile($profile)], 201);
    }

    public function detail(Profile $profile): JsonResponse
    {
        $this->own($profile);

        return response()->json(['data' => Present::profile($profile->load('fields'), true)]);
    }

    public function update(Request $request, Profile $profile): JsonResponse
    {
        $this->own($profile);
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:40'],
            'display_name' => ['sometimes', 'string', 'max:120'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'company' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'theme' => ['sometimes', 'array'],
            'theme.preset' => ['sometimes', Rule::in(array_keys(BioTheme::PRESETS))],
            'theme.accent' => ['sometimes', 'nullable', Rule::in(BioTheme::ACCENTS)],
            ...collect(BioTheme::OPTIONS)->mapWithKeys(fn ($v, $k) => ["theme.$k" => ['sometimes', Rule::in($v)]])->all(),
            'locale' => ['sometimes', Rule::in(['id', 'en'])],
            'is_indexable' => ['sometimes', 'boolean'],
            'show_exchange_form' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['theme'])) {
            // DBD §3.7 — hanya pilihan (preset/opsi/nama token) yang disimpan; warna di-resolve saat render.
            $data['theme'] = BioTheme::normalize(array_merge(BioTheme::normalize($profile->theme), $data['theme']));
        }
        $profile->update($data);
        $this->forgetCards($profile);

        return response()->json(['data' => Present::profile($profile->fresh('fields'), true)]);
    }

    public function destroy(Profile $profile): JsonResponse
    {
        $this->own($profile);
        if (Card::where('profile_id', $profile->id)->whereIn('status', ['active', 'paused', 'lost'])->exists()) {
            throw new ApiError('profile_in_use', 'Profil masih dipakai kartu. Pindahkan kartu ke profil lain dulu.');
        }
        if (Profile::where('user_id', $this->user()->id)->whereNull('archived_at')->count() <= 1) {
            throw new ApiError('last_profile', 'Minimal harus ada satu profil.');
        }
        $profile->delete();

        return response()->json(null, 204);
    }

    /** PUT /api/profiles/{id}/fields — ganti seluruh daftar field (urutan = urutan array). */
    public function fields(Request $request, Profile $profile): JsonResponse
    {
        $this->own($profile);
        $data = $request->validate([
            'fields' => ['present', 'array', 'max:30'],
            'fields.*.id' => ['nullable', 'string', 'size:26'],
            'fields.*.type' => ['required', Rule::in(ContactTypes::activeKeys())],
            'fields.*.label' => ['nullable', 'string', 'max:60'],
            'fields.*.value' => ['required', 'string', 'max:500'],
            'fields.*.is_primary' => ['boolean'],
            'fields.*.is_visible' => ['boolean'],
            'fields.*.in_vcard' => ['boolean'],
            'fields.*.meta' => ['nullable', 'array'],
            'fields.*.meta.bank' => ['nullable', 'string', 'max:60'],
            'fields.*.meta.holder' => ['nullable', 'string', 'max:120'],
        ], ['fields.*.value.required' => 'Isi semua kolom kontak atau hapus baris yang kosong.']);

        $rows = [];
        foreach ($data['fields'] as $i => $f) {
            $n = ContactValue::normalize(ContactTypes::get($f['type']), $f['value'], $f['meta'] ?? [], "fields.$i.value", $profile->tenant_id);
            $rows[] = [...$f, ...$n];
        }

        DB::transaction(function () use ($profile, $rows) {
            $existing = $profile->fields()->get()->keyBy('public_id');
            $keep = [];
            $primarySeen = [];
            foreach ($rows as $sort => $f) {
                $group = in_array($f['type'], ['phone', 'whatsapp', 'whatsapp_business'], true) ? 'tel' : $f['type'];
                $isPrimary = ($f['is_primary'] ?? false) && ! isset($primarySeen[$group]);
                if ($isPrimary) {
                    $primarySeen[$group] = true;
                }
                $attrs = [
                    'type' => $f['type'],
                    'label' => ($f['label'] ?? '') ?: self::defaultLabel($f['type']),
                    'value' => $f['value'],
                    'meta' => $f['meta'],
                    'is_primary' => $isPrimary,
                    'is_visible' => $f['is_visible'] ?? true,
                    'in_vcard' => $f['in_vcard'] ?? true,
                    'sort' => $sort,
                ];
                $model = isset($f['id']) ? $existing->get($f['id']) : null;
                if ($model?->is_locked) {
                    $keep[] = $model->id; // field terkunci perusahaan tidak bisa diubah anggota (PF-04)

                    continue;
                }
                $model ? $model->update($attrs) : $model = $profile->fields()->create($attrs);
                $keep[] = $model->id;
            }
            ProfileField::where('profile_id', $profile->id)->where('is_locked', false)->whereNotIn('id', $keep)->delete();
            $profile->touch();
        });
        $this->forgetCards($profile);

        return response()->json(['data' => Present::profile($profile->fresh('fields'), true)]);
    }

    public function avatar(Request $request, Profile $profile): JsonResponse
    {
        $this->own($profile);
        $request->validate(['photo' => ['required', 'file']]);
        $img = ImageResizer::load($request->file('photo'));

        $dir = "t{$profile->tenant_id}/avatars";
        $name = Str::random(32);
        $disk = Storage::disk('public');
        foreach (array_filter([$profile->avatar_path, $profile->vcard_photo_path]) as $old) {
            $disk->delete($old);
        }
        $disk->put("$dir/$name.webp", ImageResizer::webp(ImageResizer::square($img, 400)));
        $disk->put("$dir/$name.jpg", ImageResizer::jpeg(ImageResizer::square($img, 256)));
        $profile->update(['avatar_path' => "$dir/$name.webp", 'vcard_photo_path' => "$dir/$name.jpg"]);
        $this->forgetCards($profile);

        return response()->json(['data' => Present::profile($profile->fresh('fields'), true)]);
    }

    /** TRD §11 — skema https saja, host bukan IP privat/loopback. */
    public static function defaultLabel(string $type): string
    {
        return ContactTypes::get($type)['label'] ?? ucfirst($type);
    }

    /** GET /api/contact-types — jenis kontak aktif per grup + daftar bank, untuk editor bio link. */
    public function contactTypes(): JsonResponse
    {
        $types = array_values(array_filter(ContactTypes::all(), fn ($t) => $t['is_active']));

        return response()->json(['data' => ['types' => $types, 'banks' => ContactCatalog::BANKS]])
            ->header('Cache-Control', 'private, max-age=600');
    }

    /** POST /api/profiles/{id}/field-image — gambar untuk field (QRIS). Disimpan PNG agar tetap tajam saat di-scan. */
    public function fieldImage(Request $request, Profile $profile): JsonResponse
    {
        $this->own($profile);
        $request->validate(['image' => ['required', 'file', 'max:4096']]);
        $img = ImageResizer::load($request->file('image'));
        $path = "t{$profile->tenant_id}/fields/".Str::random(32).'.png';
        Storage::disk('public')->put($path, ImageResizer::png(ImageResizer::fit($img, 1000)));

        return response()->json(['data' => ['path' => $path, 'url' => Storage::disk('public')->url($path)]], 201);
    }

    private function own(Profile $profile): void
    {
        abort_unless($profile->user_id === $this->user()->id || $this->access()->has('profiles.edit.all'), 404);
    }

    private function forgetCards(Profile $profile): void
    {
        Card::where('profile_id', $profile->id)->pluck('card_token')->each(fn ($t) => Cache::forget("card:$t"));
    }
}
