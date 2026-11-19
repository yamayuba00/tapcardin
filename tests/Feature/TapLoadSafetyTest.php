<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Jobs\RecordTap;
use App\Jobs\TapContext;
use App\Models\Tap;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Lonjakan tap (hasil uji beban): batas per perangkat, halaman 429 bermerek, tanpa sesi, tap tidak dobel. */
class TapLoadSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_rate_limit_is_per_device_and_shows_branded_page(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $url = $this->host('tap', "/c/{$card->card_token}?src=qr");
        for ($i = 0; $i < 60; $i++) {
            $this->get($url, ['User-Agent' => 'HP-A'])->assertOk();
        }
        $this->get($url, ['User-Agent' => 'HP-A'])->assertStatus(429)->assertHeader('Retry-After')
            ->assertSee('"error":{"status":429}', false);
        // pengunjung lain di Wi-Fi yang sama (browser berbeda) tetap bisa membuka
        $this->get($url, ['User-Agent' => 'HP-B'])->assertOk();
    }

    public function test_public_tap_pages_do_not_start_a_session(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $res = $this->get($this->host('tap', "/c/{$card->card_token}"))->assertOk();
        $this->assertEmpty(array_filter($res->headers->getCookies(), fn ($c) => $c->getName() === config('session.cookie')));
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_html_errors_render_the_spa_error_screen(): void
    {
        $this->get($this->host('perusahaan-tidak-ada', '/'))->assertNotFound()->assertSee('"error":{"status":404}', false);
        // API tetap JSON, bukan halaman
        $this->getJson($this->host('tap', '/api/c/XXXXXXXXXX'))->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }

    public function test_retried_record_tap_job_does_not_duplicate(): void
    {
        $card = $this->makeCard(CardStatus::Active, $this->makeUser());
        $ctx = new TapContext(source: 'nfc', sunResult: null, ipHash: str_repeat('a', 64), visitorHash: str_repeat('b', 64),
            country: null, city: null, device: 'mobile', browser: 'Chrome', at: now()->toIso8601String(), id: '01JAAAAAAAAAAAAAAAAAAAAAAA');
        $job = new RecordTap($card->tenant_id, $card->id, $ctx);
        $job->handle();
        $job->handle(); // percobaan ulang setelah tap tersimpan
        $this->assertSame(1, Tap::withoutGlobalScope(TenantScope::class)->count());
    }
}
