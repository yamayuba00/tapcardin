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
 * Aman dijalankan berulang. Pengguna (app.) login dengan kode OTP DEV_LOGIN_CODE (123456 di lokal);
 * staf (admin.) login dengan email + password STAFF_PASSWORD.
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

    public const STAFF_PASSWORD = 'password123';

    public function run(): void
    {
        foreach (self::ACCOUNTS as $email => [$name, $role]) {
            $user = User::whereRaw('lower(email) = ?', [$email])->first()
                ?? app(RegisterUser::class)->handle($email, $name);

            if ($role) {
                PlatformStaff::updateOrCreate(
                    ['user_id' => $user->id],
                    ['role_id' => Role::system($role)->id, 'status' => 'active'],
                );
                $user->forceFill(['password' => self::STAFF_PASSWORD])->save();
            }
        }

        // Kartu, riwayat tap, dan koneksi contoh untuk akun demo (sekali saja).
        $demo = User::where('email', 'demo@tapcard.test')->first();
        if (! Card::acrossTenants()->where('holder_user_id', $demo->id)->exists()) {
            $this->call(DemoSeeder::class);
        }

        $code = config('tapcard.dev_login_code') ?: '(lihat storage/logs)';
        $this->command?->table(['Email', 'Peran', 'Login di', 'Kredensial'], collect(self::ACCOUNTS)->map(fn ($v, $email) => [
            $email,
            $v[1] ? Role::system($v[1])->name : 'Pengguna',
            $v[1] ? 'admin.localhost:8000' : 'app.localhost:8000',
            $v[1] ? 'password: '.self::STAFF_PASSWORD : "kode OTP: $code",
        ])->values()->all());
    }
}
