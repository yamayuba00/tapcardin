<?php

namespace Database\Seeders;

use App\Actions\Auth\RegisterUser;
use App\Actions\Cards\ActivateCard;
use App\Actions\Leads\CreateConnection;
use App\Actions\Production\GenerateBatchTokens;
use App\Actions\Production\ShipBatch;
use App\Enums\BatchStatus;
use App\Enums\ChipType;
use App\Enums\LeadSource;
use App\Models\Batch;
use App\Models\Card;
use App\Models\Profile;
use App\Models\ProfileField;
use App\Models\Tap;
use App\Models\User;
use App\Support\Hasher;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Data contoh untuk pengembangan lokal: `php artisan db:seed --class=DemoSeeder`.
 * Membuat pengguna demo@tapcard.test dengan 1 kartu aktif + riwayat tap, dan 1 kartu siap aktivasi.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'demo@tapcard.test')->first()
            ?? app(RegisterUser::class)->handle('demo@tapcard.test', 'Rina Saputra');
        $user->update(['handle' => 'rina']);
        $tenant = $user->personalTenant;
        $current = app(CurrentTenant::class);

        $batch = Batch::create(['code' => 'DEMO-'.now()->format('His'), 'status' => BatchStatus::Draft, 'chip_type' => ChipType::Ntag213,
            'quantity' => 2, 'holder_user_id' => $user->id, 'tenant_id' => $tenant->id, 'created_by' => $user->id]);
        app(GenerateBatchTokens::class)->handle($batch);
        $batch->cards()->update(['encoded_at' => now(), 'qc_passed_at' => now()]);
        app(ShipBatch::class)->handle($batch->fresh(), 'DEMO');

        [$active, $spare] = $batch->cards()->orderBy('sequence')->get()->all();
        // kartu kedua: aktivasi lewat kode (bukan pembeli) untuk mencoba alur P-02
        $spare->forceFill(['assigned_user_id' => null, 'activation_code_hash' => Hash::make('7K2M9QXP')])->save();
        app(ActivateCard::class)->handle($active->card_token, $user);
        Card::acrossTenants()->whereKey($active->id)->update(['label' => 'Kartu hitam']);

        $current->run($tenant, function () use ($user, $active) {
            $profile = Profile::where('user_id', $user->id)->first();
            $profile->update(['title' => 'Konsultan Pajak', 'company' => 'Saputra & Rekan',
                'bio' => "Membantu UMKM merapikan pajak & laporan keuangan.\nKonsultasi awal gratis."]);
            ProfileField::where('profile_id', $profile->id)->delete();
            foreach ([
                ['whatsapp', 'WhatsApp', '+6281234567890', true], ['email', 'Email', 'demo@tapcard.test', true],
                ['url', 'Website', 'https://saputra-rekan.id', false], ['social', 'LinkedIn', 'https://linkedin.com/in/rinasaputra', false],
                ['address', 'Kantor', 'Jl. Sudirman No. 1, Jakarta', false],
            ] as $i => [$type, $label, $value, $primary]) {
                ProfileField::create(['profile_id' => $profile->id, 'type' => $type, 'label' => $label, 'value' => $value, 'is_primary' => $primary, 'sort' => $i]);
            }

            $cities = ['Jakarta', 'Bandung', 'Surabaya', null];
            for ($d = 29; $d >= 0; $d--) {
                for ($n = 0, $max = random_int(0, 6); $n < $max; $n++) {
                    $at = now()->subDays($d)->setTime(random_int(8, 20), random_int(0, 59));
                    Tap::create([
                        'card_id' => $active->id, 'profile_id' => $profile->id, 'holder_user_id' => $user->id,
                        'source' => random_int(0, 3) ? 'nfc' : 'qr', 'ip_hash' => Hasher::ip("10.0.$d.$n"),
                        'visitor_hash' => Hasher::visitor("10.0.$d.$n", 'demo', $at->toDateString()),
                        'city' => $cities[array_rand($cities)], 'device' => random_int(0, 1) ? 'ios' : 'android',
                        'saved_contact' => random_int(0, 2) === 0, 'created_at' => $at,
                    ]);
                }
            }
            foreach ([['Budi Hartono', '081298765432', 'PT Contoh'], ['Sari Wulandari', '081377788899', 'CV Maju']] as [$name, $phone, $co]) {
                app(CreateConnection::class)->handle(['name' => $name, 'phone' => $phone, 'company' => $co, 'consent' => true], LeadSource::ExchangeForm, $user->id, ['card_id' => $active->id]);
            }
        });

        $this->command?->info('Demo siap. Login: demo@tapcard.test (kode OTP muncul di storage/logs).');
        $this->command?->info('Kartu aktif : '.config('tapcard.resolver_url')."/c/{$active->card_token}");
        $this->command?->info('Kartu baru  : '.config('tapcard.resolver_url')."/c/{$spare->card_token}  (kode aktivasi 7K2M-9QXP)");
    }
}
