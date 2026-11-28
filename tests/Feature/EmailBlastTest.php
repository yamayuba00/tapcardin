<?php

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Jobs\SendBlastBatch;
use App\Jobs\SendEmailBlast;
use App\Mail\BlastMail;
use App\Models\AuditLog;
use App\Models\EmailBlast;
use App\Models\EmailBlastRecipient;
use App\Models\EmailUnsubscribe;
use App\Models\Plan;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Blast\BlastAudience;
use App\Support\Blast\BlastMarkdown;
use App\Support\Blast\BlastUnsubscribe;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Email blast promo: renderer markdown aman, unggah gambar, penerima, kirim idempoten, berhenti berlangganan, izin. */
class EmailBlastTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function panel(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('panel', "/api$path"), $data);
    }

    private function blast(array $attrs = []): EmailBlast
    {
        return EmailBlast::create([
            'subject' => 'Promo akhir tahun', 'preheader' => 'Diskon 30%', 'body' => "# Halo\n\nPromo **besar**.", 'audience' => 'all', 'status' => 'draft', ...$attrs,
        ]);
    }

    private function company(User $owner, User ...$members): Tenant
    {
        $org = Tenant::create([
            'type' => TenantType::Company, 'name' => 'Maju Jaya', 'slug' => 'majujaya', 'owner_user_id' => $owner->id,
            'status' => 'active', 'plan_id' => Plan::where('key', 'business')->value('id'), 'settings' => Tenant::DEFAULT_SETTINGS,
        ]);
        foreach ([$owner, ...$members] as $u) {
            app(CurrentTenant::class)->run($org, fn () => TenantMember::create(['user_id' => $u->id, 'role_id' => Role::system('owner')->id, 'status' => 'active']));
        }

        return $org;
    }

    public function test_markdown_escapes_raw_html_and_drops_unsafe_links(): void
    {
        $html = BlastMarkdown::html(implode("\n\n", [
            '# Judul', 'Halo <script>alert(1)</script> <img src=x onerror=alert(1)>', '<div onclick="x()">blok</div>',
            '[jahat](javascript:alert(1)) [data](data:text/html;base64,xx) [http](http://contoh.id) [relatif](/promo) [aman](https://tapcard.id/promo)',
            '![Banner promo](https://cdn.tapcard.id/blast/a.png)', '![x](javascript:alert(1))', '- satu', '---',
            '[[Tombol: Beli sekarang | https://tapcard.id/beli]]', '[[Tombol: Nakal | javascript:alert(1)]]',
        ]));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<div', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        $this->assertStringNotContainsString('data:text', $html);
        $this->assertStringNotContainsString('href="http://contoh.id"', $html);
        $this->assertStringNotContainsString('href="/promo"', $html);
        $this->assertStringContainsString('href="https://tapcard.id/promo"', $html);
        $this->assertMatchesRegularExpression('~<img [^>]*width="100%"[^>]*src="https://cdn\.tapcard\.id/blast/a\.png" alt="Banner promo"~', $html);
        $this->assertStringContainsString('max-width:100%', $html);
        $this->assertStringContainsString('<hr', $html);
        $this->assertStringContainsString('href="https://tapcard.id/beli"', $html);
        $this->assertStringContainsString('>Beli sekarang</a>', $html);
        $this->assertStringContainsString('>Nakal</p>', $html); // tombol tak aman → teks biasa
        $this->assertStringContainsString('<h1 style=', $html);

        $text = BlastMarkdown::text("## Promo\n\n**Hemat** [di sini](https://tapcard.id/x)\n\n[[Tombol: Beli | https://tapcard.id/beli]]");
        $this->assertSame("Promo\n\nHemat di sini (https://tapcard.id/x)\n\nBeli: https://tapcard.id/beli", $text);
    }

    public function test_image_upload_is_validated_and_stored_on_blast_disk(): void
    {
        Storage::fake('blast');
        $this->actingAs($this->makeStaff('platform_admin'));

        $res = $this->panel('POST', '/email-blasts/images', ['image' => UploadedFile::fake()->image('banner.png', 1200, 600)])->assertCreated();
        $path = $res->json('data.path');
        $this->assertMatchesRegularExpression('~^blast/\d{4}/\d{2}/[a-z0-9]{32}\.png$~', $path);
        Storage::disk('blast')->assertExists(substr($path, strlen('blast/')));
        $this->assertStringStartsWith('http', $res->json('data.url'));

        $this->panel('POST', '/email-blasts/images', ['image' => UploadedFile::fake()->image('besar.jpg', 2600, 800)])->assertUnprocessable()->assertJsonPath('error.message', 'Ukuran gambar maksimal 2400 × 2400 piksel.');
        $this->panel('POST', '/email-blasts/images', ['image' => UploadedFile::fake()->create('berat.png', 3000, 'image/png')])->assertUnprocessable();
        $this->panel('POST', '/email-blasts/images', ['image' => UploadedFile::fake()->createWithContent('palsu.png', '<?php echo 1;')])->assertUnprocessable();
        $this->panel('POST', '/email-blasts/images', ['image' => UploadedFile::fake()->image('anim.gif', 100, 100)])->assertUnprocessable();
        $this->assertCount(1, Storage::disk('blast')->allFiles());
    }

    public function test_recipients_respect_audience_and_exclude_blocked_and_unsubscribed(): void
    {
        $rina = $this->makeUser('rina@example.com');
        $budi = $this->makeUser('Budi@Example.com', 'Budi');
        $blocked = $this->makeUser('blokir@example.com', 'Blokir');
        $blocked->update(['blocked_at' => now()]);
        $gone = $this->makeUser('keluar@example.com', 'Keluar');
        EmailUnsubscribe::create(['email' => 'keluar@example.com']);
        $this->company($budi);
        $this->makeStaff('support', 'uji@tapcard.test');

        $emails = fn (string $aud, ?int $plan = null) => BlastAudience::query($aud, $plan)->get()->pluck('email')->sort()->values()->all();
        $this->assertSame(['budi@example.com', 'rina@example.com'], $emails('all'));
        $this->assertSame(['budi@example.com', 'rina@example.com'], $emails('personal'));
        $this->assertSame(['budi@example.com'], $emails('company'));
        $this->assertSame(['budi@example.com'], $emails('plan', Plan::where('key', 'business')->value('id')));
        $this->assertSame(['owner@tapcard.test', 'uji@tapcard.test'], $emails('test')); // staf aktif (pemilik hasil seed + staf uji)
        $this->assertSame(2, BlastAudience::count('all', null));

        $this->actingAs($this->makeStaff('platform_admin', 'admin2@tapcard.test'));
        $this->panel('GET', '/email-blasts/audience-count?audience=company')->assertOk()->assertJsonPath('data.count', 1);
    }

    public function test_sending_is_idempotent_and_records_status(): void
    {
        Mail::fake();
        $this->makeUser('rina@example.com');
        $this->makeUser('budi@example.com', 'Budi');
        $this->actingAs($this->makeStaff('platform_admin'));
        $id = $this->panel('POST', '/email-blasts', ['subject' => 'Promo', 'body' => 'Isi', 'audience' => 'all'])->assertCreated()->json('data.id');

        $this->panel('POST', "/email-blasts/$id/send")->assertOk();
        $blast = EmailBlast::where('public_id', $id)->first();
        $this->assertSame('sent', $blast->status);
        $this->assertSame(2, $blast->sent_count);
        Mail::assertSent(BlastMail::class, 2);
        Mail::assertSent(BlastMail::class, fn (BlastMail $m) => $m->hasTo('rina@example.com')
            && str_contains($m->render(), 'Berhenti berlangganan'));

        // job dijalankan ulang (retry/duplikat) → tidak ada email ganda
        $blast->update(['status' => 'sending']);
        (new SendEmailBlast($blast->id))->handle();
        (new SendBlastBatch($blast->id, EmailBlastRecipient::pluck('id')->all()))->handle();
        Mail::assertSent(BlastMail::class, 2);
        $this->assertSame(2, EmailBlastRecipient::where('status', 'sent')->count());
        $this->assertSame('sent', $blast->fresh()->status);

        // blast yang sudah terkirim tidak bisa dikirim lagi
        $this->panel('POST', "/email-blasts/$id/send")->assertUnprocessable()->assertJsonPath('error.code', 'not_editable');
        $this->assertTrue(AuditLog::where('action', 'email_blast.sent')->exists());
        $this->assertTrue(AuditLog::where('action', 'email_blast.created')->exists());
    }

    public function test_mail_has_one_click_unsubscribe_headers_and_plain_text(): void
    {
        $mail = new BlastMail('Promo', 'Pratinjau', "Halo **Rina**\n\n[[Tombol: Beli | https://tapcard.id/beli]]", 'rina@example.com', 'B1');
        $headers = $mail->headers()->text;
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $this->assertMatchesRegularExpression('~^<http://localhost(:\d+)?/unsubscribe\?.*signature=~', $headers['List-Unsubscribe']);
        $mail->assertSeeInText('Beli: https://tapcard.id/beli');
        $mail->assertSeeInHtml('Pratinjau');
        $mail->assertDontSeeInText('**');
    }

    public function test_cancel_stops_pending_recipients(): void
    {
        Mail::fake();
        Queue::fake();
        $this->makeUser('rina@example.com');
        $this->makeUser('budi@example.com', 'Budi');
        $blast = $this->blast(['status' => 'sending']);
        (new SendEmailBlast($blast->id))->handle(); // penerima dibuat, batch masuk antrean (palsu)
        Queue::assertPushedOn('mail', SendBlastBatch::class);
        $this->assertSame(2, EmailBlastRecipient::where('status', 'pending')->count());

        $this->actingAs($this->makeStaff('platform_admin'));
        $this->panel('POST', "/email-blasts/{$blast->public_id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        (new SendBlastBatch($blast->id, EmailBlastRecipient::pluck('id')->all()))->handle();
        Mail::assertNothingSent();
        $this->assertSame(2, EmailBlastRecipient::where('status', 'skipped')->count());
        $this->assertTrue(AuditLog::where('action', 'email_blast.cancelled')->exists());
    }

    public function test_scheduled_blast_is_dispatched_when_due(): void
    {
        Queue::fake();
        $this->actingAs($this->makeStaff('platform_admin'));
        $blast = $this->blast();
        $this->panel('POST', "/email-blasts/{$blast->public_id}/schedule", ['scheduled_at' => now()->subMinute()->toIso8601String()])->assertUnprocessable();
        $this->panel('POST', "/email-blasts/{$blast->public_id}/schedule", ['scheduled_at' => now()->addHour()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertTrue(AuditLog::where('action', 'email_blast.scheduled')->exists());

        $this->artisan('tapcard:blasts')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travel(61)->minutes();
        $this->artisan('tapcard:blasts')->assertSuccessful();
        $this->artisan('tapcard:blasts')->assertSuccessful(); // tidak dikirim dua kali
        Queue::assertPushed(SendEmailBlast::class, 1);
        $this->assertSame('sending', $blast->fresh()->status);
    }

    public function test_unsubscribe_signed_url_and_one_click_post(): void
    {
        $url = BlastUnsubscribe::url('Rina@Example.com', 'B1');

        // tanda tangan diubah → ditolak
        $this->post(str_replace('e=rina', 'e=budi', $url))->assertForbidden();
        $this->assertSame(0, EmailUnsubscribe::count());

        // GET = halaman konfirmasi (SPA)
        $this->get($url)->assertOk()->assertSee('id="app"', false);
        $this->assertSame(0, EmailUnsubscribe::count());

        // one-click RFC 8058: POST form tanpa CSRF, sesi, atau X-Tap-Key
        $this->withoutHeader('X-Tap-Key')->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertTrue(EmailUnsubscribe::has('rina@example.com'));
        $this->assertStringStartsWith('one-click', EmailUnsubscribe::first()->reason);

        // konfirmasi dari halaman (JSON), ulang → tetap satu baris
        $this->postJson($url)->assertOk()->assertJsonPath('data.email', 'rina@example.com');
        $this->assertSame(1, EmailUnsubscribe::count());
    }

    public function test_permissions_and_test_send(): void
    {
        Mail::fake();
        $blast = $this->blast();

        $this->actingAs($this->makeStaff('support', 'support@tapcard.test'));
        $this->panel('GET', '/email-blasts')->assertForbidden();
        $this->panel('POST', '/email-blasts', ['subject' => 'x', 'body' => 'y', 'audience' => 'all'])->assertForbidden();
        $this->panel('POST', '/email-blasts/preview', ['body' => 'x'])->assertForbidden();

        // punya comms.manage tapi bukan pemilik/admin → tidak boleh kirim uji
        $role = Role::create(['key' => 'comms_only', 'name' => 'Konten', 'scope' => 'platform', 'is_system' => false]);
        RolePermission::create(['role_id' => $role->id, 'permission_key' => 'platform.comms.manage']);
        $this->actingAs($this->makeStaff('support', 'konten@tapcard.test', 'Konten', ['role_id' => $role->id]));
        $this->panel('GET', '/email-blasts')->assertOk()->assertJsonPath('meta.can_test', false);
        $this->panel('POST', "/email-blasts/{$blast->public_id}/test", ['email' => 'saya@example.com'])->assertForbidden();
        Mail::assertNothingSent();

        $this->actingAs($this->makeStaff('platform_admin', 'admin@tapcard.test'));
        $this->panel('GET', '/email-blasts')->assertOk()->assertJsonPath('meta.can_test', true);
        $this->panel('POST', "/email-blasts/{$blast->public_id}/test", ['email' => 'bukan-email'])->assertUnprocessable();
        $this->panel('POST', "/email-blasts/{$blast->public_id}/test", ['email' => 'saya@example.com'])->assertOk();
        Mail::assertSent(BlastMail::class, fn (BlastMail $m) => $m->hasTo('saya@example.com') && $m->test);
        $this->assertSame(0, EmailBlastRecipient::count()); // kirim uji tidak membuat penerima
        $this->assertSame('draft', $blast->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'email_blast.test_sent')->exists());

        $html = $this->panel('POST', '/email-blasts/preview', ['subject' => 'S', 'body' => "<b>x</b>\n\n**tebal**"])->assertOk()->json('data.html');
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('<strong>tebal</strong>', $html);

        // dokumen pratinjau untuk iframe: CSP sendiri (gaya inline boleh, skrip tidak), hanya untuk staf pembuatnya
        $url = $this->panel('POST', '/email-blasts/preview', ['subject' => 'S', 'body' => '**tebal**'])->json('data.url');
        $doc = $this->get($url)->assertOk()->assertSee('<strong>tebal</strong>', false);
        $this->assertStringContainsString("style-src 'unsafe-inline'", $doc->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('script-src', $doc->headers->get('Content-Security-Policy'));
        $this->actingAs($this->makeStaff('platform_admin', 'lain@tapcard.test'));
        $this->get($url)->assertNotFound();
    }
}
