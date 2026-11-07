<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Event;
use App\Models\FormSubmission;
use App\Models\Tap;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/** Formulir buatan pemilik di bio link + kartu yang dibawa ke event (atribusi tap/koneksi/formulir ke event). */
class BioFormsAndEventCardsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const FORM = [
        'label' => 'Ajak Kolaborasi', 'title' => 'Kerja sama brand', 'description' => 'Ceritakan kebutuhan Anda.', 'contact' => 'both',
        'fields' => [
            ['type' => 'text', 'label' => 'Nama brand', 'required' => true],
            ['type' => 'select', 'label' => 'Jenis kerja sama', 'required' => true, 'options' => ['Endorse', 'Review', 'Event']],
            ['type' => 'multi', 'label' => 'Platform', 'options' => ['TikTok', 'Instagram', 'YouTube']],
            ['type' => 'date', 'label' => 'Deadline'],
            ['type' => 'number', 'label' => 'Budget (Rp)'],
        ],
    ];

    private function rejects($res, string $key): void
    {
        $res->assertStatus(422);
        $this->assertArrayHasKey($key, $res->json('error.fields') ?? [], (string) $res->getContent());
    }

    /** @return array{card: Card, form: array} kartu aktif Rina dengan halaman bio berisi formulir */
    private function cardWithForm(array $form = self::FORM): array
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $id = $this->profileOf($rina)->public_id;
        $page = $this->actingAs($rina)->getJson($this->host('app', "/api/profiles/$id/pages"))->json('data.0');
        $saved = $this->putJson($this->host('app', "/api/profiles/$id/pages/{$page['id']}"), [
            'name' => $page['name'], 'theme' => $page['theme'],
            'blocks' => [['type' => 'form', 'data' => $form], ['type' => 'contacts']],
        ])->assertOk()->json('data.blocks.0');

        return ['card' => $card, 'form' => $saved, 'rina' => $rina];
    }

    private function submit(Card $card, string $block, array $body)
    {
        return $this->postJson($this->host('tap', "/api/c/{$card->card_token}/forms/$block"), $body + [
            'consent' => true, 't' => Crypt::encryptString((string) (time() - 10)),
        ]);
    }

    public function test_form_block_is_validated_and_rendered_publicly(): void
    {
        $rina = $this->makeUser();
        $this->makeCard(CardStatus::Active, $rina);
        $id = $this->profileOf($rina)->public_id;
        $page = $this->actingAs($rina)->getJson($this->host('app', "/api/profiles/$id/pages"))->json('data.0');
        $put = fn (array $data) => $this->putJson($this->host('app', "/api/profiles/$id/pages/{$page['id']}"), ['name' => 'Utama', 'theme' => $page['theme'], 'blocks' => [['type' => 'form', 'data' => $data]]]);

        $this->rejects($put(['fields' => []]), 'blocks.0.data.label');
        $this->rejects($put(['label' => 'Isi', 'fields' => [['type' => 'select', 'label' => 'Pilih', 'options' => ['Satu']]]]), 'blocks.0.data.fields.0.options');
        $this->rejects($put(['label' => 'Isi', 'fields' => [['type' => 'file', 'label' => 'Unggah KTP']]]), 'blocks.0.data.fields.0.type');
        $this->rejects($put(['label' => 'Isi', 'fields' => [['type' => 'text', 'label' => ' ']]]), 'blocks.0.data.fields.0.label');
        $this->rejects($put(['label' => 'Isi', 'fields' => array_fill(0, 13, ['type' => 'text', 'label' => 'X'])]), 'blocks.0.data.fields');

        $form = $put(self::FORM)->assertOk()->json('data.blocks.0.data');
        $this->assertSame(['Ajak Kolaborasi', 'both', 'Terima kasih! Pesan Anda sudah terkirim.'], [$form['label'], $form['contact'], $form['success']]);
        $this->assertCount(5, array_unique(array_column($form['fields'], 'id')), 'setiap pertanyaan mendapat ID unik');
        $this->assertArrayNotHasKey('options', $form['fields'][0]);

        // ID pertanyaan stabil saat disimpan ulang
        $again = $put($form)->json('data.blocks.0.data.fields');
        $this->assertSame(array_column($form['fields'], 'id'), array_column($again, 'id'));
    }

    public function test_visitor_submits_form_and_it_becomes_a_connection_with_answers(): void
    {
        ['card' => $card, 'form' => $block, 'rina' => $rina] = $this->cardWithForm();
        $public = $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->json('data.profile.blocks.0');
        $this->assertSame(['form', 'Ajak Kolaborasi'], [$public['type'], $public['label']]);
        $this->assertStringEndsWith("/api/c/{$card->card_token}/forms/{$block['id']}", $public['url']);
        $this->assertNotEmpty($public['t']);

        [$brand, $kind, $platform, $deadline, $budget] = array_column($block['data']['fields'], 'id');
        $this->rejects($this->submit($card, $block['id'], ['name' => 'Sari']), 'whatsapp');
        $this->rejects($this->submit($card, $block['id'], ['name' => 'Sari', 'whatsapp' => '081298765432']), "answers.$brand");
        $this->rejects($this->submit($card, $block['id'], ['name' => 'Sari', 'whatsapp' => '081298765432', 'answers' => [$brand => 'Kopi Nusa', $kind => 'Sponsor']]), "answers.$kind");
        $this->rejects($this->submit($card, $block['id'], ['name' => 'Sari', 'whatsapp' => '081298765432', 'answers' => [$brand => 'Kopi Nusa', $kind => 'Review', $deadline => '2026-02-31']]), "answers.$deadline");

        $this->submit($card, $block['id'], ['name' => 'Sari', 'whatsapp' => '081298765432', 'answers' => [
            $brand => 'Kopi Nusa', $kind => 'Review', $platform => ['TikTok', 'YouTube'], $deadline => '2026-12-01', $budget => '5000000',
        ]])->assertCreated()->assertJsonPath('data.message', 'Terima kasih! Pesan Anda sudah terkirim.');

        $c = Connection::withoutGlobalScope(TenantScope::class)->where('phone_e164', '+6281298765432')->firstOrFail();
        $this->assertSame(['bio_form', $card->id], [$c->source->value, $c->card_id]);
        $this->assertStringContainsString('• Platform: TikTok, YouTube', $c->note);
        $sub = FormSubmission::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertSame([$c->id, 'Kerja sama brand'], [$sub->connection_id, $sub->form_title]);
        $this->assertSame(['Nama', 'WhatsApp', 'Nama brand', 'Jenis kerja sama', 'Platform', 'Deadline', 'Budget (Rp)'], array_column($sub->answers, 'label'));

        // pengirim yang sama → koneksi yang sama, jawaban kedua tetap tercatat
        $this->submit($card, $block['id'], ['name' => 'Sari W', 'whatsapp' => '0812-9876-5432', 'answers' => [$brand => 'Kopi Nusa', $kind => 'Event']])->assertCreated();
        $this->assertSame(1, Connection::withoutGlobalScope(TenantScope::class)->where('phone_e164', '+6281298765432')->count());
        $forms = $this->actingAs($rina)->getJson($this->host('app', "/api/leads/{$c->public_id}"))->assertOk()->json('data.forms');
        $this->assertCount(2, $forms);
        $this->assertSame('Event', collect($forms[0]['answers'])->firstWhere('label', 'Jenis kerja sama')['value']);
    }

    public function test_spam_contact_rules_and_unknown_blocks(): void
    {
        ['card' => $card, 'form' => $block] = $this->cardWithForm(['label' => 'Booking', 'contact' => 'email', 'fields' => []]);
        // kontak = email saja
        $this->rejects($this->submit($card, $block['id'], ['name' => 'Budi', 'whatsapp' => '081234567890']), 'email');
        // honeypot & kiriman terlalu cepat: sukses palsu, tidak disimpan
        $this->submit($card, $block['id'], ['name' => 'Bot', 'email' => 'bot@x.com', 'website' => 'spam'])->assertCreated();
        $this->postJson($this->host('tap', "/api/c/{$card->card_token}/forms/{$block['id']}"), ['name' => 'Bot', 'email' => 'bot@x.com', 'consent' => true, 't' => Crypt::encryptString((string) time())])->assertCreated();
        $this->assertSame(0, FormSubmission::withoutGlobalScope(TenantScope::class)->count());
        // blok yang bukan formulir / tidak ada di halaman aktif → 404
        $this->submit($card, str_repeat('A', 26), ['name' => 'X', 'email' => 'x@x.com'])->assertNotFound();
        $this->submit($card, $block['id'], ['name' => 'Budi', 'email' => 'budi@x.com'])->assertCreated();
        $this->assertSame(1, FormSubmission::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_cards_assigned_to_an_event_attribute_taps_connections_and_forms_during_the_event(): void
    {
        ['card' => $card, 'form' => $block, 'rina' => $rina] = $this->cardWithForm(['label' => 'Daftar demo', 'fields' => []]);
        $other = $this->makeCard(CardStatus::Active, $this->makeUser('budi@example.com', 'Budi'));
        $this->actingAs($rina);
        $event = $this->postJson($this->host('app', '/api/events'), [
            'name' => 'Expo UMKM', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addHours(5)->toIso8601String(),
            'show_on_profile' => true, 'cards' => [$card->card_token],
        ])->assertCreated()->assertJsonPath('data.cards_count', 1)->json('data');

        // kartu orang lain tidak bisa di-assign
        $this->rejects($this->putJson($this->host('app', "/api/events/{$event['id']}/cards"), ['cards' => [$card->card_token, $other->card_token]]), 'cards');
        $show = $this->getJson($this->host('app', "/api/events/{$event['id']}"))->assertOk();
        $this->assertSame([$card->card_token], array_column($show->json('data.cards'), 'token'));
        $this->assertContains($card->card_token, array_column($show->json('data.available_cards'), 'token'));
        $this->assertSame('Expo UMKM', $this->getJson($this->host('app', "/api/cards/{$card->card_token}"))->json('data.events.0.name'));

        // tap, Tukar Kontak, dan formulir dari kartu itu tercatat ke event — tanpa Mode Event
        $profile = $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.event', 'Expo UMKM');
        $eventId = Event::withoutGlobalScope(TenantScope::class)->where('public_id', $event['id'])->value('id');
        $this->assertSame($eventId, Tap::withoutGlobalScope(TenantScope::class)->where('card_id', $card->id)->latest('created_at')->value('event_id'));
        $this->postJson($this->host('tap', "/api/c/{$card->card_token}/connect"), [
            'name' => 'Dewi', 'whatsapp' => '081211112222', 'consent' => true, 't' => $profile->json('data.profile.exchange.t'),
        ]);
        $this->travel(3)->seconds();
        $this->postJson($this->host('tap', "/api/c/{$card->card_token}/connect"), [
            'name' => 'Dewi', 'whatsapp' => '081211112222', 'consent' => true, 't' => Crypt::encryptString((string) (time() - 10)),
        ])->assertCreated();
        $this->assertSame($eventId, Connection::withoutGlobalScope(TenantScope::class)->where('phone_e164', '+6281211112222')->value('event_id'));
        $this->submit($card, $block['id'], ['name' => 'Eka', 'email' => 'eka@example.com'])->assertCreated();
        $this->assertSame($eventId, FormSubmission::withoutGlobalScope(TenantScope::class)->value('event_id'));
        $this->assertSame($eventId, Connection::withoutGlobalScope(TenantScope::class)->where('email_norm', 'eka@example.com')->value('event_id'));
        $this->getJson($this->host('app', "/api/events/{$event['id']}"))->assertJsonPath('data.summary.connections', 2);

        // setelah event selesai: tidak lagi tercatat & "Bertemu di" hilang
        $this->travel(6)->hours();
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.event', null);
        $this->submit($card, $block['id'], ['name' => 'Fajar', 'email' => 'fajar@example.com'])->assertCreated();
        $this->assertNull(Connection::withoutGlobalScope(TenantScope::class)->where('email_norm', 'fajar@example.com')->value('event_id'));

        // lepas kartu dari event
        $this->putJson($this->host('app', "/api/events/{$event['id']}/cards"), ['cards' => []])->assertOk()->assertJsonCount(0, 'data.cards');
    }
}
