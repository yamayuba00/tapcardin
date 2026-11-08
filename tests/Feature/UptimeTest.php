<?php

namespace Tests\Feature;

use App\Events\MonitorChecked;
use App\Events\StatusPageUpdated;
use App\Mail\NotificationMail;
use App\Models\Monitor;
use App\Models\PlatformStaff;
use App\Models\Role;
use App\Models\User;
use App\Support\Uptime\Checker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Pemantauan uptime: cek HTTP, status & notifikasi, dasbor panel, insiden, dan halaman status publik. */
class UptimeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Event::fake([MonitorChecked::class, StatusPageUpdated::class]);
        Monitor::query()->delete(); // tanpa monitor bawaan migrasi
    }

    private function staff(string $role = 'platform_owner'): User
    {
        $u = $this->makeUser("$role@tapcard.test", 'Staf');
        PlatformStaff::create(['user_id' => $u->id, 'role_id' => Role::system($role)->id, 'status' => 'active', 'last_reverified_at' => now()]);

        return $u;
    }

    private function panel(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function monitor(array $over = []): Monitor
    {
        return Monitor::create($over + ['name' => 'API', 'type' => 'http', 'url' => 'https://api.contoh.test/up', 'retries' => 1,
            'public_name' => 'Layanan API', 'is_public' => true]);
    }

    private function checkAll(): void
    {
        $this->artisan('tapcard:uptime', ['--all' => true])->assertSuccessful();
    }

    /** @return list<string> */
    private function mailed(): array
    {
        return Mail::sent(NotificationMail::class)->map->type->unique()->values()->all();
    }

    public function test_goes_down_only_after_retries_then_recovers_with_emails_and_realtime(): void
    {
        $m = $this->monitor();
        Http::fake(['api.contoh.test/*' => Http::sequence()
            ->push('ok', 200)->push('rusak', 503)->push('rusak', 503)->push('rusak', 503)->push('ok', 200)]);

        $this->checkAll();
        $this->assertSame('up', $m->fresh()->status);
        $this->checkAll();
        $this->assertSame('up', $m->fresh()->status, 'gagal pertama: belum down (retries = 1)');
        $this->assertSame([], $this->mailed());
        $this->checkAll();
        $this->assertSame('down', $m->fresh()->status);
        $this->assertSame('Kode 503 (diharapkan 200-399)', $m->fresh()->last_error);
        $this->assertSame(['monitor_down'], $this->mailed());
        $this->checkAll();
        $this->assertCount(1, Mail::sent(NotificationMail::class, fn ($x) => $x->type === 'monitor_down' && $x->hasTo('owner@tapcard.test')), 'tidak diulang');

        $this->checkAll();
        $this->assertSame('up', $m->fresh()->status);
        $this->assertSame(['monitor_down', 'monitor_up'], $this->mailed());
        $this->assertSame(5, $m->checks()->count());
        $this->assertSame(['pending>up', 'up>down', 'down>up'], $m->events()->orderBy('id')->get()->map(fn ($e) => "$e->from_status>$e->to_status")->all());
        Event::assertDispatched(MonitorChecked::class, 5);
        Event::assertDispatched(MonitorChecked::class, fn ($e) => $e->change === ['from' => 'up', 'to' => 'down', 'reason' => 'Kode 503 (diharapkan 200-399)']);
        Event::assertDispatched(StatusPageUpdated::class);
    }

    public function test_keyword_expected_codes_and_timeouts(): void
    {
        $kw = $this->monitor(['url' => 'https://a.test/', 'keyword' => 'Selamat datang', 'retries' => 0]);
        $code = $this->monitor(['url' => 'https://b.test/', 'expected_status' => '301,302', 'retries' => 0]);
        $dead = $this->monitor(['url' => 'https://c.test/', 'retries' => 0]);
        Http::fake([
            'a.test/*' => Http::response('<h1>Halaman pemeliharaan</h1>'),
            'b.test/*' => Http::response('', 302),
            'c.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);
        $this->checkAll();
        $this->assertSame(['down', 'Kata kunci “Selamat datang” tidak ditemukan'], [$kw->fresh()->status, $kw->fresh()->last_error]);
        $this->assertSame('up', $code->fresh()->status);
        $this->assertSame(['down', 'Tidak menjawab dalam 10 detik'], [$dead->fresh()->status, $dead->fresh()->last_error]);
        $this->assertTrue(Checker::statusMatches(204, '200-299,301'));
        $this->assertFalse(Checker::statusMatches(404, '200-399'));
    }

    public function test_panel_lists_stats_and_only_managers_can_change(): void
    {
        $this->monitor();
        Http::fake(['*' => Http::response('ok')]);
        $this->checkAll();

        $this->actingAs($this->staff('production'));
        $this->panel('GET', '/uptime')->assertOk(); // semua staf operasional boleh melihat
        $this->panel('POST', '/uptime/monitors', ['name' => 'X', 'type' => 'http', 'url' => 'https://x.test'])->assertForbidden();
        $this->actingAs($this->staff());
        $list = $this->panel('GET', '/uptime')->assertOk()->json('data');
        $this->assertSame(100.0, (float) $list['monitors'][0]['uptime_24h']);
        $this->assertCount(1, $list['monitors'][0]['recent']);
        $this->assertStringStartsWith('http://status.localhost', $list['status_url']);

        $this->panel('POST', '/uptime/monitors', ['name' => 'X', 'type' => 'http', 'url' => 'ftp://x', 'interval_seconds' => 5])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['url', 'interval_seconds']]]);
        $id = $this->panel('POST', '/uptime/monitors', ['name' => 'Webhook', 'type' => 'http', 'url' => 'https://pay.test/hook', 'expected_status' => '200, 405'])
            ->assertCreated()->assertJsonPath('data.expected_status', '200,405')->json('data.id');
        $this->panel('PATCH', "/uptime/monitors/$id", ['is_active' => false])->assertJsonPath('data.status', 'paused');
        $this->panel('PATCH', "/uptime/monitors/$id", ['is_active' => true])->assertJsonPath('data.status', 'pending');
        $this->panel('POST', "/uptime/monitors/$id/check")->assertOk()->assertJsonPath('data.monitor.status', 'up');
        $this->panel('GET', "/uptime/monitors/$id")->assertJsonCount(1, 'data.checks');
        $this->panel('DELETE', "/uptime/monitors/$id")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'uptime.monitor_created']);
    }

    public function test_public_status_page_shows_public_monitors_and_incidents_without_internal_details(): void
    {
        $this->monitor();
        $this->monitor(['name' => 'Database internal', 'url' => 'https://db.internal.test/', 'is_public' => false]);
        Http::fake(['*' => Http::response('', 500)]);
        $this->checkAll();
        $this->checkAll();

        $this->actingAs($this->staff());
        $inc = $this->panel('POST', '/uptime/incidents', ['kind' => 'incident', 'title' => 'API lambat', 'impact' => 'minor', 'status' => 'investigating', 'body' => 'Sedang kami periksa.'])
            ->assertCreated()->json('data.id');
        $this->panel('POST', '/uptime/incidents', ['kind' => 'maintenance', 'title' => 'Pemeliharaan', 'impact' => 'minor', 'status' => 'resolved', 'body' => 'x'])->assertStatus(422);

        $this->getJson($this->host('status', '/api/status'))->assertOk();
        // permintaan kedua dari cache: tetap array (cache tidak meng-unserialize objek)
        $res = $this->getJson($this->host('status', '/api/status'))->assertOk()->assertJsonPath('data.monitors.0.days.0.uptime', null);
        $this->getJson('http://localhost/api/status')->assertOk()->assertJsonPath('data.monitors.0.name', 'Layanan API');
        $this->assertSame('major', $res->json('data.overall'));
        $this->assertSame(['Layanan API'], array_column($res->json('data.monitors'), 'name'), 'hanya monitor publik, nama publik');
        $this->assertCount(90, $res->json('data.monitors.0.days'));
        $this->assertSame('API lambat', $res->json('data.incidents.0.title'));
        $body = $res->getContent();
        foreach (['api.contoh.test', 'db.internal', 'Kode 500'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $this->panel('POST', "/uptime/incidents/$inc/updates", ['status' => 'resolved', 'body' => 'Sudah normal.'])->assertJsonPath('data.status_label', 'Selesai');
        $res = $this->getJson($this->host('status', '/api/status'));
        $this->assertSame([], $res->json('data.incidents'));
        $this->assertSame(['resolved', 'investigating'], array_column($res->json('data.history.0.updates'), 'status'));
        $this->get($this->host('status', '/'))->assertOk()->assertSee('id="app"', false);
    }

    public function test_health_endpoint_is_not_swallowed_by_the_spa(): void
    {
        foreach (['app', 'tap', 'panel', 'status'] as $sub) {
            $this->get($this->host($sub, '/up'))->assertOk()->assertDontSee('id="app"', false);
        }
    }
}
