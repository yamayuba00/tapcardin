<?php

namespace App\Actions\Cards;

use App\Enums\CardStatus;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\CardStatusHistory;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Satu-satunya jalur perubahan cards.status: validasi transisi, riwayat, audit, invalidasi cache. */
final class ChangeCardStatus
{
    public function handle(Card $card, CardStatus $to, ?string $reason = null, bool $platform = false, array $extra = []): Card
    {
        $from = $card->status;
        // kartu digital: hanya aktif ↔ jeda, dan bisa langsung dicabut (tidak ada hilang/stok/kirim)
        $ok = $card->isDigital()
            ? $from !== CardStatus::Revoked && in_array($to, [...$card->allowedNext(), CardStatus::Revoked], true)
            : $from->canTransitionTo($to);
        if (! $ok) {
            throw new ApiError('invalid_transition', "Kartu {$from->label()} tidak bisa diubah menjadi {$to->label()}.");
        }

        return DB::transaction(function () use ($card, $from, $to, $reason, $platform, $extra) {
            $card->fill($extra);
            $card->status = $to;
            match ($to) {
                CardStatus::Active => $card->activated_at ??= now(),
                CardStatus::Lost => $card->lost_at = now(),
                CardStatus::Revoked => $card->revoked_at = now(),
                CardStatus::Unclaimed => $card->shipped_at ??= now(),
                default => null,
            };
            if ($from === CardStatus::Lost && $to === CardStatus::Active) {
                $card->lost_at = null;
            }
            $card->save();

            CardStatusHistory::create([
                'tenant_id' => $card->tenant_id,
                'card_id' => $card->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_user_id' => auth()->id(),
                'reason' => $reason,
            ]);

            $before = ['status' => $from->value];
            $after = ['status' => $to->value, 'reason' => $reason];
            $platform || $card->tenant_id === null
                ? Audit::platform('card.status_changed', $card, $before, $after)
                : Audit::tenant($card->tenant_id, 'card.status_changed', $card, $before, $after);

            self::forget($card);

            return $card;
        });
    }

    public static function forget(Card $card): void
    {
        Cache::forget("card:{$card->card_token}");
    }
}
