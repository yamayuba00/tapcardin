<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\StaffAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** BSD §10 — Pemilik Platform (akun staf, bukan pengguna app.) dari env SEED_PLATFORM_OWNER_EMAIL. */
class PlatformOwnerSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('tapcard.seed_platform_owner_email');
        if (! $email) {
            $this->command?->warn('SEED_PLATFORM_OWNER_EMAIL kosong — Pemilik Platform tidak dibuat.');

            return;
        }

        $email = strtolower(trim($email));
        $staff = StaffAccount::whereRaw('lower(email) = ?', [$email])->first() ?? new StaffAccount(['email' => $email, 'name' => 'Pemilik Platform']);
        $staff->fill(['role_id' => Role::system('platform_owner')->id, 'status' => 'active'])->save();

        // Login panel memakai email + password. Password yang sudah ada tidak ditimpa.
        if (! $staff->password) {
            $password = config('tapcard.seed_platform_owner_password') ?: Str::password(16, symbols: false);
            $staff->forceFill(['password' => $password])->save();
            if (! config('tapcard.seed_platform_owner_password')) {
                $this->command?->warn("Password Pemilik Platform ($email): $password — simpan sekarang, lalu ganti di panel → Akun.");
            }
        }
    }
}
