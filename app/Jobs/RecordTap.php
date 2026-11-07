<?php

namespace App\Jobs;

use App\Actions\Events\EventForCard;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Tap;
use App\Models\TeamMember;
use App\Support\Notify;
use App\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/** Antrean `taps` (BSD §7.3): simpan tap, perbarui last_tap_at, notifikasi pemegang. */
class RecordTap implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 3;

    public function __construct(
        public int $tenantId,
        public int $cardId,
        public TapContext $ctx,
    ) {
        $this->onQueue('taps');
    }

    public function handle(): void
    {
        $this->enterTenant();
        $card = Card::find($this->cardId);
        if (! $card) {
            return;
        }
        $at = Carbon::parse($this->ctx->at);

        // event kartu (di-assign & sedang berlangsung) atau Mode Event pemegang
        $eventId = EventForCard::at($this->tenantId, $card->id, $card->holder_user_id, $at)?->id;
        $teamId = $card->holder_user_id
            ? TeamMember::where('user_id', $card->holder_user_id)->orderBy('created_at')->value('team_id')
            : null;

        $tap = Tap::create([
            'tenant_id' => $this->tenantId,
            'card_id' => $card->id,
            'profile_id' => $card->profile_id,
            'holder_user_id' => $card->holder_user_id,
            'team_id' => $teamId,
            'source' => $this->ctx->source,
            'sun_result' => $this->ctx->sunResult,
            'event_id' => $eventId,
            'ip_hash' => $this->ctx->ipHash,
            'visitor_hash' => $this->ctx->visitorHash,
            'country' => $this->ctx->country,
            'city' => $this->ctx->city,
            'device' => $this->ctx->device,
            'browser' => $this->ctx->browser,
            'created_at' => $at,
        ]);

        Card::whereKey($card->id)->update(['last_tap_at' => $at]);

        if (! $card->holder_user_id) {
            return;
        }
        $data = ['card' => $card->card_token, 'label' => $card->label, 'city' => $this->ctx->city, 'source' => $this->ctx->source, 'tap_id' => $tap->id, 'at' => $at->toIso8601String()];
        match ($card->status) {
            CardStatus::Active => Notify::user($card->holder_user_id, $this->tenantId, 'tap', $data),
            CardStatus::Lost => Notify::user($card->holder_user_id, $this->tenantId, 'lost_card_tapped', $data),
            default => null,
        };
    }
}
