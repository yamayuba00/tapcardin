<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Cards\ChangeCardStatus;
use App\Actions\Production\CreateBatch;
use App\Enums\CardStatus;
use App\Enums\TenantType;
use App\Exceptions\ApiError;
use App\Models\Batch;
use App\Models\Card;
use App\Models\CardSale;
use App\Models\CardStatusHistory;
use App\Models\Tap;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\Crockford;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** PL-07 — cari kartu via token/kode pendek/email; riwayat status; ubah status; terbitkan ulang kode aktivasi. */
class CardController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('cards.view');
        $q = trim((string) $request->query('q'));
        $code = Crockford::normalize($q);
        $cards = Card::acrossTenants()->with(['holder', 'assignee', 'batch'])
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('card_token', $q)
                ->orWhere('short_code', $code)
                ->orWhereHas('holder', fn ($h) => $h->where('email', 'ilike', "%$q%"))
                ->orWhereHas('assignee', fn ($h) => $h->where('email', 'ilike', "%$q%"))))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))
            ->when($request->boolean('replacement'), fn ($w) => $w->whereNotNull('replacement_requested_at')
                ->where('status', CardStatus::Lost->value)
                ->whereNotExists(fn ($b) => $b->from('batches')->whereColumn('batches.replaces_card_id', 'cards.id')));

        return Table::json(Table::paginate($cards, $request, self::SORTS, '-updated_at', [], 40), fn (Card $c) => self::summary($c), $request);
    }

    public function show(string $token): JsonResponse
    {
        $this->can('cards.view');
        $card = $this->find($token)->load(['holder', 'batch', 'assignee']);

        return $this->ok(self::summary($card) + [
            'url' => $card->resolverUrl(),
            'assigned' => $card->assignee ? ['id' => $card->assignee->public_id, 'email' => $card->assignee->email] : null,
            'can_assign' => in_array($card->status, [CardStatus::Manufactured, CardStatus::Unclaimed], true)
                && (! $card->tenant_id || Tenant::find($card->tenant_id)?->type === TenantType::Personal),
            'uid_bound' => $card->chip_uid_hash !== null,
            'sale' => CardSale::with(['buyer', 'seller'])->where('card_id', $card->id)->first()?->present(),
            'sale_methods' => CardSale::METHODS,
            'encoded_at' => self::dt($card->encoded_at),
            'qc_passed_at' => self::dt($card->qc_passed_at),
            'shipped_at' => self::dt($card->shipped_at),
            'activated_at' => self::dt($card->activated_at),
            'activation_attempts' => $card->activation_attempts,
            'activation_locked_until' => $card->activation_locked_until?->isFuture() ? self::dt($card->activation_locked_until) : null,
            'targets' => array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], $card->staffTargets()),
            'can_reissue' => in_array($card->status, [CardStatus::Manufactured, CardStatus::Unclaimed], true),
            'replacement' => $card->replacement_requested_at ? [
                'requested_at' => self::dt($card->replacement_requested_at),
                'note' => $card->replacement_note,
                'batch' => ($b = Batch::where('replaces_card_id', $card->id)->latest()->first()) ? ['id' => $b->public_id, 'code' => $b->code, 'status_label' => $b->status->label()] : null,
            ] : null,
            'history' => CardStatusHistory::where('card_id', $card->id)->latest('id')->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'reason' => $h->reason, 'at' => self::dt($h->created_at)]),
            'taps' => Tap::acrossTenants()->where('card_id', $card->id)->latest('created_at')->limit(15)->get()
                ->map(fn ($t) => ['source' => $t->source->value, 'device' => $t->device, 'city' => $t->city, 'at' => self::dt($t->created_at)]),
        ]);
    }

    public function status(Request $request, string $token, ChangeCardStatus $status): JsonResponse
    {
        $this->can('cards.manage');
        $card = $this->find($token);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, $card->staffTargets()))],
            'reason' => ['required', 'string', 'max:200'],
        ], ['reason.required' => 'Alasan wajib diisi.', 'status.in' => 'Status tujuan tidak diizinkan untuk kartu ini.']);
        $status->handle($card, CardStatus::from($data['status']), $data['reason'], platform: true);

        return $this->show($token);
    }

    /**
     * Tandai (atau lepas) pemilik kartu sebelum diaktifkan. Pemilik yang ditandai mengaktifkan tanpa kode, cukup masuk dengan emailnya;
     * pengguna lain tetap butuh kode aktivasi. Berguna untuk kartu NFC polos yang dibagikan ke orang tertentu.
     */
    public function assign(Request $request, string $token): JsonResponse
    {
        $this->can('cards.manage');
        $card = $this->find($token)->load('assignee');
        if (! in_array($card->status, [CardStatus::Manufactured, CardStatus::Unclaimed], true)) {
            throw new ApiError('card_active', 'Pemilik hanya bisa ditandai sebelum kartu diaktifkan.');
        }
        if ($card->tenant_id && Tenant::find($card->tenant_id)?->type !== TenantType::Personal) {
            throw new ApiError('company_card', 'Kartu perusahaan ditugaskan oleh admin workspace-nya.');
        }
        $data = $request->validate(['email' => ['nullable', 'email', 'max:190']]);
        $user = null;
        if (! empty($data['email'])) {
            $user = User::whereRaw('lower(email) = ?', [strtolower($data['email'])])->first()
                ?? throw ValidationException::withMessages(['email' => 'Pengguna dengan email ini belum terdaftar. Minta mereka masuk sekali ke TapCard.']);
        }
        $before = $card->assignee?->email;
        $card->forceFill(['assigned_user_id' => $user?->id, 'activation_attempts' => 0, 'activation_locked_until' => null])->save();
        Audit::platform('card.assigned', $card, ['assigned' => $before], ['assigned' => $user?->email]);

        return $this->show($token);
    }

    /** Kode aktivasi baru hanya dikembalikan sekali (yang disimpan hanya hash). */
    public function reissueCode(string $token): JsonResponse
    {
        $this->can('cards.manage');
        $card = $this->find($token);
        if (! in_array($card->status, [CardStatus::Manufactured, CardStatus::Unclaimed], true)) {
            throw new ApiError('card_active', 'Kartu sudah aktif.');
        }
        $code = Crockford::random(8);
        $card->forceFill(['activation_code_hash' => Hash::make($code), 'activation_attempts' => 0, 'activation_locked_until' => null])->save();
        Audit::platform('card.activation_code_reissued', $card);

        return $this->ok(['code' => substr($code, 0, 4).'-'.substr($code, 4)]);
    }

    /** Buat batch produksi 1 kartu pengganti untuk kartu hilang (AFD F-13). Pembeli = pemegang lama → aktivasi tanpa kode. */
    public function replacementBatch(string $token, CreateBatch $create): JsonResponse
    {
        $this->can('production.manage');
        $card = $this->find($token)->load('holder');
        if ($card->status !== CardStatus::Lost || ! $card->holder) {
            throw new ApiError('not_replaceable', 'Hanya kartu hilang yang punya pemegang yang bisa diganti.');
        }
        if (Batch::where('replaces_card_id', $card->id)->whereNot('status', 'cancelled')->exists()) {
            throw new ApiError('replacement_exists', 'Batch pengganti untuk kartu ini sudah dibuat.');
        }
        $batch = $create->handle($card->chip_type, 1, $card->holder, 'Pengganti '.$card->formattedShortCode(), $card);

        return $this->ok(['batch' => ['id' => $batch->public_id, 'code' => $batch->code]], 201);
    }

    /** Kolom urut yang sama untuk semua tabel kartu di panel pemilik. */
    public const SORTS = [
        'token' => 'card_token', 'short_code' => 'short_code', 'status' => 'status',
        'last_tap_at' => 'last_tap_at', 'updated_at' => 'updated_at', 'activated_at' => 'activated_at',
    ];

    public static function summary(Card $c): array
    {
        return [
            'token' => $c->card_token,
            'short_code' => $c->formattedShortCode(),
            'label' => $c->label,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'tone' => $c->status->tone(),
            'form' => $c->form,
            'chip_type' => $c->chip_type?->value,
            'holder' => $c->relationLoaded('holder') && $c->holder ? ['id' => $c->holder->public_id, 'email' => $c->holder->email] : null,
            // pemilik yang ditandai sebelum kartu diaktifkan (setelah aktif, pemegang yang berlaku)
            'assigned' => $c->relationLoaded('assignee') && $c->assignee ? ['id' => $c->assignee->public_id, 'email' => $c->assignee->email] : null,
            'batch' => $c->relationLoaded('batch') && $c->batch ? ['id' => $c->batch->public_id, 'code' => $c->batch->code] : null,
            'sequence' => $c->sequence,
            'replacement_requested' => $c->replacement_requested_at !== null,
            'last_tap_at' => self::dt($c->last_tap_at),
        ];
    }

    private function find(string $token): Card
    {
        return Card::acrossTenants()->where('card_token', $token)->firstOrFail();
    }
}
