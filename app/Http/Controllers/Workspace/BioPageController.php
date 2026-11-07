<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Profiles\ProvisionCardProfile;
use App\Exceptions\ApiError;
use App\Models\BioBlock;
use App\Models\BioPage;
use App\Models\Card;
use App\Models\Profile;
use App\Support\Bio\BioBlocks;
use App\Support\Bio\BioTheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Halaman bio link per kartu (kartu fisik maks. 3, kartu digital 1; satu aktif) + susunan bloknya. Identitas & kontak tetap di ProfileController;
 * di sini hanya nama halaman, tema, dan blok.
 */
class BioPageController extends WorkspaceController
{
    /** GET /api/profiles/{id}/pages */
    public function index(Profile $profile): JsonResponse
    {
        $this->own($profile);
        if (BioPage::ensureFor($profile)) {
            $this->forgetCards($profile); // resolver beralih dari tampilan lama ke blok
        }

        return response()->json(['data' => $this->pages($profile), 'meta' => ['limit' => self::limit($profile)]]);
    }

    /** POST /api/profiles/{id}/pages — halaman baru (tombol simpan kontak + daftar kontak) atau salinan halaman lain (`from`). */
    public function store(Request $request, Profile $profile): JsonResponse
    {
        $this->own($profile);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'from' => ['nullable', 'string', 'size:26'],
        ]);
        BioPage::ensureFor($profile);

        $page = DB::transaction(function () use ($profile, $data) {
            Profile::whereKey($profile->id)->lockForUpdate()->first(); // dua klik bersamaan tidak melewati batas
            $limit = self::limit($profile);
            if (BioPage::where('profile_id', $profile->id)->count() >= $limit) {
                throw new ApiError('bio_page_limit', $limit === 1
                    ? 'Kartu digital hanya punya 1 bio link. Kartu fisik bisa sampai '.BioBlocks::MAX_PAGES.'.'
                    : 'Satu kartu maksimal punya '.$limit.' bio link. Hapus salah satu dulu.');
            }
            if ($data['from'] ?? null) {
                return $this->find($profile, $data['from'])->duplicateTo($profile, $data['name']);
            }
            $active = BioPage::where('profile_id', $profile->id)->where('is_active', true)->first();
            $page = BioPage::create([
                'tenant_id' => $profile->tenant_id, 'profile_id' => $profile->id, 'name' => $data['name'],
                'is_active' => false, 'theme' => BioTheme::normalize($active?->theme ?? $profile->theme),
            ]);
            $page->addStarterBlocks();

            return $page;
        });

        return response()->json(['data' => $page->load('blocks')->present()], 201);
    }

    /** PUT /api/profiles/{id}/pages/{page} — simpan nama, tema, dan seluruh blok (urutan = urutan array). */
    public function update(Request $request, Profile $profile, string $page): JsonResponse
    {
        $this->own($profile);
        $page = $this->find($profile, $page);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'theme' => ['required', 'array'],
            'theme.preset' => ['sometimes', Rule::in(array_keys(BioTheme::PRESETS))],
            'theme.accent' => ['sometimes', 'nullable', Rule::in(BioTheme::ACCENTS)],
            ...collect(BioTheme::OPTIONS)->mapWithKeys(fn ($v, $k) => ["theme.$k" => ['sometimes', Rule::in($v)]])->all(),
            'blocks' => ['present', 'array', 'max:'.BioBlocks::MAX_BLOCKS],
            'blocks.*.id' => ['nullable', 'string', 'size:26'],
            'blocks.*.type' => ['required', Rule::in(BioBlocks::TYPES)],
            'blocks.*.is_visible' => ['boolean'],
            'blocks.*.data' => ['nullable', 'array'],
        ], ['blocks.max' => 'Maksimal '.BioBlocks::MAX_BLOCKS.' blok per bio link.']);

        $rows = [];
        ksort($data['blocks']); // validated() bisa mengacak urutan kunci array bersarang; urutan = urutan kiriman
        foreach ($data['blocks'] as $i => $b) {
            if (isset(BioBlocks::SINGLE[$b['type']]) && in_array($b['type'], array_column($rows, 'type'), true)) {
                throw ValidationException::withMessages(["blocks.$i.type" => 'Blok '.BioBlocks::SINGLE[$b['type']].' cukup satu per bio link.']);
            }
            $rows[] = [
                'id' => $b['id'] ?? null,
                'type' => $b['type'],
                'is_visible' => $b['is_visible'] ?? true,
                'data' => BioBlocks::normalize($b['type'], $b['data'] ?? [], "blocks.$i.data"),
            ];
        }

        DB::transaction(function () use ($page, $data, $rows) {
            $theme = BioTheme::normalize(array_merge(BioTheme::normalize($page->theme), $data['theme']));
            $page->update(['name' => $data['name'], 'theme' => $theme]);
            if ($page->is_active) {
                $page->profile()->update(['theme' => json_encode($theme), 'updated_at' => now()]);
            }
            $existing = $page->blocks()->get()->keyBy('public_id');
            $keep = [];
            foreach ($rows as $sort => $r) {
                $attrs = ['type' => $r['type'], 'data' => $r['data'], 'is_visible' => $r['is_visible'], 'sort' => $sort];
                $model = $r['id'] ? $existing->get($r['id']) : null;
                $model ? $model->update($attrs) : $model = $page->blocks()->create($attrs + ['tenant_id' => $page->tenant_id]);
                $keep[] = $model->id;
            }
            BioBlock::where('bio_page_id', $page->id)->whereNotIn('id', $keep)->delete();
            $page->touch();
        });
        $this->forgetCards($profile);

        return response()->json(['data' => $page->fresh('blocks')->present()]);
    }

    /** POST /api/profiles/{id}/pages/{page}/activate — yang lain otomatis nonaktif. */
    public function activate(Profile $profile, string $page): JsonResponse
    {
        $this->own($profile);
        $this->find($profile, $page)->activate();
        $this->forgetCards($profile);

        return response()->json(['data' => $this->pages($profile)]);
    }

    /** DELETE /api/profiles/{id}/pages/{page} — halaman aktif tidak bisa dihapus. */
    public function destroy(Profile $profile, string $page): JsonResponse
    {
        $this->own($profile);
        $page = $this->find($profile, $page);
        if ($page->is_active) {
            throw new ApiError('bio_page_active', 'Bio link yang sedang aktif tidak bisa dihapus. Aktifkan bio lain dulu.');
        }
        $page->delete();

        return response()->json(['data' => $this->pages($profile)]);
    }

    /** Paket kartu digital: 1 bio link; kartu fisik BioBlocks::MAX_PAGES (1 kartu = 1 profil, ProvisionCardProfile). */
    public static function limit(Profile $profile): int
    {
        $digital = Card::acrossTenants()->where('profile_id', $profile->id)->where('form', Card::DIGITAL)
            ->whereIn('status', ProvisionCardProfile::OWNING)->exists();

        return $digital ? (int) config('tapcard.digital_card.bio_pages') : BioBlocks::MAX_PAGES;
    }

    private function pages(Profile $profile): array
    {
        return BioPage::where('profile_id', $profile->id)->with('blocks')->orderBy('id')->get()
            ->map(fn (BioPage $p) => $p->present())->all();
    }

    private function find(Profile $profile, string $id): BioPage
    {
        return BioPage::where('profile_id', $profile->id)->where('public_id', $id)->firstOrFail();
    }

    private function own(Profile $profile): void
    {
        abort_unless($profile->user_id === $this->user()->id || $this->access()->has('profiles.edit.all'), 404);
    }

    private function forgetCards(Profile $profile): void
    {
        Card::where('profile_id', $profile->id)->pluck('card_token')->each(function ($t) {
            Cache::forget("card:$t");
        }); // bukan arrow fn: forget() = false menghentikan each()
    }
}
