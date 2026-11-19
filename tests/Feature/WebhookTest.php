<?php

namespace Tests\Feature;

use App\Actions\Cards\ChangeCardStatus;
use App\Actions\Leads\CreateConnection;
use App\Enums\CardStatus;
use App\Enums\LeadSource;
use App\Enums\TenantType;
use App\Jobs\DeliverWebhook;
use App\Jobs\RecordTap;
use App\Jobs\TapContext;
use App\Models\Card;
use App\Models\LeadStage;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Webhooks\HostResolver;
use App\Support\Webhooks\WebhookEvents;
use App\Support\Webhooks\Webhooks;
use App\Support\Webhooks\WebhookSender;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Webhook keluar workspace perusahaan: endpoint, SSRF, tanda tangan, payload tiap event, ulang & nonaktif otomatis, log. */
class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Tenant $org;

    private User $owner;

    private const URL = 'https://hook.example.com/tapcard';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public array $map = ['hook.example.com' => ['93.184.216.34'], 'internal.example.com' => ['10.0.0.5'],
                'meta.example.com' => ['169.254.169.254'], 'cgnat.example.com' => ['100.64.1.1'], 'v6.example.com' => ['::1'],
                'mixed.example.com' => ['93.184.216.34', '192.168.1.10']];

            public function ips(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->map[$host] ?? []);
            }
        });
        $this->owner = $this->makeUser('owner@majujaya.id', 'Owner MJ');
        $this->org = $this->company('majujaya', 'business', $this->owner);
    }

    private function company(string $slug, string $plan, User $owner): Tenant
    {
        $t = Tenant::create(['type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => $slug, 'owner_user_id' => $owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', $plan)->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS]);
        app(CurrentTenant::class)->run($t, function () use ($owner) {
            foreach (LeadStage::DEFAULTS as $i => $s) {
                LeadStage::create([...$s, 'sort' => $i]);
            }
            TenantMember::create(['user_id' => $owner->id, 'role_id' => Role::system('owner')->id, 'status' => 'active']);
        });

        return $t;
    }

    private function join(User $user, string $role): void
    {
        app(CurrentTenant::class)->run($this->org, fn () => TenantMember::create(['user_id' => $user->id, 'role_id' => Role::system($role)->id, 'status' => 'active']));
    }

    private function api(string $method, string $path, array $data = [], string $host = 'majujaya')
    {
        return $this->json($method, $this->host($host, "/api$path"), $data);
    }

    private function endpoint(array $events = ['lead.created'], string $url = self::URL): array
    {
        return $this->api('POST', '/webhooks', ['url' => $url, 'events' => $events, 'description' => 'ERP'])->assertCreated()->json('data');
    }

    /** @return list<array> isi body yang terkirim */
    private function sent(): array
    {
        return Http::recorded()->map(fn ($pair) => json_decode($pair[0]->body(), true))->all();
    }

    public function test_crud_validation_ssrf_secret_once_and_limit(): void
    {
        $this->actingAs($this->owner);
        $bad = fn (string $url) => $this->api('POST', '/webhooks', ['url' => $url, 'events' => ['lead.created']])->assertUnprocessable();
        $bad('http://hook.example.com/x');                   // https wajib di luar APP_ENV=local
        $bad('https://internal.example.com/x');              // IP privat
        $bad('https://meta.example.com/latest/meta-data');   // metadata cloud
        $bad('https://cgnat.example.com/x');                 // CGNAT
        $bad('https://v6.example.com/x');                    // loopback IPv6
        $bad('https://mixed.example.com/x');                 // salah satu IP privat
        $bad('https://127.0.0.1/x');
        $bad('https://user:pass@hook.example.com/x');
        $bad('https://hook.example.com:22/x');
        $bad('https://tidak-ada.example.com/x');             // DNS tidak ditemukan
        $this->api('POST', '/webhooks', ['url' => self::URL, 'events' => ['lead.hapus']])->assertUnprocessable();
        $this->api('POST', '/webhooks', ['url' => self::URL, 'events' => []])->assertUnprocessable();

        $e = $this->endpoint(['lead.created', 'lead.created', 'tap.recorded']);
        $this->assertStringStartsWith('whsec_', $e['secret']);
        $this->assertSame(['lead.created', 'tap.recorded'], $e['events']);
        $raw = DB::table('webhook_endpoints')->where('public_id', $e['id'])->value('secret');
        $this->assertNotSame($e['secret'], $raw, 'rahasia disimpan terenkripsi');

        $list = $this->api('GET', '/webhooks')->assertOk()->json('data');
        $this->assertArrayNotHasKey('secret', $list[0]);
        $this->assertSame('whsec_…'.substr($e['secret'], -4), $list[0]['secret_hint']);

        $this->api('PATCH', "/webhooks/{$e['id']}", ['events' => ['lead.updated'], 'is_active' => false])->assertOk()
            ->assertJsonPath('data.events', ['lead.updated'])->assertJsonPath('data.is_active', false);
        $rot = $this->api('POST', "/webhooks/{$e['id']}/secret")->assertOk()->json('data.secret');
        $this->assertNotSame($e['secret'], $rot);
        $this->assertSame($rot, WebhookEndpoint::acrossTenants()->where('public_id', $e['id'])->first()->secret);

        for ($i = 1; $i < WebhookEndpoint::MAX_PER_WORKSPACE; $i++) {
            $this->endpoint();
        }
        $this->api('POST', '/webhooks', ['url' => self::URL, 'events' => ['lead.created']])->assertJsonPath('error.code', 'webhook_limit');
        $this->api('DELETE', "/webhooks/{$e['id']}")->assertNoContent();
        $this->assertDatabaseMissing('webhook_endpoints', ['public_id' => $e['id']]);
    }

    public function test_permission_plan_gate_and_tenant_isolation(): void
    {
        $member = $this->makeUser('sales@majujaya.id', 'Sales');
        $this->join($member, 'member');
        $this->actingAs($member);
        $this->api('GET', '/webhooks')->assertForbidden();

        $other = $this->makeUser('owner@lain.id', 'Owner Lain');
        $this->company('lain', 'starter', $other); // paket tanpa fitur webhooks
        $this->actingAs($other);
        $this->api('GET', '/webhooks', host: 'lain')->assertForbidden();
        $this->api('GET', '/webhooks', host: 'app')->assertNotFound(); // workspace personal

        $this->actingAs($this->owner);
        $e = $this->endpoint();
        $biz = $this->makeUser('owner@biz.id', 'Biz');
        $this->company('bizlain', 'business', $biz);
        $this->actingAs($biz);
        $this->api('PATCH', "/webhooks/{$e['id']}", ['is_active' => false], 'bizlain')->assertNotFound();
        $this->api('GET', "/webhooks/{$e['id']}/deliveries", host: 'bizlain')->assertNotFound();
        $this->assertSame([], $this->api('GET', '/webhooks', host: 'bizlain')->json('data'));
    }

    public function test_ping_signature_headers_and_no_redirects(): void
    {
        Http::fake(['hook.example.com/*' => Http::sequence()->push(['ok' => true], 200)
            ->push('', 302, ['Location' => 'http://169.254.169.254/'])]);
        $this->actingAs($this->owner);
        $e = $this->endpoint();
        $d = $this->api('POST', "/webhooks/{$e['id']}/test")->assertOk()->json('data');
        $this->assertSame(['success', 200, 1], [$d['status'], $d['http_status'], $d['attempts']]);
        $this->assertSame('ping', json_decode($d['request_body'], true)['type']);

        Http::assertSent(function (HttpRequest $r) use ($e, $d) {
            $ts = $r->header('X-TapCard-Timestamp')[0];
            $expected = 'v1='.hash_hmac('sha256', $ts.'.'.$r->body(), $e['secret']);

            return $r->url() === self::URL && $r->method() === 'POST'
                && $r->header('X-TapCard-Signature')[0] === $expected
                && $r->header('X-TapCard-Event')[0] === 'ping'
                && $r->header('X-TapCard-Delivery')[0] === $d['id']
                && $r->header('User-Agent')[0] === 'TapCard-Webhooks/1.0'
                && $r->hasHeader('Content-Type', 'application/json')
                && abs(now()->timestamp - (int) $ts) < 60;
        });

        $d = $this->api('POST', "/webhooks/{$e['id']}/test")->assertOk()->json('data');
        $this->assertSame(['failed', 302], [$d['status'], $d['http_status']]);
        $this->assertCount(2, Http::recorded(), 'redirect tidak diikuti');
        $this->assertStringContainsString('redirect', $d['error']);
        $this->assertSame(0, WebhookEndpoint::acrossTenants()->first()->failure_count, 'uji kirim tidak dihitung sebagai gagal');
    }

    public function test_every_event_payload_matches_docs_and_has_no_internal_ids_or_secrets(): void
    {
        Http::fake(['hook.example.com/*' => Http::response('ok', 200)]);
        $this->actingAs($this->owner);
        $this->endpoint(WebhookEvents::keys());
        $sari = $this->makeUser('sari@example.com', 'Sari');

        // lead.created / lead.updated / lead.stage_changed
        $lead = $this->api('POST', '/leads', ['name' => 'Dewi', 'email' => 'dewi@contoh.com', 'phone' => '081234567890'])->assertCreated()->json('data.id');
        $this->api('PATCH', "/leads/$lead", ['company' => 'PT Baru'])->assertOk();
        $won = app(CurrentTenant::class)->run($this->org, fn () => LeadStage::where('kind', 'won')->value('public_id'));
        $this->api('POST', "/leads/$lead/stage", ['stage_id' => $won])->assertOk();
        // member.*
        $this->api('POST', '/company/members', ['email' => 'sari@example.com', 'role' => 'member'])->assertCreated();
        $this->api('PATCH', "/company/members/{$sari->public_id}", ['role' => 'manager'])->assertOk();
        // lead.assigned
        $this->api('PUT', '/leads/assign', ['ids' => [$lead], 'user' => $sari->public_id])->assertOk()->assertJsonPath('data.updated', 1);
        // card.assigned / card.unassigned
        $card = $this->makeCard();
        $card->forceFill(['tenant_id' => $this->org->id])->save();
        $this->api('PUT', "/company/cards/{$card->card_token}/assign", ['user' => $sari->public_id])->assertOk();
        $this->api('DELETE', "/company/cards/{$card->card_token}/assign")->assertOk();
        $this->api('POST', "/company/members/{$sari->public_id}/deactivate")->assertOk();
        $this->api('POST', "/company/members/{$sari->public_id}/reactivate")->assertOk();
        // card.activated / card.status_changed
        app(CurrentTenant::class)->run($this->org, function () use ($card, $sari) {
            $c = Card::find($card->id);
            app(ChangeCardStatus::class)->handle($c, CardStatus::Active, 'Aktivasi', extra: ['holder_user_id' => $sari->id, 'profile_id' => $this->profileOf($sari)->id]);
            app(ChangeCardStatus::class)->handle($c->fresh(), CardStatus::Paused, 'Cuti');
            // contact.exchanged
            app(CreateConnection::class)->handle(['name' => 'Tamu', 'email' => 'tamu@contoh.com', 'consent' => true], LeadSource::ExchangeForm, $sari->id, ['card_id' => $c->id]);
        });
        // tap.recorded
        (new RecordTap($this->org->id, $card->id, new TapContext(source: 'nfc', sunResult: null, ipHash: 'IPHASHRAHASIA', visitorHash: 'VISITORRAHASIA',
            country: 'ID', city: 'Jakarta', device: 'mobile', browser: 'Chrome', at: now()->toIso8601String())))->handle();

        // event.checkin
        $attendee = $this->makeCard(CardStatus::Active, $sari);
        $attendee->forceFill(['tenant_id' => $this->org->id])->save();
        $event = $this->api('POST', '/events', ['name' => 'Expo', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String()])->assertCreated()->json('data.id');
        $this->api('POST', "/events/$event/checkins", ['value' => config('tapcard.resolver_url')."/c/{$attendee->card_token}", 'method' => 'code'])->assertCreated();

        $byType = collect($this->sent())->keyBy('type');
        $catalog = WebhookEvents::all();
        foreach ($catalog as $type => $def) {
            $this->assertTrue($byType->has($type), "event $type terkirim");
            $p = $byType[$type];
            $this->assertSame(['id', 'type', 'created_at', 'workspace', 'data'], array_keys($p));
            $this->assertSame(['id' => $this->org->public_id, 'name' => 'Maju Jaya', 'slug' => 'majujaya'], $p['workspace']);
            $this->assertShape($def['sample'], $p['data'], $type);
        }
        $all = json_encode($this->sent());
        foreach (['IPHASHRAHASIA', 'VISITORRAHASIA', 'whsec_', 'password'] as $leak) {
            $this->assertStringNotContainsString($leak, $all);
        }
        $this->assertDoesNotMatchRegularExpression('/"id":\d+/', $all, 'tidak ada ID numerik');
        $this->assertSame(['company'], $byType['lead.updated']['data']['changes']);
        $this->assertSame('won', $byType['lead.stage_changed']['data']['to_stage']['kind']);
        $this->assertSame(['active', 'paused'], [$byType['card.status_changed']['data']['from'], $byType['card.status_changed']['data']['to']]);
    }

    /** Bentuk payload asli = contoh di dokumentasi (kunci sama, rekursif untuk objek). */
    private function assertShape(array $sample, array $actual, string $path): void
    {
        $this->assertEqualsCanonicalizing(array_keys($sample), array_keys($actual), "kunci $path");
        foreach ($sample as $k => $v) {
            if (is_array($v) && $v && ! array_is_list($v) && is_array($actual[$k] ?? null)) {
                $this->assertShape($v, $actual[$k], "$path.$k");
            }
        }
    }

    public function test_retries_with_backoff_then_failed_and_auto_disable_notifies_admins(): void
    {
        Http::fake(['hook.example.com/*' => Http::response('down', 503)]);
        $this->actingAs($this->owner);
        $e = $this->endpoint();
        $this->api('POST', '/leads', ['name' => 'Dewi'])->assertCreated();

        $d = WebhookDelivery::acrossTenants()->sole();
        $this->assertSame(['retrying', 1, 503], [$d->status, $d->attempts, $d->http_status]);
        $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, $d->next_retry_at->timestamp, 2);
        $sender = app(WebhookSender::class);
        foreach (array_slice(Webhooks::BACKOFF, 1) as $i => $delay) {
            $this->assertSame($delay, $sender->attempt($d->fresh('endpoint')), 'jeda ke-'.($i + 2));
        }
        $this->assertNull($sender->attempt($d->fresh('endpoint')));
        $d = $d->fresh();
        $this->assertSame(['failed', WebhookSender::MAX_ATTEMPTS], [$d->status, $d->attempts]);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // gagal 20x berturut-turut → endpoint dinonaktifkan + owner/admin diberi tahu; sukses mengatur ulang hitungan
        $admin = $this->makeUser('admin@majujaya.id', 'Admin');
        $this->join($admin, 'admin');
        $ep = WebhookEndpoint::acrossTenants()->where('public_id', $e['id'])->first();
        $ep->forceFill(['failure_count' => WebhookEndpoint::DISABLE_AFTER - 1])->save();
        $this->api('POST', '/leads', ['name' => 'Eka'])->assertCreated();
        $ep->refresh();
        $this->assertFalse($ep->is_active);
        $this->assertNotNull($ep->disabled_at);
        $this->assertEqualsCanonicalizing([$this->owner->id, $admin->id],
            Notification::where('type', 'webhook_disabled')->pluck('user_id')->all());
        $this->assertSame([], Webhooks::endpoints($this->org->id), 'cache endpoint aktif dibersihkan');

        Http::fake(['hook.example.com/*' => Http::response('ok', 200)]);
        $this->api('PATCH', "/webhooks/{$e['id']}", ['is_active' => true])->assertOk()->assertJsonPath('data.failure_count', 0)
            ->assertJsonPath('data.disabled_at', null);
    }

    public function test_redeliver_log_filters_queue_after_commit_and_prune(): void
    {
        Http::fake(['hook.example.com/*' => Http::response('ok', 200)]);
        $this->actingAs($this->owner);
        $e = $this->endpoint(['lead.created', 'lead.updated']);
        $lead = $this->api('POST', '/leads', ['name' => 'Dewi'])->assertCreated()->json('data.id');
        $this->api('PATCH', "/leads/$lead", ['company' => 'PT Baru'])->assertOk();

        $log = $this->api('GET', "/webhooks/{$e['id']}/deliveries")->assertOk()->json('data');
        $this->assertSame(['lead.updated', 'lead.created'], array_column($log, 'type'));
        $this->assertArrayNotHasKey('request_body', $log[0]);
        $this->assertCount(1, $this->api('GET', "/webhooks/{$e['id']}/deliveries?type=lead.created")->json('data'));
        $this->assertCount(0, $this->api('GET', "/webhooks/{$e['id']}/deliveries?status=failed")->json('data'));
        $detail = $this->api('GET', "/webhook-deliveries/{$log[1]['id']}")->assertOk()->json('data');
        $this->assertSame('ok', $detail['response_body']);

        $copy = $this->api('POST', "/webhook-deliveries/{$log[1]['id']}/redeliver")->assertStatus(202)->json('data');
        $this->assertNotSame($log[1]['id'], $copy['id']);
        $this->assertSame($log[1]['event_id'], $copy['event_id']);
        $this->assertSame('success', WebhookDelivery::acrossTenants()->where('public_id', $copy['id'])->value('status'));
        $this->assertEquals(100, $this->api('GET', '/webhooks')->json('data.0.success_rate_7d'));

        // dikirim setelah commit: selama transaksi belum selesai tidak ada HTTP; rollback = tidak ada delivery & tidak terkirim
        $before = count(Http::recorded());
        try {
            DB::transaction(function () use ($before) {
                app(CurrentTenant::class)->run($this->org, fn () => app(CreateConnection::class)->handle(['name' => 'Batal'], LeadSource::Manual, $this->owner->id));
                $this->assertCount($before, Http::recorded(), 'belum dikirim sebelum commit');
                throw new \RuntimeException('batal');
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount($before, Http::recorded());
        $this->assertSame(0, WebhookDelivery::acrossTenants()->where('body', 'like', '%Batal%')->count());

        Queue::fake();
        app(CurrentTenant::class)->run($this->org, fn () => app(CreateConnection::class)->handle(['name' => 'Jadi'], LeadSource::Manual, $this->owner->id));
        Queue::assertPushedOn('webhooks', DeliverWebhook::class);

        WebhookDelivery::acrossTenants()->update(['created_at' => now()->subDays(31)]);
        Artisan::call('tapcard:prune');
        $this->assertSame(0, WebhookDelivery::acrossTenants()->count());
    }

    public function test_catalog_lists_events_with_samples_and_no_deliveries_without_endpoints(): void
    {
        $this->actingAs($this->owner);
        $c = $this->api('GET', '/webhooks/catalog')->assertOk()->json('data');
        $this->assertSame(WebhookEvents::keys(), array_column($c['events'], 'key'));
        $this->assertSame('majujaya', $c['events'][0]['sample']['workspace']['slug']);
        $this->assertSame(Webhooks::BACKOFF, $c['retry']);
        $this->assertTrue(collect($c['events'])->firstWhere('key', 'tap.recorded')['volume']);

        Http::fake();
        $this->api('POST', '/leads', ['name' => 'Tanpa endpoint'])->assertCreated();
        $this->assertSame(0, WebhookDelivery::acrossTenants()->count());
        Http::assertNothingSent();
    }
}
