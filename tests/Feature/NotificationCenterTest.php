<?php

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pusat notifikasi (lonceng): daftar berhalaman, belum dibaca, tandai satu / semua, terpisah per workspace & pengguna. */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_list_is_paged_and_marking_read_updates_unread_count(): void
    {
        $rina = $this->makeUser();
        $budi = $this->makeUser('budi@example.com', 'Budi');
        $tid = $rina->personal_tenant_id;
        foreach (range(1, 35) as $i) {
            Notification::create(['user_id' => $rina->id, 'tenant_id' => $tid, 'type' => 'tap', 'data' => ['card' => 'ABCDEFGHIJ', 'city' => "Kota $i"]]);
        }
        Notification::create(['user_id' => $rina->id, 'tenant_id' => $budi->personal_tenant_id, 'type' => 'tap', 'data' => []]); // workspace lain
        Notification::create(['user_id' => $budi->id, 'tenant_id' => $budi->personal_tenant_id, 'type' => 'tap', 'data' => []]); // pengguna lain

        $this->actingAs($rina);
        $first = $this->getJson($this->host('app', '/api/notifications'))->assertOk()
            ->assertJsonCount(30, 'data')->assertJsonPath('meta.unread', 35)->assertJsonPath('meta.has_more', true)->json();
        $this->assertSame('Kota 35', $first['data'][0]['data']['city']);
        $last = end($first['data'])['id'];
        $this->getJson($this->host('app', "/api/notifications?before=$last"))->assertOk()
            ->assertJsonCount(5, 'data')->assertJsonPath('meta.has_more', false);

        $one = $first['data'][0]['id'];
        $this->postJson($this->host('app', '/api/notifications/read'), ['ids' => [$one]])->assertOk();
        $this->getJson($this->host('app', '/api/notifications'))->assertJsonPath('meta.unread', 34)->assertJsonPath('data.0.read', true);

        $this->postJson($this->host('app', '/api/notifications/read'))->assertOk();
        $this->getJson($this->host('app', '/api/notifications'))->assertJsonPath('meta.unread', 0);
        // notifikasi workspace lain & pengguna lain tidak ikut terbaca
        $this->assertSame(2, Notification::whereNull('read_at')->count());
    }
}
