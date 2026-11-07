<?php

namespace Tests\Feature;

use App\Enums\CardStatus;
use App\Models\Addon;
use App\Models\Card;
use App\Models\PlatformStaff;
use App\Models\Profile;
use App\Models\ProfileField;
use App\Models\Role;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Onboarding pengguna baru, add-on "segera hadir" (Integrasi CRM), dan PWA. */
class OnboardingAddonPwaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function api(string $method, string $path, array $data = [])
    {
        return $this->json($method, $this->host('app', "/api$path"), $data);
    }

    public function test_onboarding_steps_complete_from_real_data_and_can_be_dismissed(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $o = $this->api('GET', '/dashboard')->assertOk()->json('data.onboarding');
        $this->assertSame(['profile', 'handle', 'share', 'physical'], array_column($o['steps'], 'key'));
        $this->assertSame(0, $o['done']);
        $this->assertSame(17, $o['profile']['percent'], 'hanya email dari pendaftaran (1/6)');

        // lengkapi bio kartu digital + @nama + bagikan
        $digital = Card::acrossTenants()->where(['holder_user_id' => $rina->id, 'form' => 'digital'])->firstOrFail();
        $profile = Profile::withoutGlobalScope(TenantScope::class)->find($digital->profile_id);
        $profile->forceFill(['title' => 'Konsultan', 'bio' => 'Halo', 'avatar_path' => 'avatars/x.jpg'])->save();
        foreach ([['whatsapp', '+628123'], ['instagram', 'rina']] as [$type, $value]) {
            ProfileField::withoutGlobalScope(TenantScope::class)->create(['profile_id' => $profile->id, 'tenant_id' => $profile->tenant_id, 'type' => $type, 'label' => $type, 'value' => $value]);
        }
        $rina->update(['handle' => 'rina']);
        $this->api('POST', "/cards/{$digital->card_token}/shared", ['channel' => 'whatsapp'])->assertNoContent();
        $this->api('POST', "/cards/{$digital->card_token}/shared", ['channel' => 'fax'])->assertUnprocessable();

        $o = $this->api('GET', '/dashboard')->json('data.onboarding');
        $this->assertSame(100, $o['profile']['percent']);
        $this->assertSame(3, $o['done']);

        // kartu fisik aktif → semua selesai → panduan hilang sendiri
        $this->makeCard(CardStatus::Active, $rina);
        $this->assertNull($this->api('GET', '/dashboard')->json('data.onboarding'));

        $lain = $this->makeUser('lain@example.com', 'Lain');
        $this->actingAs($lain);
        $this->api('POST', '/me/onboarding', ['dismissed' => true])->assertNoContent();
        $this->assertNull($this->api('GET', '/dashboard')->json('data.onboarding'));
        $this->api('POST', '/me/onboarding', ['dismissed' => false])->assertNoContent();
        $this->assertNotNull($this->api('GET', '/dashboard')->json('data.onboarding'));
    }

    public function test_crm_addon_is_coming_soon_with_one_interest_per_workspace(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina);
        $a = $this->api('GET', '/addons')->assertOk()->json('data.0');
        $this->assertSame(['crm', 'coming_soon', null], [$a['key'], $a['status'], $a['interested_at']]);

        $this->api('POST', '/addons/crm/interest', ['note' => 'HubSpot'])->assertOk()->assertJsonPath('data.interested_at', fn ($v) => $v !== null);
        $this->api('POST', '/addons/crm/interest')->assertOk(); // idempoten
        $this->assertDatabaseCount('addon_interests', 1);
        $this->assertDatabaseHas('addon_interests', ['tenant_id' => $rina->personal_tenant_id, 'note' => 'HubSpot']);

        $this->expectException(QueryException::class);
        Addon::where('key', 'crm')->update(['status' => 'beta']); // CHECK constraint
    }

    public function test_panel_manages_addon_and_sees_interest_count(): void
    {
        $rina = $this->makeUser();
        $this->actingAs($rina)->api('POST', '/addons/crm/interest');

        $owner = $this->makeUser('pemilik-addon@tapcard.test', 'Owner');
        PlatformStaff::create(['user_id' => $owner->id, 'role_id' => Role::system('platform_owner')->id, 'status' => 'active', 'last_reverified_at' => now()]);
        $this->actingAs($owner);
        $addon = collect($this->getJson($this->host('panel', '/api/catalog'))->assertOk()->json('data.addons'))->firstWhere('key', 'crm');
        $this->assertSame(1, $addon['interests']);

        $this->patchJson($this->host('panel', "/api/catalog/addons/{$addon['public_id']}"), ['price_monthly' => 39000, 'status' => 'available'])->assertOk();
        $this->assertDatabaseHas('addons', ['key' => 'crm', 'price_monthly' => 39000, 'status' => 'available']);

        $this->actingAs($rina);
        $this->api('POST', '/addons/crm/interest')->assertJsonPath('error.code', 'addon_not_coming_soon');
    }

    public function test_pwa_manifest_on_app_and_panel_hosts_and_assets_exist(): void
    {
        $this->get($this->host('app', '/login'))->assertOk()->assertSee('href="/manifest.webmanifest"', false)->assertSee('apple-touch-icon', false);
        $this->get($this->host('tap', '/k'))->assertOk()->assertDontSee('rel="manifest"', false);
        $this->get('http://localhost/')->assertOk()->assertDontSee('rel="manifest"', false);
        $this->get($this->host('panel', '/login'))->assertOk()->assertSee('href="/manifest-panel.webmanifest"', false)
            ->assertSee('/icons/panel-apple-touch-icon.png', false)->assertSee('/favicon-admin.svg', false);

        $panel = json_decode(file_get_contents(public_path('manifest-panel.webmanifest')), true);
        $this->assertSame(['standalone', '/'], [$panel['display'], $panel['scope']]);
        foreach ([...array_column($panel['icons'], 'src'), '/icons/panel-apple-touch-icon.png'] as $path) {
            $this->assertFileExists(public_path(ltrim($path, '/')), $path);
        }
        $this->assertSame([512, 512], array_slice(getimagesize(public_path('icons/panel-maskable-512.png')), 0, 2));

        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/share?source=pwa', $manifest['shortcuts'][0]['url']);
        foreach ([...array_column($manifest['icons'], 'src'), '/sw.js', '/offline.html', '/icons/apple-touch-icon.png'] as $path) {
            $this->assertFileExists(public_path(ltrim($path, '/')), $path);
        }
        $this->assertSame([512, 512], array_slice(getimagesize(public_path('icons/maskable-512.png')), 0, 2));
    }
}
