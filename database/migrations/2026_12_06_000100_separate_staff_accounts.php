<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Staf panel dipisah dari pengguna app.: tabel `staff_accounts` + sesi sendiri. Data platform_staff disalin,
 * kolom aksi panel dipetakan ulang ke id staf, kolom campuran (audit, riwayat kartu) diberi `actor_type`.
 * Baris lama di `users` tetap ada sebagai pelanggan biasa.
 */
return new class extends Migration
{
    /** Kolom yang hanya diisi dari panel → FK pindah ke staff_accounts. */
    private const MOVED = [
        ['batches', 'created_by'], ['tenant_domains', 'created_by'], ['vouchers', 'created_by'], ['announcements', 'created_by'],
        ['incidents', 'created_by'], ['incident_updates', 'user_id'], ['impersonations', 'staff_user_id'], ['profile_reports', 'handled_by'],
        ['refunds', 'requested_by'], ['refunds', 'decided_by'], ['refunds', 'paid_by'],
        ['approval_requests', 'requested_by'], ['approval_requests', 'decided_by'],
        ['payment_reviews', 'resolved_by'], ['orders', 'reviewed_by'], ['card_sales', 'sold_by_user_id'],
    ];

    /** Kolom id staf tanpa FK. */
    private const PLAIN = [['platform_settings', 'updated_by'], ['feature_flags', 'updated_by']];

    public function up(): void
    {
        Schema::create('staff_accounts', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('name', 120);
            $t->string('email', 190);
            $t->string('password')->nullable();
            $t->timestampTz('password_changed_at')->nullable();
            $t->foreignId('role_id')->constrained('roles');
            $t->string('status', 10)->default('active');
            $t->foreignId('invited_by')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $t->jsonb('ip_allowlist')->nullable();
            $t->timestampTz('last_reverified_at')->nullable();
            $t->timestampTz('last_login_at')->nullable();
            $t->rememberToken();
            $t->unsignedBigInteger('legacy_user_id')->nullable(); // hanya selama migrasi
            $t->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX staff_accounts_email_lower_uq ON staff_accounts (lower(email))');

        Schema::create('staff_sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        foreach (DB::table('platform_staff')->join('users', 'users.id', '=', 'platform_staff.user_id')
            ->select('platform_staff.*', 'users.name', 'users.email', 'users.password', 'users.password_changed_at', 'users.last_login_at', 'users.blocked_at', 'users.deleted_at')
            ->orderBy('platform_staff.id')->get() as $s) {
            DB::table('staff_accounts')->insert([
                'public_id' => (string) Str::ulid(), 'name' => $s->name, 'email' => strtolower($s->email), 'password' => $s->password,
                'password_changed_at' => $s->password_changed_at, 'role_id' => $s->role_id,
                'status' => $s->blocked_at || $s->deleted_at ? 'revoked' : $s->status,
                'ip_allowlist' => $s->ip_allowlist, 'last_reverified_at' => $s->last_reverified_at, 'last_login_at' => $s->last_login_at,
                'legacy_user_id' => $s->user_id, 'created_at' => $s->created_at, 'updated_at' => $s->updated_at,
            ]);
        }
        DB::statement('UPDATE staff_accounts s SET invited_by = m.id FROM platform_staff ps, staff_accounts m
            WHERE ps.user_id = s.legacy_user_id AND m.legacy_user_id = ps.invited_by');

        foreach (self::MOVED as [$table, $col]) {
            Schema::table($table, function (Blueprint $t) use ($col) {
                $t->dropForeign([$col]);
                $t->unsignedBigInteger($col)->nullable()->change();
            });
            $this->remap($table, $col, 'legacy_user_id', 'id');
            Schema::table($table, fn (Blueprint $t) => $t->foreign($col)->references('id')->on('staff_accounts')->nullOnDelete());
        }
        foreach (self::PLAIN as [$table, $col]) {
            $this->remap($table, $col, 'legacy_user_id', 'id');
        }

        Schema::table('card_status_history', fn (Blueprint $t) => $t->dropForeign(['actor_user_id']));
        foreach (['audit_logs', 'card_status_history'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('actor_type', 5)->default('user'));
        }
        // aksi panel lama: audit lingkup platform; riwayat kartu yang punya audit platform kartu sama dari aktor sama (±60 detik)
        DB::statement("UPDATE audit_logs a SET actor_type = 'staff', actor_user_id = s.id FROM staff_accounts s
            WHERE a.scope = 'platform' AND a.actor_user_id = s.legacy_user_id");
        DB::statement("UPDATE card_status_history h SET actor_type = 'staff', actor_user_id = s.id FROM staff_accounts s
            WHERE h.actor_user_id = s.legacy_user_id AND EXISTS (SELECT 1 FROM audit_logs a WHERE a.actor_type = 'staff' AND a.actor_user_id = s.id
                AND a.subject_type = 'Card' AND a.subject_id = h.card_id AND abs(extract(epoch FROM a.created_at - h.created_at)) <= 60)");

        Schema::dropIfExists('platform_staff');
        Schema::table('staff_accounts', fn (Blueprint $t) => $t->dropColumn('legacy_user_id'));
    }

    /** Balik ke platform_staff memakai email yang sama di `users`; staf tanpa akun pengguna ber-email sama tidak ikut. */
    public function down(): void
    {
        Schema::create('platform_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->foreignId('role_id')->constrained('roles');
            $t->string('status', 10)->default('active');
            $t->foreignId('invited_by')->nullable()->constrained('users');
            $t->timestampTz('last_reverified_at')->nullable();
            $t->jsonb('ip_allowlist')->nullable();
            $t->timestampsTz();
        });
        Schema::table('staff_accounts', fn (Blueprint $t) => $t->unsignedBigInteger('legacy_user_id')->nullable());
        DB::statement('UPDATE staff_accounts s SET legacy_user_id = u.id FROM users u WHERE lower(u.email) = lower(s.email)');
        DB::statement('INSERT INTO platform_staff (user_id, role_id, status, last_reverified_at, ip_allowlist, created_at, updated_at)
            SELECT legacy_user_id, role_id, status, last_reverified_at, ip_allowlist, created_at, updated_at FROM staff_accounts WHERE legacy_user_id IS NOT NULL');
        DB::statement('UPDATE platform_staff ps SET invited_by = i.legacy_user_id FROM staff_accounts s, staff_accounts i
            WHERE s.legacy_user_id = ps.user_id AND i.id = s.invited_by');

        foreach (['audit_logs', 'card_status_history'] as $table) {
            DB::statement("UPDATE $table h SET actor_user_id = s.legacy_user_id FROM staff_accounts s WHERE h.actor_type = 'staff' AND h.actor_user_id = s.id");
            DB::statement("UPDATE $table SET actor_user_id = NULL WHERE actor_type = 'staff' AND actor_user_id NOT IN (SELECT legacy_user_id FROM staff_accounts WHERE legacy_user_id IS NOT NULL)");
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('actor_type'));
        }
        Schema::table('card_status_history', fn (Blueprint $t) => $t->foreign('actor_user_id')->references('id')->on('users'));
        foreach (self::PLAIN as [$table, $col]) {
            $this->remap($table, $col, 'id', 'legacy_user_id');
        }
        foreach (self::MOVED as [$table, $col]) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign([$col]));
            $this->remap($table, $col, 'id', 'legacy_user_id');
            Schema::table($table, fn (Blueprint $t) => $t->foreign($col)->references('id')->on('users')->nullOnDelete());
        }

        Schema::dropIfExists('staff_sessions');
        Schema::dropIfExists('staff_accounts');
    }

    /** Ganti nilai kolom lewat peta staff_accounts.{$from} → {$to}; nilai tanpa pasangan dikosongkan. */
    private function remap(string $table, string $col, string $from, string $to): void
    {
        DB::statement("UPDATE $table t SET $col = m.nv
            FROM (SELECT DISTINCT t2.$col AS ov, s.$to AS nv FROM $table t2 LEFT JOIN staff_accounts s ON s.$from = t2.$col WHERE t2.$col IS NOT NULL) m
            WHERE t.$col = m.ov");
    }
};
