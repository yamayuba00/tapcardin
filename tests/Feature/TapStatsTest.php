<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Tap;
use App\Support\UserAgent;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tap dihitung sekali per pengunjung per kartu (anti spam), bot tidak dihitung, analitik dari ringkasan harian + tap terbaru. */
class TapStatsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

    private function open(Card $card, string $ip = '10.0.0.1', string $ua = self::PHONE): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('User-Agent', $ua)
            ->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertOk();
    }

    private function taps(): int
    {
        return Tap::withoutGlobalScope(TenantScope::class)->count();
    }

    private function seedTap(Card $card, string $at, array $attrs = []): void
    {
        Tap::withoutGlobalScope(TenantScope::class)->create($attrs + [
            'tenant_id' => $card->tenant_id, 'card_id' => $card->id, 'holder_user_id' => $card->holder_user_id,
            'source' => 'nfc', 'ip_hash' => str_repeat('a', 64), 'visitor_hash' => hash('sha256', uniqid('', true)),
            'device' => 'android', 'city' => 'Bandung', 'created_at' => Carbon::parse($at, 'Asia/Jakarta'), // waktu WIB, apa pun jam tes dijalankan
        ]);
    }

    public function test_repeat_opens_by_same_visitor_count_once_and_notify_once(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);

        $this->open($card);
        $this->open($card);
        $this->open($card);
        $this->assertSame(1, $this->taps(), 'refresh berulang tidak menambah tap');
        $this->assertSame(1, DB::table('notifications')->where('user_id', $rina->id)->where('type', 'tap')->count());

        // satu IP dipakai banyak orang (Wi-Fi kantor / CGNAT): browser lain tetap terhitung
        $this->open($card, ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1');
        $this->open($card, ip: '10.0.0.2');
        $this->assertSame(3, $this->taps());

        // setelah jendela waktu lewat, kunjungan berikutnya dihitung lagi
        $this->travel(config('tapcard.tap_dedupe_minutes') + 1)->minutes();
        $this->open($card);
        $this->assertSame(4, $this->taps());
    }

    public function test_bots_and_link_previews_are_not_counted(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        foreach (['WhatsApp/2.23.20.0 A', 'facebookexternalhit/1.1', 'TelegramBot (like TwitterBot)', 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'curl/8.4.0', ''] as $ua) {
            $this->open($card, ua: $ua);
        }
        $this->assertSame(0, $this->taps());

        $this->assertFalse(UserAgent::isBot('Mozilla/5.0 (Linux; Android 12; CUBOT X50) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36'));
    }

    public function test_analytics_combines_daily_rollup_with_recent_taps(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->seedTap($card, '-10 days 10:00', ['source' => 'qr', 'city' => 'Jakarta']);
        $this->seedTap($card, '-10 days 11:00', ['source' => 'qr', 'city' => 'Jakarta', 'device' => 'ios']);
        $this->seedTap($card, '-5 days 09:00');
        $this->artisan('tapcard:aggregate-stats', ['--days' => 30])->assertSuccessful();
        // tap lama di tabel mentah yang belum diringkas tidak boleh terbaca (bukti ringkasan yang dipakai)
        $this->seedTap($card, '-5 days 09:30');
        $this->seedTap($card, 'today 00:00', ['city' => 'Jakarta']); // selalu sudah lewat, jam berapa pun tes dijalankan

        $res = $this->actingAs($rina)->getJson($this->host('app', '/api/analytics/cards?days=30'))->assertOk();
        $res->assertJsonPath('data.totals.taps', 4)
            ->assertJsonPath('data.totals.visitors', 4)
            ->assertJsonPath('data.sources.qr', 2)->assertJsonPath('data.sources.nfc', 2)
            ->assertJsonPath('data.devices.android', 3)->assertJsonPath('data.devices.ios', 1)
            ->assertJsonPath('data.cities.Jakarta', 3)->assertJsonPath('data.cities.Bandung', 1)
            ->assertJsonPath('data.cards.0.taps', 4);
        $series = collect($res->json('data.series'))->keyBy('date');
        $this->assertSame(2, $series[now('Asia/Jakarta')->subDays(10)->toDateString()]['value']);
        $this->assertSame(1, $series[now('Asia/Jakarta')->toDateString()]['value']);
    }

    public function test_dashboard_compares_this_week_with_last_week(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->seedTap($card, '-9 days 10:00');
        $this->seedTap($card, '-8 days 10:00');
        $this->seedTap($card, '-20 days 10:00');
        $this->artisan('tapcard:aggregate-stats', ['--days' => 30])->assertSuccessful();
        $this->seedTap($card, 'today 07:00');

        $this->actingAs($rina)->getJson($this->host('app', '/api/dashboard'))->assertOk()
            ->assertJsonPath('data.kpi.taps_7', 1)->assertJsonPath('data.kpi.taps_prev_7', 2)
            ->assertJsonPath('data.kpi.taps_30', 4)->assertJsonPath('data.kpi.visitors_30', 4);
    }

    public function test_event_taps_use_partial_index(): void
    {
        $def = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'taps_event_ix'")?->indexdef;
        $this->assertStringContainsString('WHERE (event_id IS NOT NULL)', (string) $def);
    }
}
