<?php

namespace Tests\Feature;

use App\Authz\PlanFeatures;
use App\Enums\CardStatus;
use App\Models\Connection;
use App\Models\LeadReminder;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\PlatformStaff;
use App\Models\Role;
use App\Models\Tap;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Roadmap v1: pengingat follow-up, Mode Event, impor CSV, QR dinamis, Mode Reader, dashboard lead, tabel server-side. */
class LeadsV1Test extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    private function lead(string $name, array $extra = []): array
    {
        return $this->api('POST', '/leads', ['name' => $name] + $extra)->assertCreated()->json('data');
    }

    private function connections()
    {
        return Connection::withoutGlobalScope(TenantScope::class);
    }

    // ── Pengingat follow-up (LD-05) ──────────────────────────────────

    public function test_new_lead_gets_auto_followup_that_first_activity_cancels(): void
    {
        $this->actingAs($this->makeUser());
        $lead = $this->lead('Budi');
        $r = LeadReminder::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertTrue($r->is_auto);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $r->due_at->timestamp, 5);
        $this->assertNotNull($this->connections()->first()->next_followup_at);

        $this->api('POST', "/leads/{$lead['id']}/activities", ['type' => 'call'])->assertOk()
            ->assertJsonPath('data.reminders', []);
        $this->assertNull($this->connections()->first()->next_followup_at, 'pengingat otomatis batal setelah aktivitas pertama');
    }

    public function test_a_plain_note_keeps_the_auto_followup(): void
    {
        $this->actingAs($this->makeUser());
        $lead = $this->lead('Budi');
        $this->api('POST', "/leads/{$lead['id']}/activities", ['type' => 'note', 'body' => 'Ketemu di booth'])->assertOk()
            ->assertJsonCount(1, 'data.reminders');
    }

    public function test_camera_and_nfc_allowed_only_on_app_and_admin_hosts(): void
    {
        $this->get($this->host('app', '/connections'))->assertHeader('Permissions-Policy', 'camera=(self), nfc=(self)');
        $this->get($this->host('panel', '/'))->assertHeader('Permissions-Policy', 'camera=(self), nfc=(self)');
        $this->get($this->host('tap', '/k'))->assertHeader('Permissions-Policy', 'camera=(), nfc=(), geolocation=()');
        $this->get('http://localhost/')->assertHeader('Permissions-Policy', 'camera=(), nfc=(), geolocation=()');
    }

    public function test_manual_reminders_lists_and_due_notification_is_sent_once(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $lead = $this->lead('Budi');
        $this->api('POST', "/leads/{$lead['id']}/reminders", ['due_at' => now()->subHour()->toIso8601String()])->assertStatus(422);
        $id = $this->api('POST', "/leads/{$lead['id']}/reminders", ['due_at' => now()->addMinutes(5)->toIso8601String(), 'note' => 'Kirim proposal'])
            ->assertCreated()->json('data.id');

        $this->travel(10)->minutes();
        $this->api('GET', '/reminders?due=overdue')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.note', 'Kirim proposal')->assertJsonPath('data.0.lead.name', 'Budi')
            ->assertJsonPath('meta.counts.overdue', 1);
        $this->api('GET', '/leads?due=overdue')->assertJsonCount(1, 'data');

        $this->artisan('tapcard:reminders')->assertSuccessful();
        $this->artisan('tapcard:reminders')->assertSuccessful();
        $this->assertSame(1, Notification::where('user_id', $rina->id)->where('type', 'reminder_due')->count());

        $this->api('PATCH', "/reminders/$id", ['done' => true])->assertOk()->assertJsonPath('data.done_at', fn ($v) => $v !== null);
        $this->api('GET', '/reminders?due=done')->assertJsonCount(1, 'data');

        $other = $this->makeUser('lain@example.com', 'Lain');
        $this->actingAs($other)->api('PATCH', "/reminders/$id", ['done' => false])->assertNotFound();
    }

    // ── Tabel server-side ───────────────────────────────────────────

    public function test_connection_list_is_paged_and_sorted_on_the_server(): void
    {
        $this->actingAs($this->makeUser());
        foreach (['Citra', 'Andi', 'Budi'] as $n) {
            $this->lead($n);
        }
        $res = $this->api('GET', '/leads?sort=name&per_page=10')->assertOk();
        $this->assertSame(['Andi', 'Budi', 'Citra'], array_column($res->json('data'), 'name'));
        $res->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.last_page', 1);
        $this->assertSame(['Citra', 'Budi', 'Andi'], array_column($this->api('GET', '/leads?sort=-name')->json('data'), 'name'));
        $this->assertSame('Budi', $this->api('GET', '/leads?sort=bogus')->json('data.0.name'), 'urutan tak dikenal → bawaan (terbaru dulu)');
        $this->api('GET', '/leads?sort=stage')->assertOk();
    }

    public function test_admin_embedded_tables_are_paged_endpoints(): void
    {
        $owner = $this->makeUser('pemilik-platform@tapcard.test', 'Owner');
        PlatformStaff::create(['user_id' => $owner->id, 'role_id' => Role::system('platform_owner')->id, 'status' => 'active', 'last_reverified_at' => now()]);
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->actingAs($owner);

        $this->getJson($this->host('panel', '/api/staff?sort=-email'))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.email', 'pemilik-platform@tapcard.test')->assertJsonCount(6, 'meta.roles');
        $this->getJson($this->host('panel', "/api/users/{$rina->public_id}/cards"))->assertOk()->assertJsonPath('data.0.token', $card->card_token);
        $this->getJson($this->host('panel', "/api/tenants/{$rina->personalTenant->public_id}/members?sort=name"))->assertOk()->assertJsonPath('data.0.email', $rina->email);
        $this->getJson($this->host('panel', "/api/tenants/{$rina->personalTenant->public_id}/cards"))->assertOk()->assertJsonPath('meta.total', 2); // kartu fisik + kartu digital
        $this->getJson($this->host('panel', "/api/batches/{$card->batch->public_id}/cards?progress=done"))->assertOk()->assertJsonPath('data.0.qc', true);
        $this->getJson($this->host('panel', "/api/batches/{$card->batch->public_id}"))->assertOk()->assertJsonPath('data.counts.tokens', 1)->assertJsonMissingPath('data.cards');
    }

    // ── Mode Event (F-10) ───────────────────────────────────────────

    public function test_event_mode_tags_exchanged_contacts_and_summarises(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->actingAs($rina);
        $ev = $this->api('POST', '/events', [
            'name' => 'Expo UMKM', 'location' => 'JCC', 'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addHours(5)->toIso8601String(), 'show_on_profile' => true, 'activate' => true,
        ])->assertCreated()->assertJsonPath('data.is_active', true)->assertJsonPath('data.status', 'live')->json('data');

        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.event', 'Expo UMKM');
        $this->postJson($this->host('tap', "/api/c/{$card->card_token}/connect"), [
            'name' => 'Budi', 'whatsapp' => '081234567890', 'consent' => true, 'website' => '', 't' => Crypt::encryptString((string) (time() - 10)),
        ])->assertCreated();
        $this->assertNotNull($this->connections()->first()->event_id);

        $this->api('GET', "/events/{$ev['id']}")->assertOk()->assertJsonPath('data.summary.connections', 1)->assertJsonPath('data.summary.taps', 1);
        $this->api('GET', '/events')->assertJsonPath('meta.active.id', $ev['id'])->assertJsonPath('data.0.connections_count', 1);
        $this->api('GET', "/leads?event={$ev['id']}")->assertJsonCount(1, 'data');

        $this->api('DELETE', '/events/active')->assertOk();
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.event', null);
        $this->api('POST', '/events', ['name' => 'X', 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()])->assertStatus(422);
    }

    // ── Impor CSV (LD-10) ───────────────────────────────────────────

    public function test_csv_import_maps_columns_dedups_and_reports(): void
    {
        Storage::fake('local');
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $this->lead('Budi Lama', ['phone' => '081111111111']);

        $csv = "\xEF\xBB\xBFNama;No HP;Email;Perusahaan;Tag\n"
            ."Andi;0822 2222 2222;andi@contoh.id;PT A;vip, expo\n"
            ."Budi;081111111111;;;\n"
            .";0833;;;\n"
            ."Citra;;bukan-email;;\n";
        $up = $this->api('POST', '/leads/imports', ['file' => UploadedFile::fake()->createWithContent('kontak.csv', $csv)])
            ->assertCreated()->assertJsonPath('data.total_rows', 4)->assertJsonPath('data.headers.0', 'Nama')
            ->assertJsonPath('data.mapping.name', 0)->assertJsonPath('data.mapping.phone', 1)->assertJsonPath('data.mapping.email', 2)
            ->assertJsonPath('data.mapping.company', 3)->assertJsonPath('data.mapping.tags', 4)->json('data');

        $this->api('POST', "/leads/imports/{$up['id']}/start", ['mapping' => ['phone' => 1]])->assertStatus(422);
        $this->api('POST', "/leads/imports/{$up['id']}/start", ['mapping' => ['name' => 0, 'phone' => 0]])->assertJsonPath('error.code', 'mapping_duplicate');
        $this->api('POST', "/leads/imports/{$up['id']}/start", ['mapping' => $up['mapping']])->assertOk();

        $this->api('GET', "/leads/imports/{$up['id']}")->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.created', 1)->assertJsonPath('data.merged', 1)->assertJsonPath('data.failed', 2)
            ->assertJsonPath('data.errors.0.row', 4);
        $andi = $this->connections()->where('name', 'Andi')->firstOrFail();
        $this->assertSame(['vip', 'expo'], $andi->tags);
        $this->assertSame('import', $andi->source->value);
        $this->assertSame(0, LeadReminder::withoutGlobalScope(TenantScope::class)->where('connection_id', $andi->id)->count(), 'impor massal tanpa pengingat per baris');
        $this->assertDatabaseHas('notifications', ['user_id' => $rina->id, 'type' => 'import_done']);
        $this->api('POST', "/leads/imports/{$up['id']}/start", ['mapping' => $up['mapping']])->assertStatus(409);
        $this->api('POST', '/leads/imports', ['file' => UploadedFile::fake()->createWithContent('kosong.csv', "Nama\n")])->assertJsonPath('error.code', 'import_empty');
    }

    // ── QR dinamis (F-11) ───────────────────────────────────────────

    public function test_dynamic_qr_is_plan_gated_single_use_and_expires(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->actingAs($rina);
        $this->api('POST', "/cards/{$card->card_token}/dynamic-qr")->assertStatus(402)->assertJsonPath('error.code', 'feature_locked');

        $rina->personalTenant->update(['plan_id' => Plan::where('key', 'personal_pro')->value('id')]);
        $rina->personalTenant->unsetRelation('plan'); // actingAs memakai objek user yang sama di semua request
        PlanFeatures::forget($rina->personalTenant);
        $qr = $this->api('POST', "/cards/{$card->card_token}/dynamic-qr")->assertOk()->assertJsonPath('data.ttl', 60)->json('data');
        $path = parse_url($qr['url'], PHP_URL_PATH);
        $this->assertGreaterThan(20, $qr['qr']['modules']);

        $this->get($this->host('tap', $path))->assertRedirect("http://tap.localhost/c/{$card->card_token}?src=dynamic_qr");
        $this->get($this->host('tap', $path))->assertStatus(410)->assertSee('"state":"expired"', false);

        $late = parse_url($this->api('POST', "/cards/{$card->card_token}/dynamic-qr")->json('data.url'), PHP_URL_PATH);
        $this->travel(61)->seconds();
        $this->get($this->host('tap', $late))->assertStatus(410);
        $this->get($this->host('tap', '/q/AAAAAAAAAAAAAAAAAAAAAAAA'))->assertStatus(410);
    }

    // ── Mode Reader (F-09) ──────────────────────────────────────────

    public function test_reader_saves_tapcard_owner_two_way_and_external_cards(): void
    {
        $rina = $this->makeUser();
        $budi = $this->makeUser('budi@example.com', 'Budi Santoso');
        $rinaCard = $this->makeCard(CardStatus::Active, $rina);
        $budiCard = $this->makeCard(CardStatus::Active, $budi);
        $this->inTenantOf($budi, fn () => $this->profileOf($budi)->update(['company' => 'PT Budi', 'title' => 'Direktur']));

        $this->actingAs($rina);
        $this->api('POST', '/scans', ['value' => $rinaCard->resolverUrl()])->assertJsonPath('error.code', 'own_card');
        $this->api('POST', '/scans', ['value' => $budiCard->qrUrl(), 'tags' => ['expo']])->assertCreated()
            ->assertJsonPath('data.kind', 'tapcard')->assertJsonPath('data.connection.name', 'Budi Santoso')
            ->assertJsonPath('data.connection.company', 'PT Budi')->assertJsonPath('data.connection.source', 'scan_tapcard');
        $this->api('POST', '/scans', ['value' => 'tc·'.strtolower($budiCard->formattedShortCode())])->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertDatabaseHas('notifications', ['user_id' => $budi->id, 'type' => 'saved_back']);
        $this->assertSame(2, Tap::withoutGlobalScope(TenantScope::class)->where('card_id', $budiCard->id)->where('source', 'app')->count(), 'setiap scan Mode Reader tercatat sebagai tap bersumber app');
        $this->assertSame(1, Notification::where('user_id', $budi->id)->where('type', 'saved_back')->count(), 'scan ulang tidak memberi tahu dua kali');

        // pemilik kartu yang di-scan bisa "Simpan balik"
        $this->actingAs($budi);
        $this->api('GET', '/scans/inbound')->assertJsonPath('data.0.name', 'Rina Saputra')->assertJsonPath('data.0.saved_back', false);
        $this->api('POST', '/scans', ['value' => $this->api('GET', '/scans/inbound')->json('data.0.card')])->assertCreated();
        $this->api('GET', '/scans/inbound')->assertJsonPath('data.0.saved_back', true);

        // kartu merek lain / teks bebas
        $this->actingAs($rina);
        $this->api('POST', '/scans', ['value' => 'https://kartu-lain.com/u/andi'])->assertStatus(422)->assertJsonPath('error.code', 'name_required');
        $this->api('POST', '/scans', ['value' => 'https://kartu-lain.com/u/andi', 'name' => 'Andi'])->assertCreated()
            ->assertJsonPath('data.kind', 'external')->assertJsonPath('data.connection.source', 'scan_external');
        $this->api('POST', '/scans', ['value' => 'http://tap.localhost/c/ZZZZZZZZzz'])->assertNotFound();
    }

    // ── Dashboard lead ──────────────────────────────────────────────

    public function test_lead_dashboard_layout_is_customisable_and_scoped(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $this->lead('Andi', ['company' => 'PT A']);
        $this->lead('Budi');
        $this->actingAs($this->makeUser('lain@example.com', 'Lain'));
        $this->lead('Bukan milik Rina');

        $this->actingAs($rina);
        $d = $this->api('GET', '/leads/dashboard')->assertOk()->assertJsonPath('data.days', 30)->json('data');
        $this->assertSame(2, $d['widgets']['kpi_total']['value']);
        $this->assertSame(2, array_sum(array_column($d['widgets']['new_by_day']['series'], 'value')));
        $this->assertCount(30, $d['widgets']['new_by_day']['series']);
        $this->assertSame(['overdue' => 0, 'today' => 0], $d['widgets']['kpi_followup'], 'pengingat otomatis 24 jam belum jatuh tempo');
        $this->assertSame([['label' => 'Input manual', 'value' => 2]], $d['widgets']['by_source']['bars']);

        $this->api('PUT', '/leads/dashboard/layout', ['widgets' => [['id' => 'nope', 'size' => 1]], 'days' => 30])->assertStatus(422);
        $saved = $this->api('PUT', '/leads/dashboard/layout', ['widgets' => [['id' => 'top_tags', 'size' => 2], ['id' => 'kpi_value', 'size' => 1]], 'days' => 7])
            ->assertOk()->assertJsonPath('data.days', 7)->json('data');
        $this->assertSame(['top_tags', 'kpi_value'], array_column($saved['layout'], 'id'));
        $this->assertSame(['top_tags', 'kpi_value'], array_keys($saved['widgets']), 'hanya widget yang dipasang yang dihitung');
        $this->assertSame(7, User::find($rina->id)->preferences['lead_dashboard']['days']);
    }
}
