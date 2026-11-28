<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Mail\TenantInvite;
use App\Support\Mail\EmailContent;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Template email bersama (mail.layout): setiap jenis di EmailContent::for dirender ke HTML & teks dengan
 * preheader, ringkasan, tombol, footer — dan input pengguna (<script>) selalu di-escape.
 */
class EmailTemplateTest extends TestCase
{
    public const XSS = '<script>alert(1)</script>';

    /** Contoh payload per jenis — bentuknya sama dengan pemanggil Notify::user / Notify::email. */
    public static function samples(): array
    {
        $x = self::XSS;
        $at = '2026-12-01T09:30:00+07:00';

        return [
            'invoice_due' => ['id' => 'inv1', 'number' => 'INV/2026/11/000042', 'due' => $at, 'total' => 450000],
            'invoice_paid' => ['id' => 'inv1', 'number' => 'INV/2026/11/000042', 'plan' => 'Personal Pro', 'until' => $at],
            'subscription_past_due' => ['plan' => 'Business', 'grace_until' => $at, 'company' => true, 'id' => 'inv1', 'number' => 'INV/2026/11/000042'],
            'grace_ending' => ['plan' => 'Business', 'grace_until' => $at, 'id' => 'inv1', 'number' => 'INV/2026/11/000042'],
            'plan_changed' => ['plan' => 'Gratis', 'reason' => 'expired'],
            'subscription_reminder' => ['stage' => 'h7', 'days' => 7, 'plan' => 'Business', 'workspace' => "PT $x", 'end' => $at, 'id' => 'inv1', 'number' => 'INV/2026/11/000042'],
            'trial_ending' => ['stage' => 'trial', 'days' => 1, 'plan' => 'Business', 'workspace' => "PT $x", 'end' => $at],
            'webhook_disabled' => ['url' => 'https://hooks.example.com/tapcard', 'endpoint' => 'ep1', 'failures' => 20],
            'workspace_expired' => ['plan' => 'Business', 'reason' => 'trial'],
            'order_status' => ['id' => 'ord1', 'number' => 'ORD-2026-0042', 'status' => 'shipped', 'label' => 'Dikirim', 'tracking' => 'JNE123456789',
                'items' => [['label' => "Kartu PVC $x", 'qty' => 2, 'amount' => 300000]]],
            'order_unpaid' => ['id' => 'ord1', 'number' => 'ORD-2026-0042', 'total' => 300000, 'cancel_at' => $at],
            'card_period' => ['stage' => 'h7', 'days' => 7, 'card' => 'tok1', 'label' => 'TC-7Q4K', 'until' => $at, 'renew_price' => 150000],
            'card_renewed' => ['card' => 'tok1', 'label' => 'TC-7Q4K', 'until' => $at],
            'tap_quota' => ['card' => 'tok1', 'level' => 80, 'used' => 1200, 'quota' => 1500],
            'domain_unverified' => ['domain' => 'kartu.example.com'],
            'lost_card_tapped' => ['card' => 'tok1', 'label' => "Kartu $x", 'city' => 'Bandung', 'source' => 'nfc', 'tap_id' => 1, 'at' => $at],
            'tap' => ['card' => 'tok1', 'label' => "Kartu $x", 'count' => 3, 'city' => 'Jakarta', 'source' => 'nfc', 'at' => $at, 'tap_id' => 1],
            'lead_assigned' => ['count' => 1, 'name' => $x, 'id' => 'c1', 'by' => 'Budi'],
            'lead_new' => ['id' => 'c1', 'name' => $x, 'company' => 'A & B', 'source' => 'scan_tapcard'],
            'saved_back' => ['name' => $x, 'card' => 'tok1', 'by' => 'u1'],
            'reminder_due' => ['id' => 'c1', 'name' => $x, 'company' => 'A & B', 'note' => "Tawarkan paket $x", 'reminder' => 'r1'],
            'import_done' => ['id' => 'imp1', 'filename' => "kontak $x.csv", 'created' => 120, 'merged' => 4, 'failed' => 1],
            'account_deleted' => ['purge_at' => $at],
            'password_reset' => ['url' => 'http://panel.localhost/reset/abc?email=a%40b.c', 'minutes' => 60],
            'system_alert' => ['checks' => [['label' => 'Error server 15 menit', 'value' => '12', 'hint' => 'cek log'], ['label' => 'Antrean', 'value' => '540 job']],
                'reminder' => false, 'url' => 'http://panel.localhost/system'],
            'monitor_down' => ['name' => 'API', 'url' => 'http://panel.localhost/uptime', 'target' => 'https://api.example.com', 'reason' => 'HTTP 502', 'since' => $at],
            'monitor_degraded' => ['name' => 'API', 'url' => 'http://panel.localhost/uptime', 'target' => 'https://api.example.com', 'reason' => 'Lambat 4 detik', 'since' => $at],
            'monitor_up' => ['name' => 'API', 'url' => 'http://panel.localhost/uptime', 'target' => 'https://api.example.com', 'reason' => null, 'since' => $at],
            'system_recovered' => ['labels' => ['Antrean', 'Disk'], 'url' => 'http://panel.localhost/system'],
        ];
    }

