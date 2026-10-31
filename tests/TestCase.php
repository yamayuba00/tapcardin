<?php

namespace Tests;

use App\Actions\Auth\RegisterUser;
use App\Enums\BatchStatus;
use App\Enums\CardStatus;
use App\Models\Batch;
use App\Models\Card;
use App\Models\Profile;
use App\Models\User;
use App\Support\Base62;
use App\Support\Crockford;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    public const ACTIVATION_CODE = '7K2M9QXP';

    protected function host(string $sub, string $path = '/'): string
    {
        return "http://$sub.localhost".$path;
    }

    protected function makeUser(string $email = 'rina@example.com', string $name = 'Rina Saputra'): User
    {
        return app(RegisterUser::class)->handle($email, $name);
    }

    protected function profileOf(User $user): Profile
    {
        return Profile::withoutGlobalScope(TenantScope::class)->where('user_id', $user->id)->orderBy('id')->firstOrFail();
    }

    /** Kartu dalam status tertentu; `active` otomatis dipasang ke profil pertama pemegang. */
    protected function makeCard(CardStatus $status = CardStatus::Unclaimed, ?User $holder = null, ?User $buyer = null): Card
    {
        $batch = Batch::create([
            'code' => 'T'.Base62::random(6), 'status' => BatchStatus::TokensGenerated, 'chip_type' => 'ntag213',
            'quantity' => 1, 'created_by' => ($holder ?? $buyer ?? $this->makeUser('staff'.Base62::random(4).'@example.com'))->id,
        ]);
        $tenantId = $holder?->personal_tenant_id ?? $buyer?->personal_tenant_id;

        $card = Card::create([
            'card_token' => Base62::random(10),
            'short_code' => Crockford::random(8),
            'status' => $status,
            'chip_type' => 'ntag213',
            'activation_code_hash' => Hash::make(self::ACTIVATION_CODE),
            'tenant_id' => $tenantId,
            'holder_user_id' => $status === CardStatus::Active || $status === CardStatus::Paused || $status === CardStatus::Lost ? $holder?->id : null,
            'assigned_user_id' => $buyer?->id,
            'profile_id' => $holder && in_array($status, [CardStatus::Active, CardStatus::Paused, CardStatus::Lost], true) ? $this->profileOf($holder)->id : null,
            'batch_id' => $batch->id,
            'sequence' => 1,
            'encoded_at' => now(),
            'qc_passed_at' => now(),
            'lost_at' => $status === CardStatus::Lost ? now() : null,
        ]);

        return $card;
    }

    protected function inTenantOf(User $user, callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($user->personalTenant, $fn);
    }
}
