<?php

namespace App\Actions\Cards;

use App\Actions\Profiles\ProvisionCardProfile;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardStatusHistory;
use App\Models\ProductEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Base62;
use App\Support\Crockford;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Kartu digital — langsung aktif tanpa chip/batch, punya bio link sendiri (1 kartu = 1 bio) dan alamat
 * `/c/{token}` permanen yang sama seperti kartu fisik. Idempoten: satu kartu digital (belum dicabut)
 * per pengguna per workspace (indeks unik parsial `cards_digital_holder_uniq`).
 * Tidak dihitung ke batas `cards_active` paket.
 */
final class CreateDigitalCard
{
    public const LABEL = 'Kartu digital';

    public function __construct(private readonly CurrentTenant $current, private readonly ProvisionCardProfile $provision) {}

    public function handle(User $user, Tenant $tenant): Card
    {
        return DB::transaction(function () use ($user, $tenant) {
            $existing = self::of($user, $tenant);
            if ($existing) {
                return $existing;
            }

            return $this->current->run($tenant, function () use ($user, $tenant) {
                $card = new Card([
                    'form' => Card::DIGITAL, 'card_token' => self::unique('card_token', fn () => Base62::random(10)),
                    'short_code' => self::unique('short_code', fn () => Crockford::random(8)),
                    'tenant_id' => $tenant->id, 'holder_user_id' => $user->id, 'label' => self::LABEL,
                ]);
                $card->profile_id = $this->provision->handle($user, $card)->id;
                $card->status = CardStatus::Active;
                $card->activated_at = now();
                $card->save();

                CardStatusHistory::create(['tenant_id' => $tenant->id, 'card_id' => $card->id, 'from_status' => 'manufactured',
                    'to_status' => 'active', 'actor_user_id' => $user->id, 'reason' => 'Kartu digital dibuat']);
                ProductEvent::record('digital_card_created', $tenant->id, $user->id);

                return $card;
            });
        });
    }

    public static function of(User $user, Tenant $tenant): ?Card
    {
        return Card::acrossTenants()->where(['tenant_id' => $tenant->id, 'holder_user_id' => $user->id, 'form' => Card::DIGITAL])
            ->where('status', '!=', CardStatus::Revoked->value)->first();
    }

    private static function unique(string $column, callable $make): string
    {
        do {
            $value = $make();
        } while (Card::acrossTenants()->where($column, $value)->exists());

        return $value;
    }
}
