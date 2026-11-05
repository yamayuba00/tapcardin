<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\BioBlock;
use App\Models\BioPage;
use App\Models\Card;
use App\Support\Bio\BioBlocks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $this->assertSame(['Utama', true, 'contacts'], [$pages[0]['name'], $pages[0]['is_active'], $pages[0]['blocks'][0]['type']]);
        // panggilan kedua tidak membuat halaman ganda
        $this->getJson($this->pagesUrl($id))->assertJsonCount(1, 'data');
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.blocks.0.type', 'contacts');
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
        $this->rejects($this->save($id, $page, [['type' => 'gallery', 'data' => ['images' => [['path' => 't999/bio/'.str_repeat('a', 32).'.webp']]]]]), 'blocks.0.data.images');
        $this->save($id, $page, [['type' => 'html', 'data' => []]])->assertStatus(422);

        $saved = $this->save($id, $page, [
            ['type' => 'text', 'data' => ['title' => 'Halo', 'body' => 'Kreator konten', 'align' => 'center']],
            ['type' => 'button', 'data' => ['label' => 'Ajak Kolaborasi', 'url' => 'wa.me/6281234567890', 'style' => 'primary']],
            ['type' => 'video', 'data' => ['url' => 'https://youtu.be/dQw4w9WgXcQ']],
            ['type' => 'divider', 'is_visible' => false, 'data' => ['style' => 'line']],
            ['id' => $page['blocks'][0]['id'], 'type' => 'contacts'],
        ], ['theme' => ['preset' => 'ivory', 'links' => 'grid']])->assertOk();
        $this->assertSame(['text', 'button', 'video', 'divider', 'contacts'], array_column($saved->json('data.blocks'), 'type'));
        $this->assertSame($page['blocks'][0]['id'], $saved->json('data.blocks.4.id'), 'blok yang ada diperbarui, bukan dibuat ulang');
        $this->assertSame('https://wa.me/6281234567890', $saved->json('data.blocks.1.data.url'), 'tanpa skema → https://');

        $blocks = $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))
            ->assertJsonPath('data.profile.theme.preset', 'ivory')->json('data.profile.blocks');
        $this->assertSame(['text', 'button', 'video', 'contacts'], array_column($blocks, 'type'), 'blok tersembunyi tidak tampil');
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
        $this->assertSame(['contacts'], array_column($personal['blocks'], 'type'));
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

    public function test_gallery_images_upload_copy_and_cleanup(): void
    {
        Storage::fake('public');
        $rina = $this->makeUser();
        $id = $this->profileOf($rina)->public_id;
        $page = $this->actingAs($rina)->getJson($this->pagesUrl($id))->json('data.0');
        $img = fn () => $this->postJson($this->host('app', "/api/profiles/$id/bio-image"), ['image' => UploadedFile::fake()->image('a.jpg', 1600, 900)])
            ->assertCreated()->json('data.path');
        [$a, $b] = [$img(), $img()];
        $this->assertMatchesRegularExpression("#^t{$rina->personal_tenant_id}/bio/[A-Za-z0-9]{32}\.webp$#", $a);

        $saved = $this->save($id, $page, [['type' => 'gallery', 'data' => ['layout' => 'carousel', 'images' => [['path' => $a, 'caption' => 'Rumah A'], ['path' => $b]]]]])
            ->assertOk()->json('data.blocks.0.data');
        $this->assertSame(['carousel', 'Rumah A'], [$saved['layout'], $saved['images'][0]['caption']]);
        $this->assertStringEndsWith($a, $saved['images'][0]['url']);

        // salinan halaman memakai file sendiri
        $copy = $this->postJson($this->pagesUrl($id), ['name' => 'Salinan', 'from' => $page['id']])->json('data');
        $copyPaths = array_column($copy['blocks'][0]['data']['images'], 'path');
        $this->assertEmpty(array_intersect([$a, $b], $copyPaths));
        Storage::disk('public')->assertExists($copyPaths);

        // gambar yang dibuang dari galeri dihapus; menghapus halaman salinan menghapus file salinannya
        $this->save($id, $page, [['type' => 'gallery', 'data' => ['images' => [['path' => $a]]]]])->assertOk();
        Storage::disk('public')->assertMissing($b);
        Storage::disk('public')->assertExists($a);
        $this->deleteJson($this->pagesUrl($id, "/{$copy['id']}"))->assertOk();
        Storage::disk('public')->assertMissing($copyPaths);
        Storage::disk('public')->assertExists($a);
    }

    public function test_second_card_copies_all_pages_and_other_users_cannot_touch_them(): void
    {
        Storage::fake('public');
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
