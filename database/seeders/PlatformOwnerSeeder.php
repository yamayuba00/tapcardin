<?php

namespace Database\Seeders;

use App\Actions\Auth\RegisterUser;
use App\Models\PlatformStaff;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** BSD §10 — Pemilik Platform dari env SEED_PLATFORM_OWNER_EMAIL. */
class PlatformOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('tapcard.seed_platform_owner_email');
        if (! $email) {
            $this->command?->warn('SEED_PLATFORM_OWNER_EMAIL kosong — Pemilik Platform tidak dibuat.');

            return;
        }

        $user = User::whereRaw('lower(email) = ?', [strtolower($email)])->first()
            ?? app(RegisterUser::class)->handle(email: $email, name: 'Pemilik Platform');

        PlatformStaff::updateOrCreate(
            ['user_id' => $user->id],
            ['role_id' => Role::system('platform_owner')->id, 'status' => 'active'],
        );

        // Login admin. memakai email + password. Password yang sudah ada tidak ditimpa.
        if (! $user->password) {
            $password = config('tapcard.seed_platform_owner_password') ?: Str::password(16, symbols: false);
            $user->forceFill(['password' => $password])->save();
            if (! config('tapcard.seed_platform_owner_password')) {
                $this->command?->warn("Password Pemilik Platform ($email): $password — simpan sekarang, lalu ganti di admin → Akun.");
            }
        }
    }
}
