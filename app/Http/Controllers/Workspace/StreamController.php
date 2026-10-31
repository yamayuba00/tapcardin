<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Notification;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /stream — Server-Sent Events membaca tabel notifications dengan kursor id (TRD §9).
 * Koneksi ditahan ≤ 25 detik, cek tiap 3 detik; browser reconnect otomatis dengan Last-Event-ID.
 */
class StreamController extends WorkspaceController
{
    public function __invoke(Request $request, CurrentTenant $tenant): StreamedResponse
    {
        $uid = $request->user()->id;
        $tid = $tenant->id();
        $last = (int) ($request->header('Last-Event-ID') ?? $request->query('after', 0));
        if ($last === 0) {
            // koneksi pertama: mulai dari notifikasi terbaru agar yang lama tidak dikirim ulang sebagai toast
            $last = (int) Notification::where('user_id', $uid)->max('id');
        }

        // Lepas kunci sesi agar request lain dari pengguna yang sama tidak menunggu.
        $request->session()->save();

        // `php artisan serve` (cli-server) hanya melayani SATU request sekaligus: menahan koneksi 25 detik
        // akan membuat semua request lain antre. Di sana SSE dijawab langsung dan browser menyambung ulang
        // tiap 10 detik (efektif polling). Di PHP-FPM/produksi koneksi tetap ditahan ≤ 25 detik (TRD §9).
        $singleThreaded = PHP_SAPI === 'cli-server';
        $hold = app()->runningUnitTests() || $singleThreaded ? 0 : 25;
        $retry = $singleThreaded ? 10000 : 3000;

        return response()->stream(function () use ($uid, $tid, $last, $hold, $retry) {
            echo "retry: $retry\n\n";
            $until = time() + $hold;
            do {
                $rows = Notification::query()
                    ->where('user_id', $uid)->where('id', '>', $last)
                    ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tid))
                    ->orderBy('id')->limit(50)->get(['id', 'type', 'data', 'created_at']);
                foreach ($rows as $n) {
                    $last = $n->id;
                    echo "id: {$n->id}\nevent: {$n->type}\ndata: ".json_encode($n->data + ['at' => $n->created_at->toIso8601String()])."\n\n";
                }
                echo ": ping\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
                if (time() >= $until || connection_aborted()) {
                    break;
                }
                sleep(3);
            } while (true);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
