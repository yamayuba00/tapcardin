<?php

namespace App\Actions\Auth;

use App\Actions\Cards\ChangeCardStatus;
use App\Enums\CardStatus;
use App\Enums\TenantStatus;
use App\Enums\TenantType;
use App\Exceptions\ApiError;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * PV-02 / AFD F-15 — hapus akun dalam dua tahap.
 *
 * Saat diminta (handle): semua kartu dicabut, langganan dihentikan, keluar dari semua workspace & perangkat, lalu
 * IDENTITAS DILEPAS — email, Google, nama pengguna, dan password dikosongkan. Pendaftaran berikutnya dengan email/Google
 * yang sama menjadi akun BARU yang kosong; akun lama tidak pernah dipulihkan atau dilanjutkan.
 *
 * Setelah RETENTION_DAYS (purge, lewat `tapcard:prune`): data pribadi dihapus permanen — profil & bio link (+ foto),
 * koneksi & catatannya, isian formulir, desain, impor/ekspor, notifikasi, keranjang. Yang DISIMPAN sebagai riwayat
 * (tanpa identitas): kartu berstatus dicabut (kartu tercetak menampilkan "tidak berlaku"), statistik tap teranonimkan,
 * invoice/pesanan/pembayaran (catatan keuangan), dan log audit.
 */
final class DeleteAccount
{
    public const RETENTION_DAYS = 30;

    public function __construct(private readonly ChangeCardStatus $status) {}

    public function handle(User $user): void
    {
        $owned = Tenant::where('owner_user_id', $user->id)->where('type', '!=', TenantType::Personal->value)
            ->whereNotIn('status', [TenantStatus::PendingDeletion->value, TenantStatus::Deleted->value])->pluck('name');
        if ($owned->isNotEmpty()) {
            throw new ApiError('owns_workspace', 'Anda masih pemilik workspace '.$owned->join(', ').'. Alihkan kepemilikan atau tutup workspace itu dulu.');
        }

        [$email, $name] = [$user->email, $user->name]; // identitas dilepas di bawah — simpan untuk email konfirmasi
        DB::transaction(function () use ($user) {
            Card::acrossTenants()->where('holder_user_id', $user->id)->whereNotIn('status', [CardStatus::Revoked->value])->get()
                ->each(function (Card $c) {
                    // active/paused tidak bisa langsung revoked (AFD §4.1): lewat lost dulu
                    if (! $c->isDigital() && in_array($c->status, [CardStatus::Active, CardStatus::Paused], true)) {
                        $this->status->handle($c, CardStatus::Lost, 'Akun dihapus');
                    }
                    $this->status->handle($c, CardStatus::Revoked, 'Akun dihapus');
                });

            if ($tid = $user->personal_tenant_id) {
                DB::table('subscriptions')->where('tenant_id', $tid)->whereNotIn('status', ['cancelled', 'expired'])
                    ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_at_period_end' => false, 'updated_at' => now()]);
                DB::table('invoices')->where('tenant_id', $tid)->where('status', 'issued')->update(['status' => 'void', 'updated_at' => now()]);
                DB::table('tenants')->where('id', $tid)->update(['status' => TenantStatus::PendingDeletion->value, 'deletion_requested_at' => now(), 'updated_at' => now()]);
            }
            DB::table('tenant_members')->where('user_id', $user->id)->update(['status' => 'removed']);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('card_login_keys')->where('user_id', $user->id)->delete();

            if ($user->personal_tenant_id) {
                Audit::tenant($user->personal_tenant_id, 'account.deleted', $user);
            }
            $user->forceFill([
                'email' => "deleted-{$user->id}@invalid", 'google_id' => null, 'handle' => null, 'password' => null,
                'remember_token' => null, 'phone' => null,
            ])->save();
            $user->delete();
        });
        Notify::email($email, $name, 'account_deleted', ['purge_at' => now()->addDays(self::RETENTION_DAYS)->toIso8601String()]);
    }

    /** Tahap kedua — data pribadi akun yang dihapus > RETENTION_DAYS hari lalu. */
    public static function purge(int $userId): void
    {
        $disk = Storage::disk('public');
        $tid = DB::table('users')->where('id', $userId)->value('personal_tenant_id');

        $files = DB::transaction(function () use ($userId, $tid) {
            $profiles = Profile::withoutGlobalScopes()->where('user_id', $userId)->get();
            $files = $profiles->flatMap(fn ($p) => [$p->avatar_path, $p->vcard_photo_path]);
            Profile::withoutGlobalScopes()->whereIn('id', $profiles->pluck('id'))->delete(); // field & bio link ikut terhapus (cascade)

            if ($tid) {
                $files = $files->merge(DB::table('designs')->where('tenant_id', $tid)->get(['preview_front_path', 'preview_back_path', 'print_pdf_path'])
                    ->flatMap(fn ($d) => (array) $d))
                    ->merge(DB::table('lead_imports')->where('tenant_id', $tid)->pluck('path'))
                    ->merge(DB::table('data_exports')->where('tenant_id', $tid)->pluck('path'));
                DB::table('form_submissions')->where('tenant_id', $tid)->delete();
                Connection::withoutGlobalScopes()->where('tenant_id', $tid)->update(['merged_into_id' => null]);
                Connection::withoutGlobalScopes()->where('tenant_id', $tid)->delete(); // aktivitas & pengingat ikut (cascade)
                DB::table('lead_imports')->where('tenant_id', $tid)->delete();
                DB::table('data_exports')->where('tenant_id', $tid)->delete();
                DB::table('cart_items')->where('tenant_id', $tid)->delete();
                DB::table('designs')->where('tenant_id', $tid)->delete(); // kartu & pesanan: design_id → null (snapshot cetak tetap)
                DB::table('tenants')->where('id', $tid)->update(['name' => 'Workspace dihapus', 'slug' => null, 'logo_path' => null,
                    'brand' => '{}', 'status' => TenantStatus::Deleted->value, 'deleted_at' => now(), 'updated_at' => now()]);
            }
            DB::table('notifications')->where('user_id', $userId)->delete();
            DB::table('cart_items')->where('user_id', $userId)->delete();
            DB::table('designs')->where('user_id', $userId)->update(['user_id' => null]);

            DB::table('taps')->where('holder_user_id', $userId)->update(['holder_user_id' => null, 'ip_hash' => str_repeat('0', 64), 'visitor_hash' => str_repeat('0', 64)]);
            DB::table('users')->where('id', $userId)->update([
                'name' => 'Pengguna dihapus', 'email' => "deleted-$userId@invalid", 'phone' => null, 'google_id' => null,
                'handle' => null, 'avatar_path' => null, 'remember_token' => null, 'password' => null,
                'notification_prefs' => '{}', 'preferences' => '{}',
            ]);

            return $files;
        });
        $files->filter()->unique()->each(fn ($f) => rescue(fn () => $disk->delete($f), report: false));
    }
}
