<?php

namespace App\Actions\Auth;

use App\Actions\Cards\CreateDigitalCard;
use App\Enums\TenantStatus;
use App\Enums\TenantType;
use App\Models\LeadStage;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\ProfileField;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Bio\BioTheme;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/** WS-01 — setiap pengguna baru otomatis mendapat workspace Personal + profil awal + kartu digital (siap dibagikan tanpa kartu fisik). */
final class RegisterUser
{
    public function __construct(private readonly CurrentTenant $current, private readonly CreateDigitalCard $digital) {}

    public function handle(string $email, ?string $name = null, ?string $googleId = null): User
    {
        return DB::transaction(function () use ($email, $name, $googleId) {
            $name = trim($name ?: ucwords(str_replace(['.', '_', '-'], ' ', strstr($email, '@', true))));

            $user = User::create([
                'email' => strtolower(trim($email)),
                'name' => mb_substr($name, 0, 120),
                'google_id' => $googleId,
                'locale' => 'id',
                'timezone' => 'Asia/Jakarta',
                'notification_prefs' => [],
            ]);

            $tenant = Tenant::create([
                'type' => TenantType::Personal,
                'name' => $user->name,
                'owner_user_id' => $user->id,
                'status' => TenantStatus::Active,
                'plan_id' => Plan::where('key', 'personal_free')->value('id'),
                'settings' => Tenant::DEFAULT_SETTINGS,
            ]);

            $user->forceFill(['personal_tenant_id' => $tenant->id, 'last_tenant_id' => $tenant->id])->save();

            $this->current->run($tenant, function () use ($tenant, $user) {
                TenantMember::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'role_id' => Role::system('personal_owner')->id,
                    'status' => 'active',
                ]);

                foreach (LeadStage::DEFAULTS as $i => $stage) {
                    LeadStage::create([...$stage, 'sort' => $i]);
                }

                $profile = Profile::create([
                    'user_id' => $user->id,
                    'label' => 'Kerja',
                    'display_name' => $user->name,
                    'theme' => BioTheme::normalize(null),
                ]);
                ProfileField::create([
                    'profile_id' => $profile->id,
                    'type' => 'email',
                    'label' => 'Email',
                    'value' => $user->email,
                    'is_primary' => true,
                    'sort' => 0,
                ]);
            });
            $this->digital->handle($user, $tenant); // memakai profil awal di atas

            return $user;
        });
    }
}
