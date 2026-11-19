<?php

namespace Tests\Feature;

use App\Jobs\BroadcastNotification;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Support\Notify;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Notifikasi tap digabung per kartu per 60 menit; notifikasi lama dibersihkan (dibaca > 90 hari, belum dibaca > 180 hari). */
class TapNotificationAggregationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function tap($user, string $card = 'KARTUAAAAA', ?string $city = 'Bandung', int $tapId = 1): ?Notification
    {
        return Notify::tap($user->id, $user->personal_tenant_id, [
            'card' => $card, 'label' => 'Kerja', 'city' => $city, 'source' => 'nfc', 'tap_id' => $tapId, 'at' => now()->toIso8601String(),
        ]);
    }

    public function test_taps_within_an_hour_merge_into_one_unread_notification(): void
    {
        $rina = $this->makeUser();
        $a = $this->tap($rina, city: 'Bandung', tapId: 1);
        $this->travel(20)->minutes();
        $b = $this->tap($rina, city: 'Jakarta', tapId: 2);
        $this->tap($rina, city: 'Bandung', tapId: 3);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Notification::where('type', 'tap')->count());
        $d = $a->fresh()->data;
        $this->assertSame(3, $d['count']);
        $this->assertSame(['Bandung', 'Jakarta'], $d['cities'], 'kota berbeda, terbaru dulu');
        $this->assertSame(3, $d['tap_id']);
        $this->assertNotSame($d['first_at'], $d['at']);

        // satu notifikasi = satu di lencana belum dibaca
        $this->actingAs($rina)->getJson($this->host('app', '/api/notifications'))->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.data.count', 3);
    }

    public function test_new_notification_after_window_after_read_and_per_card(): void
    {
        $rina = $this->makeUser();
        $first = $this->tap($rina);
        $this->travel(61)->minutes();
        $second = $this->tap($rina);
        $this->assertNotSame($first->id, $second->id, 'lewat 60 menit → notifikasi baru');

        $second->update(['read_at' => now()]);
        $third = $this->tap($rina);
        $this->assertNotSame($second->id, $third->id, 'sudah dibaca → notifikasi baru');

        $other = $this->tap($rina, card: 'KARTUBBBBB');
        $this->assertNotSame($third->id, $other->id, 'kartu lain → notifikasi sendiri');
        $this->assertSame(1, $other->data['count']);
    }

    public function test_old_single_tap_rows_without_count_are_treated_as_one(): void
    {
        $rina = $this->makeUser();
        $old = Notification::create(['user_id' => $rina->id, 'tenant_id' => $rina->personal_tenant_id, 'type' => 'tap',
            'data' => ['card' => 'KARTUAAAAA', 'city' => 'Medan', 'at' => now()->toIso8601String()]]);
        $this->tap($rina, city: 'Bandung');
        $d = $old->fresh()->data;
        $this->assertSame(2, $d['count']);
        $this->assertSame(['Bandung', 'Medan'], $d['cities']);
    }

    public function test_lost_card_taps_are_never_merged(): void
    {
        $rina = $this->makeUser();
        Notify::user($rina->id, $rina->personal_tenant_id, 'lost_card_tapped', ['card' => 'KARTUAAAAA']);
        Notify::user($rina->id, $rina->personal_tenant_id, 'lost_card_tapped', ['card' => 'KARTUAAAAA']);
        $this->assertSame(2, Notification::where('type', 'lost_card_tapped')->count());
    }

    public function test_rebroadcast_with_same_id_and_email_only_once(): void
    {
        Queue::fake();
        Mail::fake();
        $rina = $this->makeUser();
        $rina->forceFill(['notification_prefs' => ['tap' => ['email' => true]]])->save();
        $n = $this->tap($rina);
        $this->tap($rina);
        $this->tap($rina);

        Queue::assertPushed(BroadcastNotification::class, 3);
        Queue::assertPushed(BroadcastNotification::class, fn ($j) => $j->notificationId !== $n->id ? false : true);
        Queue::assertNotPushed(BroadcastNotification::class, fn ($j) => $j->notificationId !== $n->id);
        Mail::assertQueued(NotificationMail::class, 1);
    }

    public function test_concurrent_worker_waits_for_the_same_card_lock(): void
    {
        $rina = $this->makeUser();
        // worker 1 menggabungkan tap kartu ini; kuncinya dipegang sampai transaksinya selesai (di tes: sampai tes selesai)
        $this->tap($rina);

        // worker 2 (koneksi lain) untuk kartu yang sama harus menunggu → tidak bisa membuat baris kedua / menimpa hitungan
        config(['database.connections.pgsql_worker2' => config('database.connections.pgsql')]);
        $other = DB::connection('pgsql_worker2');
        $key = ['tap-notif', "{$rina->id}:{$rina->personal_tenant_id}:KARTUAAAAA"];
        try {
            $other->beginTransaction();
            $other->statement("SET LOCAL lock_timeout = '300ms'");
            $other->select('select pg_advisory_xact_lock(hashtext(?), hashtext(?))', $key);
            $this->fail('worker 2 seharusnya menunggu kunci worker 1');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'lock_not_available');
        } finally {
            $other->rollBack();
            $other->disconnect();
        }
        // kartu lain tidak ikut tertahan
        $free = DB::connection('pgsql_worker2');
        $this->assertTrue((bool) $free->selectOne('select pg_try_advisory_lock(hashtext(?), hashtext(?)) as ok', ['tap-notif', "{$rina->id}:{$rina->personal_tenant_id}:KARTUBBBBB"])->ok);
        $free->disconnect();

        $this->tap($rina);
        $this->assertSame(1, Notification::where('type', 'tap')->count());
        $this->assertSame(2, Notification::where('type', 'tap')->first()->data['count'], 'tidak ada hitungan yang hilang');
    }

    public function test_prune_removes_read_after_90_days_and_unread_after_180_days(): void
    {
        $rina = $this->makeUser();
        $make = fn (int $days, bool $read) => Notification::create(['user_id' => $rina->id, 'tenant_id' => null, 'type' => 'announcement',
            'data' => [], 'read_at' => $read ? now() : null, 'created_at' => now()->subDays($days)]);
        $make(91, true);
        $make(181, false);
        $keepRead = $make(30, true);
        $keepUnread = $make(120, false);

        $this->artisan('tapcard:prune')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$keepRead->id, $keepUnread->id], Notification::pluck('id')->all());
    }
}
