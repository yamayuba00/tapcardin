<?php

namespace Tests\Feature;

use App\Actions\Auth\DeleteAccount;
use App\Mail\NotificationMail;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Email notifikasi: saklar per jenis, template, siklus tagihan & masa tenggang, hapus akun. */
class NotificationEmailTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    /** @return list<string> jenis email yang diantrekan untuk alamat ini */
    private function mailed(string $to): array
    {
        return Mail::queued(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo($to))->map->type->values()->all();
    }

    public function test_payment_lifecycle_emails_render_with_links_and_queue_on_notify(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $id = $this->api('POST', '/billing/checkout', ['plan' => 'personal_pro', 'cycle' => 'monthly'])->json('data.invoice.id');
        $this->api('POST', "/billing/invoices/$id/simulate", ['result' => 'paid', 'method' => 'QRIS']);

        $this->assertSame(['invoice_paid'], $this->mailed('rina@example.com'));
        $mail = Mail::queued(NotificationMail::class)->first();
        $this->assertSame('notify', $mail->queue);
        $mail->assertHasSubject(Mail::queued(NotificationMail::class)->first()->body()['subject']);
        $this->assertStringStartsWith('Pembayaran INV/', $mail->body()['subject']);
        $mail->assertSeeInHtml('Pembayaran berhasil');
        $mail->assertSeeInHtml('<strong style="color:#141210">Personal Pro</strong>', false);
        $mail->assertSeeInHtml(config('app.url')."/billing/invoices/$id", false);
        $mail->assertSeeInText('Halo Rina Saputra');

        // perpanjangan terbit → masa tenggang → pengingat 3 hari → turun paket
        $this->travel(25)->days();
        $this->artisan('tapcard:subscriptions');
        $this->travel(7)->days();
        $this->artisan('tapcard:subscriptions');
        $grace = Subscription::acrossTenants()->first()->grace_until;
        $this->travelTo($grace->copy()->subHours(60)); // jalan harian 2,5 hari sebelum habis
        $this->artisan('tapcard:subscriptions');
        $this->travelTo($grace->copy()->addHour());
        $this->artisan('tapcard:subscriptions');
        $this->assertSame(['invoice_paid', 'invoice_due', 'subscription_past_due', 'grace_ending', 'plan_changed'], $this->mailed('rina@example.com'));

        $grace = Mail::queued(NotificationMail::class, fn ($m) => $m->type === 'grace_ending')->first();
        $grace->assertSeeInHtml('Masa tenggang hampir berakhir');
        $grace->assertSeeInHtml('Bayar tagihan');
    }

    public function test_daily_activity_is_off_by_default_and_each_channel_follows_its_own_switch(): void
    {
        $rina = $this->makeUser();
        Notify::user($rina->id, $rina->personal_tenant_id, 'tap', ['city' => 'Bandung']);
        $this->assertSame([], $this->mailed('rina@example.com'), 'tap tidak dikirim ke email secara default');

        $this->actingAs($rina);
        $this->assertFalse($this->api('GET', '/me/settings')->json('data.prefs.tap.email'));
        $this->assertTrue($this->api('GET', '/me/settings')->json('data.prefs.invoice_paid.email'));
        $this->api('PATCH', '/me', ['notification_prefs' => ['tap' => ['inapp' => false, 'email' => true], 'tap_quota' => ['inapp' => true, 'email' => false]]])->assertOk();

        $this->assertNull(Notify::user($rina->id, $rina->personal_tenant_id, 'tap', ['city' => 'Bandung']), 'in-app mati');
        Notify::user($rina->id, $rina->personal_tenant_id, 'tap_quota', ['level' => 100, 'used' => 10, 'quota' => 10]);
        $this->assertSame(['tap'], $this->mailed('rina@example.com'));
        $this->assertSame(1, DB::table('notifications')->where('user_id', $rina->id)->where('type', 'tap_quota')->count());
    }

    public function test_user_input_is_escaped_and_company_links_use_workspace_subdomain(): void
    {
        $rina = $this->makeUser();
        $pt = Tenant::create(['type' => 'company', 'name' => 'PT Maju', 'slug' => 'maju', 'owner_user_id' => $rina->id, 'status' => 'active',
            'plan_id' => DB::table('plans')->where('key', 'team')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS]);
        $rina->update(['notification_prefs' => ['lead_new' => ['email' => true]]]);
        Notify::user($rina->id, $pt->id, 'lead_new', ['id' => 'ABC', 'name' => '<script>x</script>', 'company' => 'A & B']);

        $mail = Mail::queued(NotificationMail::class)->first();
        $mail->assertDontSeeInHtml('<script>x</script>', false);
        $mail->assertSeeInHtml('&lt;script&gt;', false);
        $mail->assertSeeInText('<script>x</script> (A & B)'); // teks polos tanpa entitas HTML
        $mail->assertSeeInHtml('http://maju.localhost', false);
    }

    public function test_account_deletion_confirmation_goes_to_the_original_address(): void
    {
        $rina = $this->makeUser();
        app(DeleteAccount::class)->handle($rina);
        $this->assertSame(['account_deleted'], $this->mailed('rina@example.com'));
        $mail = Mail::queued(NotificationMail::class)->first();
        $mail->assertSeeInHtml('Akun Anda telah dihapus');
        $mail->assertDontSeeInHtml('Pengaturan → Notifikasi');
    }
}
