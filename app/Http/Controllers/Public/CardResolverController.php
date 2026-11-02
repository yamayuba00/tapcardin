<?php

namespace App\Http\Controllers\Public;

use App\Actions\Cards\DynamicQr;
use App\Actions\Cards\ResolveCard;
use App\Actions\Cards\ShareQr;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SpaController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolver kartu (PRD §8). Tap selalu dicatat tepat sekali per pemuatan:
 *   GET /c/{token}      — URL di chip/QR: shell SPA dengan data profil sudah disisipkan (tanpa panggilan API kedua)
 *   GET /api/c/{token}  — dipakai SPA saat navigasi di dalam aplikasi (mis. dari /k)
 */
class CardResolverController extends Controller
{
    public function __invoke(Request $request, string $token, ResolveCard $resolve): JsonResponse
    {
        $r = $resolve->handle($request, $token);

        return $this->headers(response()->json(['data' => $r['data']], $r['status']), $r['indexable']);
    }

    public function shell(Request $request, string $token, ResolveCard $resolve, SpaController $spa): Response
    {
        $r = $resolve->handle($request, $token);

        return $this->headers($spa->render(['initial' => ['card' => ['token' => $token] + $r['data']]], $r['meta'], $r['status']), $r['indexable']);
    }

    /** GET /s/{code} — QR kartu digital berjangka (ShareQr): redirect ke profil, atau "QR sudah kedaluwarsa". */
    public function shared(string $code, SpaController $spa): Response
    {
        $url = ShareQr::redeem($code);

        return $url ? $this->headers(redirect()->away($url), false) : $this->expired($spa);
    }

    /** GET /q/{token} — QR dinamis (AFD F-11): redirect sekali pakai, atau P-07 "QR sudah kedaluwarsa". */
    public function dynamic(string $token, SpaController $spa): Response
    {
        $url = DynamicQr::redeem($token);
        if ($url) {
            return $this->headers(redirect()->away($url), false);
        }

        return $this->expired($spa);
    }

    private function expired(SpaController $spa): Response
    {
        return $this->headers($spa->render(['initial' => ['qr' => ['state' => 'expired']]], ['title' => 'QR sudah kedaluwarsa · TapCard', 'description' => 'Minta pemilik kartu menampilkan QR baru.', 'image' => null, 'theme' => 'dark', 'lang' => 'id'], 410), false);
    }

    private function headers(Response $response, bool $indexable): Response
    {
        // Setiap tap harus sampai ke server agar tercatat (TRD §6.2 langkah 6).
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', $indexable ? 'all' : 'noindex, nofollow');

        return $response;
    }
}
