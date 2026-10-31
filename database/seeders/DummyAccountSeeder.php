<?php

namespace Database\Seeders;

use App\Actions\Auth\RegisterUser;
use App\Models\Card;
use App\Models\PlatformStaff;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Akun dummy untuk pengembangan lokal: `php artisan db:seed --class=DummyAccountSeeder`.
 * Aman dijalankan berulang. Semua akun login dengan email + password DEV_PASSWORD
 * (pengguna di app., staf di panel.). Kode verifikasi daftar/lupa password lokal = DEV_LOGIN_CODE.
 */
class DummyAccountSeeder extends Seeder
{
    /** email => [nama, peran platform atau null untuk pengguna biasa] */
    public const ACCOUNTS = [
        'demo@tapcard.test' => ['Rina Saputra', null],
        'budi@tapcard.test' => ['Budi Hartono', null],
        'admin@tapcard.test' => ['Admin Platform', 'platform_owner'],
        'produksi@tapcard.test' => ['Staf Produksi', 'production'],
        'support@tapcard.test' => ['Staf Support', 'support'],
        'keuangan@tapcard.test' => ['Staf Keuangan', 'finance'],
    ];

    public const DEV_PASSWORD = 'password123';

    public function run(): void
    {
        foreach (self::ACCOUNTS as $email => [$name, $role]) {
            $user = User::whereRaw('lower(email) = ?', [$email])->first()
                ?? app(RegisterUser::class)->handle($email, $name);

            $user->forceFill(['password' => self::DEV_PASSWORD])->save();
            if ($role) {
                PlatformStaff::updateOrCreate(
                    ['user_id' => $user->id],
                    ['role_id' => Role::system($role)->id, 'status' => 'active'],
                );
            }
        }

        // Kartu, riwayat tap, dan koneksi contoh untuk akun demo (sekali saja).
        $demo = User::where('email', 'demo@tapcard.test')->first();
        if (! Card::acrossTenants()->where('holder_user_id', $demo->id)->where('form', Card::PHYSICAL)->exists()) {
            $this->call(DemoSeeder::class);
        }

        $this->command?->table(['Email', 'Peran', 'Login di', 'Password'], collect(self::ACCOUNTS)->map(fn ($v, $email) => [
            $email,
            $v[1] ? Role::system($v[1])->name : 'Pengguna',
            $v[1] ? config('tapcard.panel_subdomain').'.localhost:8000' : 'app.localhost:8000',
            self::DEV_PASSWORD,
        ])->values()->all());
    }
}
