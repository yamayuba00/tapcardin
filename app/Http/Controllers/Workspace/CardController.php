<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Cards\ChangeCardStatus;
use App\Actions\Cards\CreateDigitalCard;
use App\Actions\Cards\DynamicQr;
use App\Authz\PlanFeatures;
use App\Enums\CardStatus;
use App\Exceptions\ApiError;
use App\Http\Resources\Present;
use App\Models\Card;
use App\Models\Design;
use App\Models\ProductEvent;
use App\Models\Tap;
use App\Support\Audit;
use App\Support\Design\CardGeometry;
use App\Support\Design\CardVisual;
use App\Support\Design\TemplateLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** U-02/U-03 — kartu saya: ganti profil aktif (F-12), jeda, lapor hilang (F-13), kartu digital & bagikan. */
class CardController extends WorkspaceController
{
    public function list(): JsonResponse
    {
        $this->access()->authorize('cards.manage.own', 'cards.view.*');
        $cards = $this->visible()->with('profile')->orderByRaw("form = 'digital' and holder_user_id = ? desc", [$this->user()->id])
            ->orderByRaw("status = 'active' desc")->orderByDesc('activated_at')->get();

        return response()->json([
            'data' => $cards->map(fn (Card $c) => Present::card($c)),
            'meta' => ['has_digital' => (bool) CreateDigitalCard::of($this->user(), $this->tenant())],
        ]);
    }

    /** POST /api/cards/digital — buat (atau kembalikan) kartu digital pengguna di workspace ini; gratis di semua paket. */
    public function createDigital(CreateDigitalCard $create): JsonResponse
    {
        $this->access()->authorize('cards.manage.own');
        $existed = CreateDigitalCard::of($this->user(), $this->tenant());
        $card = $create->handle($this->user(), $this->tenant());
        if (! $existed) {
            Audit::tenant($card->tenant_id, 'card.digital_created', $card);
        }

        return response()->json(['data' => Present::card($card->load('profile'))], $existed ? 200 : 201);
    }