    /** Semua jenis di match EmailContent::for (dibaca dari sumbernya agar jenis baru tidak lolos tanpa contoh). */
    public static function types(): array
    {
        $m = new ReflectionMethod(EmailContent::class, 'for');
        $src = implode('', array_slice(file($m->getFileName()), $m->getStartLine(), $m->getEndLine() - $m->getStartLine()));
        preg_match_all("/^ {12}'([a-z_]+)' => /m", $src, $hits);

        return array_values(array_unique($hits[1]));
    }

    public function test_every_type_has_a_sample(): void
    {
        $types = self::types();
        $this->assertGreaterThanOrEqual(29, count($types));
        $this->assertEqualsCanonicalizing($types, array_keys(self::samples()));
    }

    public function test_every_type_renders_html_and_text_with_summary_button_and_escaping(): void
    {
        foreach (self::samples() as $type => $data) {
            $mail = new NotificationMail($type, $data, 'Rina '.self::XSS, 'http://app.localhost', 'PT '.self::XSS);
            $c = $mail->body();
            $html = $mail->render();

            $this->assertNotSame('', $c['preheader'], $type);
            $this->assertStringContainsString(e($c['preheader']), $html, "$type: preheader");
            $this->assertNotEmpty($c['facts'], "$type: ringkasan");
            $this->assertStringContainsString('Ringkasan', $html, $type);
            foreach ($c['facts'] as [$label, $value]) {
                $this->assertStringContainsString(e($value), $html, "$type: $label");
                $mail->assertSeeInText($label.': '.$value);
            }
            if ($c['action']) {
                $this->assertStringContainsString(e($c['action']['url']), $html, "$type: tombol");
                $this->assertStringContainsString('v:roundrect', $html, "$type: tombol VML Outlook");
                $mail->assertSeeInText($c['action']['url']);
            }
            $this->assertStringContainsString('email/tapcard-logo.png', $html);
            $this->assertStringContainsString('alt="TapCard"', $html);
            $this->assertStringContainsString('Pusat bantuan', $html);
            $this->assertStringContainsString('Email ini dikirim karena', $html);
            $this->assertStringNotContainsString(self::XSS, $html, "$type: input tidak di-escape");
            $this->assertStringContainsString('&lt;script&gt;', $html, $type); // nama penerima & workspace di footer
            $mail->assertSeeInText('Halo Rina');
        }
    }

    public function test_settings_link_only_for_types_that_can_be_switched_off(): void
    {
        $data = self::samples();
        (new NotificationMail('tap', $data['tap'], 'Rina', 'http://app.localhost'))
            ->assertSeeInHtml('Atur email notifikasi')->assertSeeInHtml('http://app.localhost/settings', false);
        (new NotificationMail('invoice_due', $data['invoice_due'], 'Rina', 'http://app.localhost'))
            ->assertSeeInHtml('Pengaturan notifikasi')->assertSeeInText('Pengaturan notifikasi: http://app.localhost/settings');
        (new NotificationMail('password_reset', $data['password_reset'], 'Rina', 'http://panel.localhost'))
            ->assertDontSeeInHtml('/settings', false)->assertDontSeeInHtml('notifikasi</a>', false);
    }

    public function test_order_items_table_only_when_payload_has_items(): void
    {
        $data = self::samples()['order_status'];
        $with = new NotificationMail('order_status', $data, 'Rina', 'http://app.localhost');
        $with->assertSeeInHtml('Kartu PVC &lt;script&gt;', false)->assertSeeInHtml('Rp300.000')->assertSeeInText('Kartu PVC '.self::XSS.' x2 — Rp300.000');

        unset($data['items'], $data['tracking']);
        $without = new NotificationMail('order_status', $data, 'Rina', 'http://app.localhost');
        $without->assertDontSeeInHtml('>Qty<', false)->assertDontSeeInHtml('Nomor resi');
    }

    public function test_tenant_invite_uses_shared_layout(): void
    {
        $mail = new TenantInvite('PT '.self::XSS, 'Admin', 'Budi', 'http://maju.localhost/join/tok123', now()->addDays(3));
        $html = $mail->render();
        $this->assertStringNotContainsString(self::XSS, $html);
        foreach (['Workspace', 'Peran', 'Pengundang', 'Berlaku sampai', 'Terima undangan', 'http://maju.localhost/join/tok123', 'v:roundrect', 'email/tapcard-logo.png'] as $s) {
            $this->assertStringContainsString($s, $html);
        }
        $this->assertStringContainsString(e($mail->body()['preheader']), $html);
        $mail->assertSeeInText('Peran: Admin');
        $mail->assertSeeInText('Terima undangan: http://maju.localhost/join/tok123');
    }
}
