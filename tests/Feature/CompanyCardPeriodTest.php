<?php

namespace Tests\Feature;

use App\Actions\Cards\ActivateCard;
use App\Enums\CardStatus;
use App\Enums\TenantType;
use App\Models\Card;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\CardPeriod;
use App\Support\TapQuota;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kartu fisik perusahaan: masa pakai 6 bulan dari aktivasi, kuota tap 6.000 per periode, aman saat perpanjang. */
class CompanyCardPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Tenant $org;

    private User $budi;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $this->org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_user_id' => $this->owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        $this->budi = $this->makeUser('budi@majujaya.id', 'Budi');
        app(CurrentTenant::class)->run($this->org, fn () => TenantMember::create(['user_id' => $this->owner->id, 'role_id' => Role::system('owner')->id, 'status' => 'active']));
        app(CurrentTenant::class)->run($this->org, fn () => TenantMember::create(['user_id' => $this->budi->id, 'role_id' => Role::system('member')->id, 'status' => 'active']));
    }

    /** kartu fisik perusahaan milik Budi (aktif, atau siap diaktifkan) */
    private function companyCard(CardStatus $status, ?int $validDays = null, array $extra = []): Card
    {
        $card = $this->makeCard($status === CardStatus::Active ? CardStatus::Active : CardStatus::Unclaimed, $status === CardStatus::Active ? $this->budi : null);
        $card->forceFill([
            'tenant_id' => $this->org->id, 'form' => Card::PHYSICAL, 'assigned_user_id' => $this->budi->id,
            'valid_until' => $validDays === null ? null : now()->addDays($validDays)->startOfSecond(),
        ] + $extra)->save();

        return $card->fresh();
    }

    public function test_activation_starts_six_month_period_and_company_quota(): void
    {
        $card = $this->companyCard(CardStatus::Unclaimed);

        app(ActivateCard::class)->handle($card->card_token, $this->budi);

        $card->refresh();
        $this->assertSame(CardStatus::Active, $card->status);
        $this->assertEqualsWithDelta(now()->addMonths(CardPeriod::months())->timestamp, $card->valid_until->timestamp, 120);
        $this->assertSame((int) config('tapcard.company_card.tap_quota'), (int) $card->tap_quota);
        $this->assertSame(0, (int) $card->tap_used);
    }

    public function test_early_renewal_adds_quota_and_keeps_usage(): void
    {
        $card = $this->companyCard(CardStatus::Active, 60, ['tap_quota' => 6000, 'tap_used' => 4000]);

        $this->actingAs($this->budi);
        $invoiceId = $this->json('POST', $this->host('majujaya', "/api/cards/{$card->card_token}/renew"))->assertCreated()->json('data.invoice.id');
        $this->actingAs($this->owner);
        $this->postJson($this->host('majujaya', "/api/billing/invoices/{$invoiceId}/simulate"), ['result' => 'paid', 'method' => 'QRIS'])->assertOk();

        $card->refresh();
        $this->assertSame(12000, (int) $card->tap_quota, 'kuota periode berjalan ditambah, tidak di-reset');
        $this->assertSame(4000, (int) $card->tap_used, 'pemakaian periode berjalan tetap');
    }

    public function test_renewal_after_expiry_starts_fresh_period(): void
    {
        $card = $this->companyCard(CardStatus::Active, -20, ['tap_quota' => 6000, 'tap_used' => 6000]);

        $this->actingAs($this->budi);
        $invoiceId = $this->json('POST', $this->host('majujaya', "/api/cards/{$card->card_token}/renew"))->assertCreated()->json('data.invoice.id');
        $this->actingAs($this->owner);
        $this->postJson($this->host('majujaya', "/api/billing/invoices/{$invoiceId}/simulate"), ['result' => 'paid', 'method' => 'QRIS'])->assertOk();

        $card->refresh();
        $this->assertSame((int) config('tapcard.company_card.tap_quota'), (int) $card->tap_quota);
        $this->assertSame(0, (int) $card->tap_used, 'periode baru: pemakaian di-reset');
        $this->assertEqualsWithDelta(now()->addMonths(CardPeriod::months())->timestamp, $card->valid_until->timestamp, 120);
    }

    public function test_exhausted_company_card_stops_counting_taps(): void
    {
        $card = $this->companyCard(CardStatus::Active, 60, ['tap_quota' => 6000, 'tap_used' => 6000]);

        $this->assertFalse(TapQuota::available($card->id, $this->org->id), 'kuota habis: profil tidak tampil');
    }
}
