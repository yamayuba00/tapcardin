<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\BioBlock;
use App\Models\BioPage;
use App\Models\Card;
use App\Support\Bio\BioBlocks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bio link berbasis blok: maks. 3 halaman per kartu, satu aktif, blok tervalidasi per jenis. */
class BioPagesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function pagesUrl(string $profile, string $suffix = ''): string
    {
        return $this->host('app', "/api/profiles/$profile/pages$suffix");
    }

    private function save(string $profile, array $page, array $blocks, array $extra = [])
    {
        return $this->putJson($this->pagesUrl($profile, "/{$page['id']}"), array_merge(['name' => $page['name'], 'theme' => $page['theme'], 'blocks' => $blocks], $extra));
    }

    private function rejects($res, string $key): void
    {
        $res->assertStatus(422);
        $this->assertArrayHasKey($key, $res->json('error.fields') ?? [], (string) $res->getContent());
    }

    public function test_profile_without_pages_keeps_legacy_view_then_gets_default_page(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.blocks', null);

        $id = $this->profileOf($rina)->public_id;
        $pages = $this->actingAs($rina)->getJson($this->pagesUrl($id))->assertOk()->assertJsonPath('meta.limit', 3)->json('data');
        $this->assertCount(1, $pages);
        $this->assertSame(['Utama', true, ['save_contact', 'contacts']], [$pages[0]['name'], $pages[0]['is_active'], array_column($pages[0]['blocks'], 'type')]);
        $this->assertSame('Simpan ke Kontak HP', $pages[0]['blocks'][0]['data']['label']);
        // panggilan kedua tidak membuat halaman ganda
        $this->getJson($this->pagesUrl($id))->assertJsonCount(1, 'data');
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.blocks.0.type', 'save_contact')
            ->assertJsonPath('data.profile.blocks.1.type', 'contacts');
    }

    public function test_blocks_are_validated_saved_in_order_and_rendered_publicly(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $id = $this->profileOf($rina)->public_id;
        $page = $this->actingAs($rina)->getJson($this->pagesUrl($id))->json('data.0');

        // tautan berbahaya / video tak didukung / teks kosong ditolak
        $this->rejects($this->save($id, $page, [['type' => 'button', 'data' => ['label' => 'X', 'url' => 'javascript:alert(1)']]]), 'blocks.0.data.url');
        $this->rejects($this->save($id, $page, [['type' => 'video', 'data' => ['url' => 'https://evil.example/embed']]]), 'blocks.0.data.url');
        $this->rejects($this->save($id, $page, [['type' => 'text', 'data' => ['title' => ' ', 'body' => '']]]), 'blocks.0.data.body');
        $this->rejects($this->save($id, $page, [['type' => 'contacts'], ['type' => 'contacts']]), 'blocks.1.type');
        $this->rejects($this->save($id, $page, [['type' => 'save_contact'], ['type' => 'save_contact']]), 'blocks.1.type');
        $this->rejects($this->save($id, $page, [['type' => 'gallery', 'data' => ['images' => [['path' => 'x.webp']]]]]), 'blocks.0.type'); // galeri tidak tersedia
        $this->save($id, $page, [['type' => 'html', 'data' => []]])->assertStatus(422);

        $saved = $this->save($id, $page, [
            ['type' => 'text', 'data' => ['title' => 'Halo', 'body' => 'Kreator konten', 'align' => 'center']],
            ['type' => 'button', 'data' => ['label' => 'Ajak Kolaborasi', 'url' => 'wa.me/6281234567890', 'style' => 'primary']],
            ['type' => 'video', 'data' => ['url' => 'https://youtu.be/dQw4w9WgXcQ']],
            ['type' => 'divider', 'is_visible' => false, 'data' => ['style' => 'line']],
            ['id' => $page['blocks'][1]['id'], 'type' => 'contacts'],
            ['type' => 'save_contact', 'data' => ['label' => '  ']],
        ], ['theme' => ['preset' => 'ivory', 'links' => 'grid']])->assertOk();
        $this->assertSame(['text', 'button', 'video', 'divider', 'contacts', 'save_contact'], array_column($saved->json('data.blocks'), 'type'));
        $this->assertSame($page['blocks'][1]['id'], $saved->json('data.blocks.4.id'), 'blok yang ada diperbarui, bukan dibuat ulang');
        $this->assertSame('Simpan ke Kontak HP', $saved->json('data.blocks.5.data.label'), 'teks kosong → teks bawaan');
        $this->assertSame('https://wa.me/6281234567890', $saved->json('data.blocks.1.data.url'), 'tanpa skema → https://');

        $blocks = $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))
            ->assertJsonPath('data.profile.theme.preset', 'ivory')->json('data.profile.blocks');
        $this->assertSame(['text', 'button', 'video', 'contacts', 'save_contact'], array_column($blocks, 'type'), 'blok tersembunyi tidak tampil');
        $this->assertSame(['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', '16/9'], [$blocks[2]['embed_url'], $blocks[2]['ratio']]);
        $this->assertArrayNotHasKey('url', $blocks[2], 'URL asli tidak perlu dikirim ke publik');
        $this->assertSame('ivory', $this->profileOf($rina)->theme['preset'], 'tema halaman aktif dicerminkan ke profil');
    }

    public function test_max_three_pages_only_one_active_and_active_cannot_be_deleted(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $id = $this->profileOf($rina)->public_id;
        $main = $this->actingAs($rina)->getJson($this->pagesUrl($id))->json('data.0');
        $this->save($id, $main, [['type' => 'text', 'data' => ['body' => 'Kerja']]])->assertOk();

        $event = $this->postJson($this->pagesUrl($id), ['name' => 'Pameran', 'from' => $main['id']])->assertCreated()->json('data');
        $this->assertFalse($event['is_active']);
        $this->assertSame('Kerja', $event['blocks'][0]['data']['body'], 'salinan membawa blok');
        $this->assertNotSame($main['blocks'][0]['id'] ?? null, $event['blocks'][0]['id']);
        $personal = $this->postJson($this->pagesUrl($id), ['name' => 'Personal'])->assertCreated()->json('data');
        $this->assertSame(['save_contact', 'contacts'], array_column($personal['blocks'], 'type'));
        $this->postJson($this->pagesUrl($id), ['name' => 'Keempat'])->assertStatus(422)->assertJsonPath('error.code', 'bio_page_limit');

        // mengaktifkan satu menonaktifkan yang lain; resolver langsung ikut
        $this->save($id, $event, [['type' => 'text', 'data' => ['body' => 'Booth A12']]], ['theme' => ['preset' => 'kreatif']])->assertOk();
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.blocks.0.body', 'Kerja');
        $pages = $this->postJson($this->pagesUrl($id, "/{$event['id']}/activate"))->assertOk()->json('data');
        $this->assertSame([false, true, false], array_column($pages, 'is_active'));
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))
            ->assertJsonPath('data.profile.blocks.0.body', 'Booth A12')->assertJsonPath('data.profile.theme.preset', 'kreatif');

        $this->deleteJson($this->pagesUrl($id, "/{$event['id']}"))->assertStatus(422)->assertJsonPath('error.code', 'bio_page_active');
        $this->deleteJson($this->pagesUrl($id, "/{$main['id']}"))->assertOk()->assertJsonCount(2, 'data');
        $this->postJson($this->pagesUrl($id), ['name' => 'Baru lagi'])->assertCreated();

        // database sendiri menolak dua halaman aktif
        $this->expectException(QueryException::class);
        BioPage::acrossTenants()->where('profile_id', $this->profileOf($rina)->id)->update(['is_active' => true]);
    }

    public function test_second_card_copies_all_pages_and_other_users_cannot_touch_them(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $first = $this->makeCard(CardStatus::Unclaimed, buyer: $rina);
        $this->postJson($this->host('app', '/api/cards/activate'), ['token' => $first->card_token])->assertOk();
        $bio1 = $first->fresh()->profile->public_id;
        $main = $this->getJson($this->pagesUrl($bio1))->json('data.0');
        $this->save($bio1, $main, [['type' => 'button', 'data' => ['label' => 'Portofolio', 'url' => 'https://behance.net/rina']]])->assertOk();
        $this->postJson($this->pagesUrl($bio1), ['name' => 'Event'])->assertCreated();

        $second = $this->makeCard(CardStatus::Unclaimed, buyer: $rina);
        $this->postJson($this->host('app', '/api/cards/activate'), ['token' => $second->card_token])->assertOk();
        $pages2 = $this->getJson($this->pagesUrl($second->fresh()->profile->public_id))->json('data');
        $this->assertSame([['Utama', true], ['Event', false]], array_map(fn ($p) => [$p['name'], $p['is_active']], $pages2));
        $this->getJson($this->host('tap', "/api/c/{$second->card_token}"))->assertJsonPath('data.profile.blocks.0.label', 'Portofolio');

        // pengguna lain: 404, dan id halaman dari profil lain tidak bisa dipakai
        $budi = $this->makeUser('budi@example.com', 'Budi');
        $this->actingAs($budi)->getJson($this->pagesUrl($bio1))->assertNotFound();
        $budiProfile = $this->profileOf($budi)->public_id;
        $this->getJson($this->pagesUrl($budiProfile))->assertOk();
        $this->postJson($this->pagesUrl($budiProfile, "/{$main['id']}/activate"))->assertNotFound();
        $this->assertSame(1, BioBlock::acrossTenants()->where('bio_page_id', BioPage::acrossTenants()->where('public_id', $main['id'])->value('id'))->count());
        $this->assertSame(1, Card::acrossTenants()->where('card_token', $first->card_token)->count());
    }

    /** Regresi: cache kartu dibersihkan untuk SEMUA kartu profil (dulu berhenti di kartu pertama yang belum ter-cache). */
    public function test_profile_change_refreshes_every_card_of_the_profile(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina); // profil yang sama dengan kartu digital (belum pernah di-cache)
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.display_name', 'Rina Saputra');
        $id = $this->profileOf($rina)->public_id;
        $this->actingAs($rina)->patchJson($this->host('app', "/api/profiles/$id"), ['display_name' => 'Rina S.'])->assertOk();
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.display_name', 'Rina S.');
    }

    /** Regresi: kontak baru yang disisipkan di tengah tetap di posisinya (validated() mengacak urutan array bersarang). */
    public function test_contact_fields_keep_submitted_order_when_mixing_new_and_existing(): void
    {
        $rina = $this->makeUser();
        $id = $this->profileOf($rina)->public_id;
        $url = $this->host('app', "/api/profiles/$id/fields");
        $saved = $this->actingAs($rina)->putJson($url, ['fields' => [
            ['type' => 'email', 'value' => 'a@example.com'], ['type' => 'linkedin', 'value' => 'https://www.linkedin.com/in/rina-s/'],
        ]])->assertOk()->json('data.fields');
        $order = $this->putJson($url, ['fields' => [
            ['id' => $saved[0]['id'], 'type' => 'email', 'value' => 'a@example.com'],
            ['type' => 'instagram', 'value' => 'rina'],
            ['id' => $saved[1]['id'], 'type' => 'linkedin', 'value' => 'https://www.linkedin.com/in/rina-s/'],
        ]])->assertOk()->json('data.fields');
        $this->assertSame(['email', 'instagram', 'linkedin'], array_column($order, 'type'));
    }

    /** "Simpan ke Kontak HP" tidak terasa sebagai unduhan: Android → form kontak bawaan, iPhone → vCard inline. */
    public function test_save_contact_opens_contact_form_instead_of_downloading(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $id = $this->profileOf($rina)->public_id;
        $this->actingAs($rina)->putJson($this->host('app', "/api/profiles/$id/fields"), ['fields' => [
            ['type' => 'whatsapp', 'value' => '081234567890', 'is_primary' => true], ['type' => 'email', 'value' => 'rina@example.com'],
        ]])->assertOk();
        $url = $this->host('tap', "/c/{$card->card_token}/vcard");
        $chrome = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

        $intent = $this->withHeader('User-Agent', $chrome)->get($url)->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('intent:#Intent;action=android.intent.action.INSERT;type=vnd.android.cursor.dir/raw_contact;', $intent);
        $this->assertStringContainsString('S.name=Rina%20Saputra;', $intent);
        $this->assertStringContainsString('S.phone=%2B6281234567890;', $intent);
        $this->assertStringContainsString('S.email=rina%40example.com;', $intent);
        $this->assertStringContainsString('S.browser_fallback_url='.rawurlencode("$url?file=1").';', $intent);
        $this->assertStringEndsWith(';end', $intent);

        // WebView aplikasi (Instagram) & iPhone → vCard inline, bukan lampiran
        $ig = 'Mozilla/5.0 (Linux; Android 14; wv) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36 Instagram 300.0';
        $this->withHeader('User-Agent', $ig)->get($url)->assertOk()->assertHeader('Content-Type', 'text/vcard; charset=utf-8');
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';
        $res = $this->withHeader('User-Agent', $iphone)->get($url)->assertOk();
        $this->assertStringStartsWith('inline;', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('FN:Rina Saputra', $res->getContent());
        $this->withHeader('User-Agent', $chrome)->get("$url?file=1")->assertOk()->assertHeader('Content-Type', 'text/vcard; charset=utf-8');
    }

    public function test_bio_image_upload_endpoint_is_gone(): void
    {
        $rina = $this->makeUser();
        $id = $this->profileOf($rina)->public_id;
        $this->actingAs($rina)->postJson($this->host('app', "/api/profiles/$id/bio-image"))->assertNotFound();
    }

    public function test_video_and_link_parsers(): void
    {
        $cases = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10' => ['youtube', 'dQw4w9WgXcQ', 'video'],
            'https://m.youtube.com/shorts/dQw4w9WgXcQ' => ['youtube', 'dQw4w9WgXcQ', 'short'],
            'https://youtu.be/dQw4w9WgXcQ?si=x' => ['youtube', 'dQw4w9WgXcQ', 'video'],
            'https://vimeo.com/76979871' => ['vimeo', '76979871', 'video'],
            'https://www.tiktok.com/@rina/video/7234567890123456789' => ['tiktok', '7234567890123456789', 'video'],
            'https://open.spotify.com/intl-id/track/4uLU6hMCjMI75M1A2tKUQC' => ['spotify', '4uLU6hMCjMI75M1A2tKUQC', 'track'],
        ];
        foreach ($cases as $url => [$provider, $vid, $kind]) {
            $this->assertSame(['provider' => $provider, 'id' => $vid, 'kind' => $kind], BioBlocks::video($url), $url);
        }
        foreach (['https://youtube.com/watch?v=short', 'javascript:alert(1)', 'https://evil.com/youtube.com/watch?v=dQw4w9WgXcQ', 'ftp://vimeo.com/76979871'] as $bad) {
            $this->assertNull(BioBlocks::video($bad), $bad);
        }
        $this->assertSame(352, BioBlocks::embed('spotify', 'x', 'playlist')['height']);
        $this->assertSame('mailto:rina@example.com', BioBlocks::link('mailto:rina@example.com'));
        $this->assertSame('tel:+62 812-3456', BioBlocks::link('tel:+62 812-3456'));
        foreach (['javascript:alert(1)', 'data:text/html,x', 'https://localhost', 'https://user:pw@example.com', ''] as $bad) {
            $this->assertNull(BioBlocks::link($bad), $bad);
        }
    }
}
