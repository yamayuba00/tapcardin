<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Masa aktif workspace perusahaan (panel & ringkasan) + pengingat H-30 / H-7 / H-1 ke owner & peran Billing, sekali per periode. */
class ActivePeriodReminderTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $owner;

    private User $billing;

    private User $member;

    private Tenant $org;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $this->actingAs($this->makeStaff());
        $id = $this->json('POST', $this->host('panel', '/api/tenants'), ['name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_email' => $this->owner->email, 'plan' => 'starter'])
            ->assertCreated()->json('data.id');
        $this->org = Tenant::where('public_id', $id)->firstOrFail();
        $this->billing = $this->makeUser('keuangan@majujaya.id', 'Keuangan');
        $this->member = $this->makeUser('sales@majujaya.id', 'Sales');
        app(CurrentTenant::class)->run($this->org, function () {
            TenantMember::create(['tenant_id' => $this->org->id, 'user_id' => $this->billing->id, 'role_id' => Role::system('billing')->id, 'status' => 'active']);
            TenantMember::create(['tenant_id' => $this->org->id, 'user_id' => $this->member->id, 'role_id' => Role::system('member')->id, 'status' => 'active']);
        });
    }

    private function sub(): Subscription
    {
        return Subscription::acrossTenants()->where('tenant_id', $this->org->id)->firstOrFail();
    }

    /** Langganan berbayar aktif 6 bulan mulai hari ini. */
    private function activate(): Subscription
    {
        $s = $this->sub();
        $s->update(['status' => 'active', 'trial_ends_at' => null, 'current_period_start' => now(), 'current_period_end' => now()->addMonthsNoOverflow(6)]);

        return $s->fresh();
    }

    private function reminders(string $type = 'subscription_reminder'): array
    {
        return Notification::where('type', $type)->orderBy('id')->get()
            ->map(fn ($n) => [$n->user_id, $n->data['stage'] ?? null])->all();
    }

    private function run_(): void
    {
        $this->artisan('tapcard:subscriptions')->assertSuccessful();
    }

    public function test_active_period_payload_for_trial_active_grace_and_expired(): void
    {
        $period = fn () => $this->json('GET', $this->host('panel', "/api/tenants/{$this->org->public_id}"))->assertOk()->json('data.active_period');

        $p = $period();
        $this->assertSame(['trialing', 'Uji coba', 14], [$p['status'], $p['label'], $p['days_left']]);
        $this->assertSame($this->sub()->trial_ends_at->toIso8601String(), $p['end']);

        $s = $this->activate();
        $p = $period();
        $this->assertSame(['active', 'Aktif'], [$p['status'], $p['label']]);
        $this->assertSame([$s->current_period_start->toIso8601String(), $s->current_period_end->toIso8601String()], [$p['start'], $p['end']]);
        $this->assertGreaterThan(180, $p['days_left']);

        $s->update(['status' => 'past_due', 'current_period_end' => now()->subDay(), 'grace_until' => now()->addDays(13)]);
        $p = $period();
        $this->assertSame(['past_due', 'Masa tenggang', 13], [$p['status'], $p['label'], $p['days_left']]);

        $s->update(['status' => 'expired']);
        $this->assertSame(['expired', 'Masa aktif habis', 0], array_values(array_intersect_key($period(), array_flip(['status', 'label', 'days_left']))));

        // ringkasan perusahaan di app memakai helper yang sama
        $s->update(['status' => 'active', 'current_period_end' => now()->addDays(40)]);
        $this->actingAs($this->owner);
        $sum = $this->json('GET', $this->host('majujaya', '/api/company/summary'))->assertOk()->json('data.subscription');
        $this->assertSame(['active', 40], [$sum['status'], $sum['days_left']]);
    }

    public function test_reminders_h30_h7_h1_once_each_to_owner_and_billing_only(): void
    {
        $s = $this->activate();
        $end = $s->current_period_end;
        $expected = fn (string $stage) => [[$this->owner->id, $stage], [$this->billing->id, $stage]];

        $this->travelTo($end->copy()->subDays(31));
        $this->run_();
        $this->assertSame([], $this->reminders(), 'belum H-30');

        $this->travelTo($end->copy()->subDays(30)->addHour());
        $this->run_();
        $this->run_(); // perintah jalan dua kali sehari: tetap sekali
        $this->assertSame($expected('h30'), $this->reminders());

        $this->travelTo($end->copy()->subDays(20));
        $this->run_();
        $this->assertCount(2, $this->reminders(), 'H-30 tidak diulang di hari berikutnya');

        // H-7: invoice perpanjangan terbit → invoice_due ke owner & billing, bukan ke anggota biasa
        $this->travelTo($end->copy()->subDays(7)->addHour());
        $this->run_();
        $this->run_();
        $due = Notification::where('type', 'invoice_due')->pluck('user_id')->sort()->values()->all();
        $this->assertSame(collect([$this->owner->id, $this->billing->id])->sort()->values()->all(), $due);
        $this->assertSame(1, DB::table('invoices')->where('subscription_id', $s->id)->count());

        $this->travelTo($end->copy()->subHours(20));
        $this->run_();
        $this->run_();
        $this->assertSame([...$expected('h30'), ...$expected('h1')], $this->reminders());
        $this->assertFalse(Notification::where('user_id', $this->member->id)->whereIn('type', ['subscription_reminder', 'invoice_due'])->exists());
        $this->assertContains('h1', array_map(fn ($k) => explode(':', $k)[1], $s->fresh()->reminders_sent));

        // periode berikutnya (sudah diperpanjang) mulai bersih
        $s->fresh()->update(['current_period_start' => $end, 'current_period_end' => $end->copy()->addMonthsNoOverflow(6)]);
        $this->travelTo($end->copy()->addMonthsNoOverflow(6)->subDays(29));
        $this->run_();
        $this->assertCount(6, $this->reminders());
        Mail::assertQueuedCount(8); // 3 pengingat + 1 invoice + 1 pengingat periode baru, ke 2 orang (email default menyala) — tanpa duplikat
    }

    public function test_h7_reminder_when_invoice_already_exists_and_trial_gets_its_own_copy(): void
    {
        // uji coba: hanya H-1 dengan salinan sendiri (tidak ada H-30 walau periode < 30 hari)
        $end = $this->sub()->trial_ends_at;
        $this->travelTo($end->copy()->subDays(10));
        $this->run_();
        $this->assertSame([], $this->reminders());
        $this->travelTo($end->copy()->subHours(12));
        $this->run_();
        $this->run_();
        $this->assertSame([[$this->owner->id, 'trial'], [$this->billing->id, 'trial']], $this->reminders('trial_ending'));
        $this->assertSame([], $this->reminders());

        // aktif + invoice sudah dibuat sendiri: H-7 tetap mengingatkan sekali
        $this->travelBack();
        $s = $this->activate();
        DB::table('invoices')->insert(['public_id' => (string) str()->ulid(), 'tenant_id' => $this->org->id, 'number' => 'INV/X/1', 'type' => 'subscription', 'status' => 'issued',
            'subscription_id' => $s->id, 'plan_id' => $s->plan_id, 'cycle' => 'semiannual', 'lines' => '[]', 'subtotal' => 1, 'total' => 1,
            'issued_at' => now(), 'due_at' => $s->current_period_end, 'created_at' => now(), 'updated_at' => now()]);
        $this->travelTo($s->current_period_end->copy()->subDays(6));
        $this->run_();
        $this->run_();
        $this->assertSame([[$this->owner->id, 'h7'], [$this->billing->id, 'h7']], $this->reminders());
    }

    public function test_personal_subscriptions_get_no_company_reminders(): void
    {
        $rina = $this->makeUser();
        $plan = DB::table('plans')->where('key', 'personal_pro')->value('id');
        $s = Subscription::create(['tenant_id' => $rina->personal_tenant_id, 'plan_id' => $plan, 'cycle' => 'monthly', 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addDays(20)]);
        $this->run_();
        $this->travelTo($s->current_period_end->copy()->subHours(10));
        $this->run_();
        $this->assertFalse(Notification::whereIn('type', ['subscription_reminder', 'trial_ending'])->where('user_id', $rina->id)->exists());
        $this->assertSame([$rina->id], Notification::where('type', 'invoice_due')->pluck('user_id')->all(), 'personal: invoice H-7 ke pemilik saja seperti sebelumnya');
    }

    public function test_old_crm_interest_moves_to_crm_other_with_its_note(): void
    {
        $path = 'database/migrations/2026_12_09_000100_reminders_and_integration_addons.php';
        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);
        $crm = DB::table('addons')->where('key', 'crm')->value('id');
        DB::table('addon_interests')->insert(['public_id' => (string) str()->ulid(), 'addon_id' => $crm, 'tenant_id' => $this->org->id, 'user_id' => $this->owner->id, 'note' => 'HubSpot']);

        Artisan::call('migrate', ['--path' => $path, '--force' => true]);
        $other = DB::table('addons')->where('key', 'crm_other')->value('id');
        $this->assertDatabaseHas('addon_interests', ['addon_id' => $other, 'tenant_id' => $this->org->id, 'note' => 'HubSpot']);
        $this->assertSame('retired', DB::table('addons')->where('key', 'crm')->value('status'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('subscriptions', 'reminders_sent'));
    }

    public function test_webhook_page_is_coming_soon_for_company_admins(): void
    {
        $this->actingAs($this->owner);
        $list = $this->json('GET', $this->host('majujaya', '/api/addons?group=webhook'))->assertOk()->json('data');
        $this->assertSame([['webhooks', 'webhook', 'coming_soon']], array_map(fn ($a) => [$a['key'], $a['group'], $a['status']], $list));
        $this->json('POST', $this->host('majujaya', '/api/addons/webhooks/interest'), ['note' => 'ERP'])->assertOk()->assertJsonPath('data.interested_at', fn ($v) => $v !== null);
        $this->assertDatabaseHas('addon_interests', ['tenant_id' => $this->org->id, 'note' => 'ERP']);
        $this->assertNotContains('webhooks', collect($this->json('GET', $this->host('majujaya', '/api/addons'))->json('data'))->pluck('key')->all());

        $this->actingAs($this->member);
        $this->json('GET', $this->host('majujaya', '/api/addons?group=webhook'))->assertForbidden();
    }
}
