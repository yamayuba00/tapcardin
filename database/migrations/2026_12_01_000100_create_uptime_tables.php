<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pemantauan uptime (ala Uptime Kuma) — monitor HTTP/HTTPS (+ kata kunci) & masa berlaku SSL, riwayat cek (90 hari),
 * perubahan status, serta insiden / maintenance yang tampil di halaman status publik `status.{base}`.
 * Monitor bawaan: aplikasi, tap kartu, landing, panel, dan SSL domain utama (alamat dari config saat migrasi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('name', 80);
            $t->string('type', 8); // http | ssl
            $t->string('url', 500);
            $t->string('method', 8)->default('GET');
            $t->string('expected_status', 40)->default('200-399'); // "200-399" atau "200,301"
            $t->string('keyword', 200)->nullable(); // harus ada di isi halaman (http)
            $t->smallInteger('interval_seconds')->default(60);
            $t->smallInteger('timeout_seconds')->default(10);
            $t->smallInteger('retries')->default(1); // gagal berturut-turut tambahan sebelum dinyatakan down
            $t->smallInteger('ssl_warn_days')->default(14);
            $t->boolean('is_public')->default(true); // tampil di halaman status
            $t->string('public_name', 80)->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('status', 10)->default('pending'); // pending | up | degraded | down | paused
            $t->smallInteger('fail_streak')->default(0);
            $t->timestampTz('last_checked_at')->nullable();
            $t->timestampTz('next_check_at')->nullable();
            $t->timestampTz('last_change_at')->nullable();
            $t->integer('last_response_ms')->nullable();
            $t->smallInteger('last_http_status')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->timestampTz('ssl_expires_at')->nullable();
            $t->smallInteger('sort')->default(0);
            $t->timestampsTz();
            $t->index(['is_active', 'next_check_at']);
        });
        DB::statement("ALTER TABLE monitors ADD CONSTRAINT monitors_type_chk CHECK (type IN ('http','ssl'))");
        DB::statement("ALTER TABLE monitors ADD CONSTRAINT monitors_status_chk CHECK (status IN ('pending','up','degraded','down','paused'))");

        Schema::create('monitor_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $t->string('status', 10); // up | degraded | down
            $t->integer('response_ms')->nullable();
            $t->smallInteger('http_status')->nullable();
            $t->string('error', 300)->nullable();
            $t->timestampTz('checked_at');
            $t->index(['monitor_id', 'checked_at']);
            $t->index('checked_at');
        });

        Schema::create('monitor_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 10);
            $t->string('to_status', 10);
            $t->string('reason', 300)->nullable();
            $t->timestampTz('created_at');
            $t->index(['monitor_id', 'created_at']);
        });

        Schema::create('incidents', function (Blueprint $t) {
            $t->id();
            $t->char('public_id', 26)->unique();
            $t->string('kind', 12); // incident | maintenance
            $t->string('title', 150);
            $t->string('impact', 12); // minor | major | maintenance
            $t->string('status', 14); // investigating | identified | monitoring | resolved | scheduled | in_progress | completed
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at')->nullable(); // rencana selesai (maintenance)
            $t->timestampTz('resolved_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['resolved_at', 'starts_at']);
        });
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_kind_chk CHECK (kind IN ('incident','maintenance'))");
        DB::statement("ALTER TABLE incidents ADD CONSTRAINT incidents_impact_chk CHECK (impact IN ('minor','major','maintenance'))");

        Schema::create('incident_updates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $t->string('status', 14);
            $t->text('body');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestampTz('created_at');
            $t->index(['incident_id', 'created_at']);
        });

        $this->seedDefaults();
    }

    private function seedDefaults(): void
    {
        $app = rtrim((string) config('app.url'), '/');
        $u = parse_url($app);
        $origin = fn (string $sub) => ($u['scheme'] ?? 'https').'://'.($sub ? "$sub." : '').config('tapcard.base_domain').(isset($u['port']) ? ':'.$u['port'] : '');
        $rows = [
            ['Aplikasi', 'http', $app.'/up', 'Aplikasi (app.)', true],
            ['Tap kartu & bio link', 'http', rtrim((string) config('tapcard.resolver_url'), '/').'/up', 'Tap kartu & bio link', true],
            ['Situs utama', 'http', $origin('').'/up', 'Situs utama', true],
            ['Panel pemilik', 'http', $origin(config('tapcard.panel_subdomain')).'/up', null, false],
            ['Sertifikat SSL', 'ssl', 'https://'.config('tapcard.base_domain'), null, false],
        ];
        foreach ($rows as $i => [$name, $type, $url, $public, $isPublic]) {
            DB::table('monitors')->insert([
                'public_id' => (string) Str::ulid(), 'name' => $name, 'type' => $type, 'url' => $url,
                'interval_seconds' => $type === 'ssl' ? 3600 : 60, 'public_name' => $public, 'is_public' => $isPublic,
                'sort' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('monitor_events');
        Schema::dropIfExists('monitor_checks');
        Schema::dropIfExists('monitors');
    }
};
