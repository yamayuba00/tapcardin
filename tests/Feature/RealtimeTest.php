<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Support\Notify;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Notifikasi realtime lewat Reverb: siaran ke channel privat pemilik + otorisasi channel hanya untuk pemiliknya. */
class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function useReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'testkey', 'secret' => 'testsecret', 'app_id' => '1',
                'options' => ['host' => 'localhost', 'port' => 8081, 'scheme' => 'http', 'useTLS' => false]],
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
    }

    public function test_notification_is_broadcast_to_owner_private_channel_with_workspace(): void
    {
        Event::fake([NotificationCreated::class]);
        $rina = $this->makeUser();
        Notify::user($rina->id, $rina->personal_tenant_id, 'lead_new', ['name' => 'Sari']);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $e) use ($rina) {
            $p = $e->broadcastWith();

            return $e->broadcastOn()[0]->name === "private-user.{$rina->public_id}"
                && $e->broadcastAs() === 'notification'
                && $p['type'] === 'lead_new' && $p['data']['name'] === 'Sari'
                && $p['tenant'] === $rina->personalTenant->public_id && $p['id'] > 0;
        });
    }

    public function test_muted_notification_is_neither_stored_nor_broadcast(): void
    {
        Event::fake([NotificationCreated::class]);
        $rina = $this->makeUser();
        $rina->forceFill(['notification_prefs' => ['tap' => ['inapp' => false]]])->save();
        $this->assertNull(Notify::user($rina->id, $rina->personal_tenant_id, 'tap', []));
        Event::assertNotDispatched(NotificationCreated::class);
    }

    public function test_private_channel_is_authorized_only_for_its_owner(): void
    {
        $this->useReverb();
        $rina = $this->makeUser();
        $budi = $this->makeUser('budi@example.com', 'Budi');
        $auth = fn ($channel) => $this->postJson($this->host('app', '/api/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => $channel]);

        $this->postJson($this->host('app', '/api/broadcasting/auth'), ['socket_id' => '1234.5678', 'channel_name' => "private-user.{$rina->public_id}"])->assertUnauthorized();
        $this->actingAs($rina);
        $ok = $auth("private-user.{$rina->public_id}")->assertOk()->json('auth');
        $this->assertStringStartsWith('testkey:', $ok);
        $auth("private-user.{$budi->public_id}")->assertForbidden();
    }

    public function test_spa_receives_realtime_config_and_csp_allows_websocket(): void
    {
        $this->useReverb();
        $res = $this->get($this->host('app', '/login'))->assertOk();
        $this->assertStringContainsString('"realtime":{"key":"testkey","host":"localhost","port":8081,"tls":false}', html_entity_decode($res->getContent()));
        $this->assertStringContainsString('ws://localhost:8081', $res->headers->get('Content-Security-Policy'));
    }
}