    public function detail(string $token): JsonResponse
    {
        $card = $this->find($token);
        $since = now()->subDays(30);
        $stats = Tap::where('card_id', $card->id)->where('created_at', '>=', $since)->selectRaw("
            count(*) as taps, count(distinct visitor_hash) as visitors,
            count(*) filter (where saved_contact) as saved,
            count(*) filter (where source = 'nfc') as nfc, count(*) filter (where source = 'qr') as qr,
            count(*) filter (where source not in ('nfc','qr')) as other
        ")->first();
        $lastLostTaps = $card->status === CardStatus::Lost
            ? Tap::where('card_id', $card->id)->where('created_at', '>=', $card->lost_at)->latest('created_at')->limit(5)
                ->get(['city', 'created_at'])->map(fn ($t) => ['city' => $t->city, 'at' => $t->created_at->toIso8601String()])
            : [];

        return response()->json(['data' => Present::card($card) + [
            'stats_30' => $stats ? array_map('intval', $stats->only(['taps', 'visitors', 'saved', 'nfc', 'qr', 'other'])) : [],
            'lost_taps' => $lastLostTaps,
            'qr_svg_url' => config('tapcard.resolver_url').'/c/'.$card->card_token.'/qr.svg',
            'share' => [
                'link' => $card->resolverUrl().'?src=link',
                // @nama hanya mengarah ke kartu ini bila memang kartu yang dipilih untuk nama pengguna
                'handle_url' => $this->user()->handle && $card->holder_user_id === $this->user()->id
                    && Card::forHandle($this->user())?->id === $card->id ? config('tapcard.resolver_url').'/@'.$this->user()->handle : null,
                'handle' => $this->user()->handle,
            ],
        ]]);
    }

    public function update(Request $request, string $token, ChangeCardStatus $status): JsonResponse
    {
        $card = $this->find($token);
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in(['active', 'paused', 'lost'])],
        ]);

        if (array_key_exists('label', $data)) {
            $card->update(['label' => $data['label']]);
        }
        if (isset($data['status']) && $data['status'] !== $card->status->value) {
            $this->access()->authorize('cards.manage.own', 'cards.status.*');
            $status->handle($card, CardStatus::from($data['status']), match ($data['status']) {
                'paused' => 'Dijeda pemilik', 'lost' => 'Dilaporkan hilang', default => 'Diaktifkan kembali',
            });
        }
        ChangeCardStatus::forget($card);

        return response()->json(['data' => Present::card($card->fresh('profile'))]);
    }

    /** GET /api/cards/{token}/visual — tampilan kartu (desain + bio kartu + QR asli) untuk layar & unduhan gambar. */
    public function visual(string $token): JsonResponse
    {
        return response()->json(['data' => CardVisual::for($this->find($token))]);
    }

    /**
     * PUT /api/cards/{token}/design {design?: public_id, template?: key, orientation?} — ganti tampilan kartu digital.
     * Template → dibuatkan desain baru milik pengguna (bisa diedit lanjut di Studio); desain → harus milik sendiri.
     */
    public function design(Request $request, string $token): JsonResponse
    {
        $card = $this->find($token);
        abort_unless($card->holder_user_id === $this->user()->id, 404);
        if (! $card->isDigital()) {
            throw new ApiError('card_printed', 'Tampilan kartu fisik mengikuti hasil cetak dan tidak bisa diganti.');
        }
        $data = $request->validate([
            'design' => ['nullable', 'required_without:template', 'string', 'size:26'],
            'template' => ['nullable', 'required_without:design', Rule::in(array_keys(TemplateLibrary::TEMPLATES))],
            'orientation' => ['nullable', Rule::in(['landscape', 'portrait'])],
        ]);

        if (! empty($data['design'])) {
            $design = Design::where('public_id', $data['design'])->where('user_id', $this->user()->id)->where('kind', 'card')->firstOrFail();
        } else {
            $g = new CardGeometry($data['orientation'] ?? 'landscape');
            $sides = TemplateLibrary::make($data['template'], $g);
            $design = Design::create([
                'user_id' => $this->user()->id, 'kind' => 'card', 'template_key' => $data['template'], 'orientation' => $g->orientation, 'lanyard' => false,
                'name' => 'Kartu digital — '.TemplateLibrary::TEMPLATES[$data['template']][0],
                'front_json' => $sides['front'], 'back_json' => $sides['back'],
                'locked_layer_ids' => collect([...$sides['front']['layers'], ...$sides['back']['layers']])->where('locked', true)->pluck('id')->values()->all(),
            ]);
        }
        $card->update(['design_id' => $design->id]);
        Audit::tenant($card->tenant_id, 'card.design_changed', $card, null, ['design' => $design->public_id]);

        return $this->visual($token);
    }

    /** POST /api/cards/{token}/shared {channel} — catat aksi berbagi (langkah onboarding & analitik produk). */
    public function shared(Request $request, string $token): JsonResponse
    {
        $card = $this->find($token);
        $data = $request->validate(['channel' => ['required', Rule::in(['copy', 'whatsapp', 'native', 'download', 'qr'])]]);
        ProductEvent::record('card_shared', $card->tenant_id, $this->user()->id, ['channel' => $data['channel'], 'form' => $card->form]);

        return response()->json(null, 204);
    }

    /** POST /api/cards/{token}/dynamic-qr — QR sekali pakai 60 detik untuk ditampilkan di layar (AFD F-11, fitur paket). */
    public function dynamicQr(string $token): JsonResponse
    {
        $card = $this->find($token);
        if (! PlanFeatures::has($this->tenant(), 'dynamic_qr')) {
            throw new ApiError('feature_locked', 'QR aman (dinamis) tersedia di paket Pro.', 402, ['feature' => 'dynamic_qr']);
        }
        if ($card->status !== CardStatus::Active) {
            throw new ApiError('card_not_active', 'QR aman hanya untuk kartu yang aktif.');
        }

        return response()->json(['data' => DynamicQr::issue($card)]);
    }

    /** POST /api/cards/{token}/replacement — AFD F-13: kartu hilang → minta kartu pengganti (desain & profil sama). */
    public function requestReplacement(Request $request, string $token): JsonResponse
    {
        $card = $this->find($token);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);
        if ($card->status !== CardStatus::Lost) {
            throw new ApiError('card_not_lost', 'Tandai kartu sebagai hilang dulu sebelum meminta pengganti.');
        }
        if (! $card->replacement_requested_at) {
            $card->forceFill(['replacement_requested_at' => now(), 'replacement_note' => $data['note'] ?? null])->save();
            Audit::tenant($card->tenant_id, 'card.replacement_requested', $card, null, ['note' => $data['note'] ?? null]);
        }

        return $this->detail($token);
    }

    private function visible()
    {
        return $this->access()->has('cards.view.all')
            ? Card::query()->whereIn('status', ['active', 'paused', 'lost', 'unclaimed'])
            : Card::query()->where('holder_user_id', $this->user()->id);
    }

    private function find(string $token): Card
    {
        $card = Card::where('card_token', $token)->firstOrFail(); // global scope tenant → 404 lintas workspace
        abort_unless($card->holder_user_id === $this->user()->id || $this->access()->has('cards.view.all'), 404);

        return $card;
    }
}
