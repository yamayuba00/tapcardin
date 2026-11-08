<?php

namespace App\Http\Controllers\Platform;

use App\Events\StatusPageUpdated;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Support\Audit;
use App\Support\PlatformAlerts;
use App\Support\Uptime\Checker;
use App\Support\Uptime\StatusPage;
use App\Support\Uptime\Uptime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Panel → Uptime: monitor HTTP/SSL & info insiden/maintenance untuk halaman status publik. Lihat: dashboard.ops, ubah: system.manage. */
class UptimeController extends PlatformController
{
    public function index(): JsonResponse
    {
        $this->can('dashboard.ops');

        return $this->ok([
            'monitors' => Uptime::list(),
            'incidents' => Incident::with('updates')->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', now()->subDays(30)))
                ->orderByRaw('resolved_at is not null')->latest('starts_at')->get()->map(fn ($i) => StatusPage::presentIncident($i))->values(),
            'status_url' => PlatformAlerts::statusUrl(),
            'statuses' => Incident::STATUSES,
        ]);
    }

    public function show(string $monitor): JsonResponse
    {
        $this->can('dashboard.ops');

        return $this->ok(Uptime::detail($this->find($monitor)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->can('system.manage');
        $m = Monitor::create($this->validated($request) + ['sort' => (int) Monitor::max('sort') + 1, 'next_check_at' => now()]);
        Audit::platform('uptime.monitor_created', $m);
        $this->changed();

        return $this->ok(Uptime::presentMonitor($m), 201);
    }

    public function update(Request $request, string $monitor): JsonResponse
    {
        $this->can('system.manage');
        $m = $this->find($monitor);
        $data = $this->validated($request, $m);
        // monitor dijeda tetap menyimpan status terakhirnya; diaktifkan lagi → dicek segera
        if (array_key_exists('is_active', $data) && $data['is_active'] && ! $m->is_active) {
            $data += ['next_check_at' => now(), 'fail_streak' => 0, 'status' => 'pending'];
        }
        $m->update($data);
        Audit::platform('uptime.monitor_updated', $m);
        $this->changed();

        return $this->ok(Uptime::presentMonitor($m->fresh()));
    }

    public function destroy(string $monitor): JsonResponse
    {
        $this->can('system.manage');
        $m = $this->find($monitor);
        Audit::platform('uptime.monitor_deleted', $m);
        $m->delete();
        $this->changed();

        return $this->ok(['deleted' => true]);
    }

    /** POST /api/uptime/monitors/{id}/check — cek sekarang (hasil juga dikirim realtime). */
    public function check(string $monitor, Checker $checker): JsonResponse
    {
        $this->can('dashboard.ops');
        $m = $this->find($monitor);
        $checker->run(collect([$m]));

        return $this->ok(Uptime::detail($m->fresh()));
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $this->can('system.manage');
        $data = $request->validate([
            'kind' => ['required', Rule::in(Incident::KINDS)],
            'title' => ['required', 'string', 'max:150'],
            'impact' => ['required', Rule::in(Incident::IMPACTS)],
            'status' => ['required', 'string'],
            'body' => ['required', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ], ['title.required' => 'Judul wajib diisi.', 'body.required' => 'Pesan untuk pengguna wajib diisi.', 'ends_at.after' => 'Selesai harus setelah mulai.']);
        $this->assertStatus($data['kind'], $data['status']);

        $incident = DB::transaction(function () use ($data) {
            $i = Incident::create([
                'kind' => $data['kind'], 'title' => $data['title'], 'impact' => $data['kind'] === 'maintenance' ? 'maintenance' : $data['impact'],
                'status' => $data['status'], 'starts_at' => $data['starts_at'] ?? now(), 'ends_at' => $data['ends_at'] ?? null,
                'resolved_at' => Incident::isFinal($data['status']) ? now() : null, 'created_by' => auth()->id(),
            ]);
            IncidentUpdate::create(['incident_id' => $i->id, 'status' => $data['status'], 'body' => $data['body'], 'user_id' => auth()->id(), 'created_at' => now()]);

            return $i;
        });
        Audit::platform('uptime.incident_created', $incident);
        $this->changed();

        return $this->ok(StatusPage::presentIncident($incident->load('updates')), 201);
    }

    /** POST /api/uptime/incidents/{id}/updates {status, body} — tambah pembaruan di linimasa; status akhir = selesai. */
    public function updateIncident(Request $request, string $incident): JsonResponse
    {
        $this->can('system.manage');
        $i = Incident::where('public_id', $incident)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'string'], 'body' => ['required', 'string', 'max:2000']], ['body.required' => 'Pesan pembaruan wajib diisi.']);
        $this->assertStatus($i->kind, $data['status']);
        DB::transaction(function () use ($i, $data) {
            IncidentUpdate::create(['incident_id' => $i->id, 'status' => $data['status'], 'body' => $data['body'], 'user_id' => auth()->id(), 'created_at' => now()]);
            $i->update(['status' => $data['status'], 'resolved_at' => Incident::isFinal($data['status']) ? ($i->resolved_at ?? now()) : null]);
        });
        Audit::platform('uptime.incident_updated', $i);
        $this->changed();

        return $this->ok(StatusPage::presentIncident($i->fresh('updates')));
    }

    public function destroyIncident(string $incident): JsonResponse
    {
        $this->can('system.manage');
        $i = Incident::where('public_id', $incident)->firstOrFail();
        Audit::platform('uptime.incident_deleted', $i);
        $i->delete();
        $this->changed();

        return $this->ok(['deleted' => true]);
    }

    private function validated(Request $request, ?Monitor $m = null): array
    {
        $req = $m ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:80'],
            'type' => [$req, Rule::in(Monitor::TYPES)],
            'url' => [$req, 'url:http,https', 'max:500'],
            'method' => ['sometimes', Rule::in(['GET', 'HEAD', 'POST'])],
            'expected_status' => ['sometimes', 'string', 'max:40', 'regex:/^\s*\d{3}(\s*-\s*\d{3})?(\s*,\s*\d{3}(\s*-\s*\d{3})?)*\s*$/'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:200'],
            'interval_seconds' => ['sometimes', 'integer', 'min:30', 'max:86400'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:2', 'max:30'],
            'retries' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'ssl_warn_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'is_public' => ['sometimes', 'boolean'],
            'public_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'url.url' => 'Alamat harus diawali http:// atau https://.',
            'expected_status.regex' => 'Contoh: 200-399 atau 200,301.',
            'interval_seconds.min' => 'Interval minimal 30 detik.',
        ]);
        if (isset($data['expected_status'])) {
            $data['expected_status'] = preg_replace('/\s+/', '', $data['expected_status']);
        }

        return $data;
    }

    private function assertStatus(string $kind, string $status): void
    {
        abort_unless(isset(Incident::STATUSES[$kind][$status]), 422, 'Status tidak sesuai jenis info.');
    }

    private function find(string $id): Monitor
    {
        return Monitor::where('public_id', $id)->firstOrFail();
    }

    /** Halaman status publik dimuat ulang realtime. */
    private function changed(): void
    {
        StatusPage::forget();
        rescue(fn () => broadcast(new StatusPageUpdated), report: false);
    }
}
