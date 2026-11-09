<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\LeadStage;
use App\Models\Notification;
use App\Models\Profile;
use App\Support\Bio\BioTheme;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Endpoint JSON pulau Vue workspace Personal (BSD §8.4). */
class WorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_spa_shell_is_served_for_non_api_paths(): void
    {
        foreach (['/', '/login', '/dashboard', '/cards/abc', '/profiles/x', '/settings'] as $path) {
            $this->get($this->host('app', $path))->assertOk()->assertSee('id="app"', false)->assertDontSee('Rina');
        }
        $this->getJson($this->host('app', '/api/tidak-ada'))->assertNotFound();
        $this->getJson($this->host('app', '/api/me'))->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_me_and_settings_and_preview(): void
    {
        $rina = $this->makeUser();
        $profile = $this->profileOf($rina);
        $this->actingAs($rina);
        $this->getJson($this->host('app', '/api/me'))->assertOk()
            ->assertJsonPath('data.email', 'rina@example.com')
            ->assertJsonPath('data.workspace.contact_label', 'Koneksi')
            ->assertJsonPath('data.is_staff', false);
        $this->getJson($this->host('app', '/api/me/settings'))->assertOk()->assertJsonPath('data.plan.name', 'Personal Free');
        $this->getJson($this->host('app', "/api/profiles/{$profile->public_id}/preview"))->assertOk()
            ->assertJsonPath('data.display_name', 'Rina Saputra')->assertJsonPath('data.vcard_url', null);
    }

    public function test_dashboard_summary_counts_taps(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"));
        $this->get($this->host('tap', "/c/{$card->card_token}/vcard"));

        $this->actingAs($rina)->getJson($this->host('app', '/api/dashboard'))
            ->assertOk()->assertJsonPath('data.kpi.taps_7', 1)->assertJsonPath('data.kpi.saved_30', 1)
            ->assertJsonPath('data.kpi.save_rate', 100)->assertJsonCount(30, 'data.series');
    }

    public function test_every_card_gets_its_own_bio_link(): void
    {
        Storage::fake('public');
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $first = $this->makeCard(CardStatus::Unclaimed, buyer: $rina);
        $this->postJson($this->host('app', '/api/cards/activate'), ['token' => $first->card_token])->assertOk();
        $bio1 = $first->fresh()->profile;
        $this->assertNotSame($this->profileOf($rina)->id, $bio1->id, 'profil awal dipegang kartu digital; kartu fisik mendapat salinan');

        // isi bio kartu pertama: field + foto
        $this->putJson($this->host('app', "/api/profiles/{$bio1->public_id}/fields"), ['fields' => [
            ['type' => 'whatsapp', 'label' => 'WhatsApp', 'value' => '081234567890', 'is_primary' => true],
        ]])->assertOk();
        $this->postJson($this->host('app', "/api/profiles/{$bio1->public_id}/avatar"), ['photo' => UploadedFile::fake()->image('a.jpg', 500, 500)])->assertOk();
        $this->patchJson($this->host('app', "/api/profiles/{$bio1->public_id}"), ['title' => 'Konsultan Pajak'])->assertOk();

        // kartu kedua: dapat salinan bio terakhir, bukan profil yang sama
        $second = $this->makeCard(CardStatus::Unclaimed, buyer: $rina);
        $this->postJson($this->host('app', '/api/cards/activate'), ['token' => $second->card_token])->assertOk();
        $bio2 = $second->fresh()->profile;
        $this->assertNotSame($bio1->id, $bio2->id);
        $this->assertSame('Konsultan Pajak', $bio2->title);
        $this->assertSame('Kartu '.$second->formattedShortCode(), $bio2->label);
        $this->assertSame(['+6281234567890'], $bio2->fields()->pluck('value')->all());
        $this->assertNotSame($bio1->fresh()->avatar_path, $bio2->avatar_path, 'foto disalin ke file baru');
        Storage::disk('public')->assertExists($bio2->avatar_path);

        // mengubah bio kartu kedua tidak memengaruhi kartu pertama, dan resolver langsung ikut berubah
        $this->getJson($this->host('tap', "/api/c/{$second->card_token}"))->assertJsonPath('data.profile.display_name', 'Rina Saputra');
        $this->patchJson($this->host('app', "/api/profiles/{$bio2->public_id}"), ['display_name' => 'Rina (Komunitas)'])->assertOk();
        $this->getJson($this->host('tap', "/api/c/{$second->card_token}"))->assertJsonPath('data.profile.display_name', 'Rina (Komunitas)');
        $this->getJson($this->host('tap', "/api/c/{$first->card_token}"))->assertJsonPath('data.profile.display_name', 'Rina Saputra');

        // profil kartu tidak bisa lagi dipindah lewat API kartu
        $this->patchJson($this->host('app', "/api/cards/{$first->card_token}"), ['profile_id' => $bio2->public_id])->assertOk();
        $this->assertSame($bio1->id, $first->fresh()->profile_id);
    }

    public function test_migration_splits_cards_that_shared_one_profile(): void
    {
        $rina = $this->makeUser();
        Card::acrossTenants()->where('form', 'digital')->delete(); // data lama: sebelum ada kartu digital
        $a = $this->makeCard(CardStatus::Active, $rina);
        $b = $this->makeCard(CardStatus::Active, $rina);
        $this->assertSame($a->profile_id, $b->profile_id);
        Profile::acrossTenants()->whereKey($a->profile_id)->update(['theme' => json_encode(['template' => 'ivory', 'mode' => 'light'])]);

        (require database_path('migrations/2026_11_05_000100_one_bio_per_card.php'))->up();

        $this->assertSame($this->profileOf($rina)->id, $a->fresh()->profile_id, 'kartu tertua tetap memegang aslinya');
        $copy = Profile::acrossTenants()->find($b->fresh()->profile_id);
        $this->assertNotSame($a->profile_id, $copy->id);
        $this->assertSame('ivory', $copy->theme['preset']);
        $this->assertArrayNotHasKey('template', $copy->theme);
    }

    public function test_bio_theme_options_are_validated_and_resolved_to_palette_tokens(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->actingAs($rina);
        $id = $this->profileOf($rina)->public_id;

        $this->getJson($this->host('app', '/api/bio/themes'))->assertOk()->assertJsonCount(8, 'data.presets')
            ->assertJsonPath('data.options.links', ['list', 'buttons', 'grid']);
        $this->patchJson($this->host('app', "/api/profiles/$id"), ['theme' => ['preset' => 'neon']])->assertStatus(422);
        $this->patchJson($this->host('app', "/api/profiles/$id"), ['theme' => ['accent' => '#FF0000']])->assertStatus(422);

        $theme = $this->patchJson($this->host('app', "/api/profiles/$id"), ['theme' => ['preset' => 'ivory', 'links' => 'grid', 'header' => 'cover', 'accent' => 'sky-700']])
            ->assertOk()->json('data.theme');
        $this->assertSame(['ivory', 'light', 'grid', 'cover', 'sky-700', 'bone-50'], [$theme['preset'], $theme['mode'], $theme['links'], $theme['header'], $theme['vars']['accent'], $theme['vars']['bg']]);
        // opsi lain dipertahankan saat hanya satu yang diubah
        $theme = $this->patchJson($this->host('app', "/api/profiles/$id"), ['theme' => ['corners' => 'pill']])->json('data.theme');
        $this->assertSame(['ivory', 'grid', 'pill'], [$theme['preset'], $theme['links'], $theme['corners']]);
        // aksen yang tak terlihat di latar diganti warna teks
        $theme = $this->patchJson($this->host('app', "/api/profiles/$id"), ['theme' => ['preset' => 'onyx', 'accent' => 'ink-950']])->json('data.theme');
        $this->assertSame('bone-50', $theme['vars']['accent']);

        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.profile.theme.preset', 'onyx')
            ->assertJsonPath('data.profile.theme.vars.accent', 'bone-50');
        $this->assertSame('kreatif', BioTheme::normalize(['template' => 'kreatif', 'mode' => 'dark', 'bg' => 'onyx-950'])['preset'], 'tema lama dipetakan');
    }

    public function test_landing_showcase_is_public(): void
    {
        $this->getJson('http://localhost/api/showcase')->assertOk()
            ->assertJsonCount(43, 'data.templates')
            ->assertJsonPath('data.templates.0.preview.portrait.front.size.w', 54)
            ->assertJsonCount(8, 'data.bio.presets')
            ->assertJsonPath('data.sample.display_name', 'Rina Saputra')
            ->assertJsonPath('data.plans.0.key', 'personal_free');
        $this->get('http://localhost/')->assertOk()->assertSee('id="app"', false);
    }

    /** Regresi: `editor.widths.find is not a function` — daftar katalog harus array JSON, tetap saat dibaca dari cache. */
    public function test_landing_showcase_catalog_lists_stay_json_arrays_when_cached(): void
    {
        foreach ([1, 2] as $_) { // panggilan ke-2 membaca dari cache
            $res = $this->getJson('http://localhost/api/showcase')->assertOk();
            $this->assertTrue(array_is_list($res->json('data.editor.widths')), 'widths harus list');
            $this->assertTrue(array_is_list($res->json('data.editor.hardware')), 'hardware harus list');
            $this->assertNotEmpty($res->json('data.editor.widths'));
        }
    }

    public function test_report_lost_then_found(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->actingAs($rina);
        $this->patchJson($this->host('app', "/api/cards/{$card->card_token}"), ['status' => 'lost'])->assertOk()->assertJsonPath('data.status', 'lost');
        $this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->assertJsonPath('data.state', 'inactive');
        $this->patchJson($this->host('app', "/api/cards/{$card->card_token}"), ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertNull(Card::acrossTenants()->find($card->id)->lost_at);
    }

    public function test_profile_fields_are_validated_and_normalized(): void
    {
        $rina = $this->makeUser();
        $profile = $this->profileOf($rina);
        $this->actingAs($rina);
        $url = $this->host('app', "/api/profiles/{$profile->public_id}/fields");

        $this->putJson($url, ['fields' => [['type' => 'url', 'value' => 'http://192.168.1.1']]])->assertStatus(422);
        $this->putJson($url, ['fields' => [['type' => 'url', 'value' => 'javascript:alert(1)']]])->assertStatus(422);
        $res = $this->putJson($url, ['fields' => [
            ['type' => 'whatsapp', 'value' => '0812 3456 7890', 'is_primary' => true],
            ['type' => 'url', 'value' => 'contoh.id', 'label' => 'Website'],
            ['type' => 'email', 'value' => 'RINA@Contoh.ID', 'is_primary' => true],
        ]])->assertOk();
        $fields = collect($res->json('data.fields'));
        $this->assertSame('+6281234567890', $fields->firstWhere('type', 'whatsapp')['value']);
        $this->assertSame('https://contoh.id', $fields->firstWhere('type', 'url')['value']);
        $this->assertSame('rina@contoh.id', $fields->firstWhere('type', 'email')['value']);
        $this->assertSame(['whatsapp', 'url', 'email'], $fields->pluck('type')->all(), 'urutan dipertahankan');
    }

    public function test_lead_stage_lost_requires_reason_and_activities_are_logged(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $id = $this->postJson($this->host('app', '/api/leads'), ['name' => 'Budi', 'phone' => '0812-0000-1111'])->assertCreated()->json('data.id');
        $lost = LeadStage::withoutGlobalScope(TenantScope::class)->where('tenant_id', $rina->personal_tenant_id)->where('kind', 'lost')->first();

        $this->postJson($this->host('app', "/api/leads/$id/stage"), ['stage_id' => $lost->public_id])->assertStatus(422);
        $this->postJson($this->host('app', "/api/leads/$id/stage"), ['stage_id' => $lost->public_id, 'lost_reason' => 'Harga'])
            ->assertOk()->assertJsonPath('data.stage.kind', 'lost')->assertJsonPath('data.lost_reason', 'Harga');
        $this->postJson($this->host('app', "/api/leads/$id/activities"), ['type' => 'whatsapp'])->assertOk();
        $this->postJson($this->host('app', "/api/leads/$id/activities"), ['type' => 'note', 'body' => 'Follow up minggu depan'])->assertOk();
        $this->patchJson($this->host('app', "/api/leads/$id"), ['tags' => ['prospek', 'vip "a"']])->assertOk()->assertJsonPath('data.tags', ['prospek', 'vip "a"']);

        $types = collect($this->getJson($this->host('app', "/api/leads/$id"))->json('data.activities'))->pluck('type')->all();
        $this->assertSame(['note', 'whatsapp', 'stage_changed', 'created'], $types);

        $this->getJson($this->host('app', '/api/leads?q=bud'))->assertJsonCount(1, 'data');
        $this->getJson($this->host('app', '/api/leads?tag=prospek'))->assertJsonCount(1, 'data');
        $csv = $this->get($this->host('app', '/api/leads/export.csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Budi', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.exported']);
    }

    public function test_notifications_endpoint_and_mark_read(): void
    {
        $rina = $this->makeUser();
        Notification::create(['user_id' => $rina->id, 'tenant_id' => $rina->personal_tenant_id, 'type' => 'tap', 'data' => ['city' => 'Jakarta']]);
        $this->actingAs($rina)->getJson($this->host('app', '/api/notifications'))->assertJsonPath('meta.unread', 1);
        $this->postJson($this->host('app', '/api/notifications/read'))->assertOk();
        $this->getJson($this->host('app', '/api/notifications'))->assertJsonPath('meta.unread', 0);
    }

    public function test_stream_emits_new_notifications(): void
    {
        $rina = $this->makeUser();
        $n = Notification::create(['user_id' => $rina->id, 'tenant_id' => $rina->personal_tenant_id, 'type' => 'lead_new', 'data' => ['name' => 'Budi']]);
        $body = $this->actingAs($rina)->get($this->host('app', '/api/stream?after='.($n->id - 1)))->assertOk()->streamedContent();
        $this->assertStringContainsString("id: {$n->id}\nevent: lead_new\n", $body);
    }

    public function test_account_deletion_revokes_cards(): void
    {
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->actingAs($rina)->deleteJson($this->host('app', '/api/me'), ['confirm_email' => 'salah@example.com'])->assertStatus(422);
        $this->deleteJson($this->host('app', '/api/me'), ['confirm_email' => 'rina@example.com'])->assertOk();
        $this->assertSame(CardStatus::Revoked, Card::acrossTenants()->find($card->id)->status);
        $this->assertSoftDeleted('users', ['id' => $rina->id]);
    }

    public function test_handle_must_be_unique_and_valid(): void
    {
        $this->makeUser('a@example.com')->update(['handle' => 'rina']);
        $this->actingAs($this->makeUser('b@example.com'));
        $this->patchJson($this->host('app', '/api/me'), ['handle' => 'rina'])->assertStatus(422);
        $this->patchJson($this->host('app', '/api/me'), ['handle' => 'Rina!'])->assertStatus(422);
        $this->patchJson($this->host('app', '/api/me'), ['handle' => 'admin'])->assertStatus(422);
        $this->patchJson($this->host('app', '/api/me'), ['handle' => 'rina.s'])->assertOk()->assertJsonPath('data.handle', 'rina.s');
    }

    public function test_cannot_delete_profile_in_use(): void
    {
        $rina = $this->makeUser();
        $this->makeCard(CardStatus::Active, $rina);
        $profile = Profile::acrossTenants()->where('user_id', $rina->id)->first();
        $this->actingAs($rina)->deleteJson($this->host('app', "/api/profiles/{$profile->public_id}"))->assertStatus(422)->assertJsonPath('error.code', 'profile_in_use');
    }
}
