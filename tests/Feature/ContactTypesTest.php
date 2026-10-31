<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\ContactType;
use App\Models\ProfileField;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Jenis kontak relasional (contact_types) — normalisasi per input, tautan bio link, vCard, FK. */
class ContactTypesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function save(string $profileId, array $fields)
    {
        return $this->putJson($this->host('app', "/api/profiles/$profileId/fields"), ['fields' => $fields]);
    }

    public function test_catalog_lists_grouped_types_and_banks(): void
    {
        $this->actingAs($this->makeUser());
        $res = $this->getJson($this->host('app', '/api/contact-types'))->assertOk();
        $types = collect($res->json('data.types'));
        $this->assertGreaterThanOrEqual(40, $types->count());
        $this->assertSame(['contact', 'messaging', 'social', 'business', 'store', 'payment', 'other'], $types->pluck('group')->unique()->values()->all());
        $this->assertContains('BCA', $res->json('data.banks'));
        $this->assertSame('Instagram', $types->firstWhere('key', 'instagram')['icon']);
    }

    public function test_values_are_normalised_per_input_kind(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $id = $this->profileOf($rina)->public_id;

        $fields = $this->save($id, [
            ['type' => 'instagram', 'value' => 'https://www.instagram.com/rina.saputra/?igsh=abc'],
            ['type' => 'linkedin', 'value' => 'https://www.linkedin.com/in/rina-s/'],
            ['type' => 'tiktok', 'value' => '@rina'],
            ['type' => 'gopay', 'value' => '0812 3456 7890'],
            ['type' => 'bank', 'value' => '123-456-7890', 'meta' => ['bank' => 'BCA', 'holder' => 'Rina Saputra']],
            ['type' => 'hours', 'value' => 'Senin–Jumat 09.00–17.00'],
        ])->assertOk()->json('data.fields');

        $this->assertSame(['rina.saputra', 'rina-s', 'rina', '+6281234567890', '1234567890'], array_slice(array_column($fields, 'value'), 0, 5));
        $this->assertSame(['bank' => 'BCA', 'holder' => 'Rina Saputra'], (array) $fields[4]['meta']);
        $this->assertSame('Instagram', $fields[0]['label'], 'label bawaan dari contact_types');

        $this->save($id, [['type' => 'bank', 'value' => '1234567', 'meta' => ['bank' => 'BCA']]])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['fields.0.meta.holder']]]);
        $this->save($id, [['type' => 'instagram', 'value' => 'nama dengan spasi!']])->assertStatus(422);
        $this->save($id, [['type' => 'url', 'value' => 'http://192.168.1.1/admin']])->assertStatus(422);
        $this->save($id, [['type' => 'qris', 'value' => 't999/fields/x.png']])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['fields.0.value']]]);
        $this->save($id, [['type' => 'tidak_ada', 'value' => 'x']])->assertStatus(422);
    }

    public function test_bio_link_shows_icons_copy_and_qris_and_tracks_link_clicks(): void
    {
        Storage::fake('public');
        $rina = $this->makeUser();
        $card = $this->makeCard(CardStatus::Active, $rina);
        $this->actingAs($rina);
        $id = $this->profileOf($rina)->public_id;
        $qris = $this->postJson($this->host('app', "/api/profiles/$id/field-image"), ['image' => UploadedFile::fake()->image('qris.png', 600, 600)])
            ->assertCreated()->json('data.path');
        $this->assertStringStartsWith("t{$rina->personal_tenant_id}/fields/", $qris);

        $this->save($id, [
            ['type' => 'whatsapp', 'value' => '081234567890', 'is_primary' => true],
            ['type' => 'instagram', 'value' => 'rina'],
            ['type' => 'bank', 'value' => '9876543210', 'meta' => ['bank' => 'BCA', 'holder' => 'Rina']],
            ['type' => 'qris', 'value' => $qris],
        ])->assertOk();

        $links = collect($this->getJson($this->host('tap', "/api/c/{$card->card_token}"))->json('data.profile.links'));
        $this->assertSame(['instagram', 'bank', 'qris'], $links->pluck('type')->all(), 'WhatsApp utama tampil sebagai tombol cepat, tidak diulang');
        $ig = $links->firstWhere('type', 'instagram');
        $this->assertSame(['Instagram', '@rina', 'link'], [$ig['icon'], $ig['display'], $ig['action']]);
        $bank = $links->firstWhere('type', 'bank');
        $this->assertSame(['copy', '9876543210', null], [$bank['action'], $bank['copy'], $bank['url']]);
        $this->assertStringEndsWith('.png', $links->firstWhere('type', 'qris')['image']);

        $this->get($ig['url'])->assertRedirect('https://instagram.com/rina');
        $this->assertSame(1, ProfileField::withoutGlobalScope(TenantScope::class)->where('type', 'instagram')->value('click_count'));
        $bankId = ProfileField::withoutGlobalScope(TenantScope::class)->where('type', 'bank')->value('public_id');
        $this->get($this->host('tap', "/c/{$card->card_token}/l/$bankId"))->assertNotFound();

        $vcf = $this->get($this->host('tap', "/c/{$card->card_token}/vcard"))->assertOk()->getContent();
        $this->assertStringContainsString('https://instagram.com/rina', $vcf);
        $this->assertStringNotContainsString('9876543210', $vcf, 'rekening tidak masuk vCard');
    }

    public function test_field_type_is_a_foreign_key(): void
    {
        $rina = $this->makeUser();
        $this->expectException(QueryException::class);
        $this->inTenantOf($rina, fn () => ProfileField::create(['profile_id' => $this->profileOf($rina)->id, 'type' => 'bukan_jenis', 'label' => 'x', 'value' => 'x']));
    }

    public function test_contact_type_in_use_cannot_be_deleted(): void
    {
        $rina = $this->makeUser();
        $this->inTenantOf($rina, fn () => ProfileField::create(['profile_id' => $this->profileOf($rina)->id, 'type' => 'instagram', 'label' => 'IG', 'value' => 'rina']));
        $this->expectException(QueryException::class);
        ContactType::where('key', 'instagram')->delete();
    }
}
