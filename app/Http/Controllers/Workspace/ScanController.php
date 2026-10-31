<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Cards\CardView;
use App\Actions\Leads\CreateConnection;
use App\Enums\LeadSource;
use App\Enums\TapSource;
use App\Exceptions\ApiError;
use App\Http\Resources\Present;
use App\Jobs\RecordTap;
use App\Jobs\TapContext;
use App\Models\Card;
use App\Models\Connection;
use App\Models\Notification;
use App\Models\ProductEvent;
use App\Models\TenantMember;
use App\Support\Crockford;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * U-10 / AFD F-09 — Mode Reader: pemilik membaca kartu orang lain lewat NFC, kamera QR, atau kode pendek.
 *  - kartu TapCard aktif → koneksi `scan_tapcard` diisi dari bio kartu itu + pemiliknya diberi tahu
 *    "{Nama} menyimpan Anda" dan bisa **Simpan balik** sekali ketuk;
 *  - URL/teks lain (kartu NFC/QR merek lain) → koneksi `scan_external` dengan nama yang diketik pengguna.
 */
class ScanController extends WorkspaceController
{
    public function store(Request $request, CreateConnection $create): JsonResponse
    {
        $this->access()->authorize('leads.edit.*');
        $data = $request->validate([
            'value' => ['required', 'string', 'max:500'], // URL dari NFC/QR, kode pendek, atau token
            'name' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:30'],
        ]);
        $user = $this->user();
        $refs = ['event_id' => $this->activeEventId()];
        $view = ($token = self::tokenFrom($data['value'])) ? CardView::cached($token) : null;

        if ($view) {
            if ($view->holderUserId === $user->id) {
                throw new ApiError('own_card', 'Ini kartu Anda sendiri.');
            }
            if ($view->status !== 'active' || ! $view->profile || $view->tenantSuspended) {
                throw new ApiError('card_inactive', 'Kartu ini sedang tidak aktif, jadi datanya tidak bisa disimpan.');
            }
            $p = $view->profile;
            ['connection' => $c, 'duplicate' => $dup] = $create->handle([
                'name' => $p['display_name'], 'company' => $p['company'], 'job_title' => $p['title'],
                'email' => $view->primary('email')['value'] ?? null,
                'phone' => $view->primary('phone', 'whatsapp')['value'] ?? null,
                'note' => $data['note'] ?? null, 'tags' => $data['tags'] ?? [],
                'external_url' => config('tapcard.resolver_url').'/c/'.$view->token,
            ], LeadSource::ScanTapcard, $user->id, $refs + [
                'counterpart_user_id' => $view->holderUserId, 'counterpart_card_id' => $view->cardId,
            ]);
            if ($view->tenantId) {
                RecordTap::dispatch($view->tenantId, $view->cardId, TapContext::fromRequest($request, source: TapSource::App));
            }
            if (! $dup && $view->holderUserId) {
                Notify::user($view->holderUserId, $view->tenantId, 'saved_back', [
                    'name' => $user->name, 'card' => $this->myCardToken(), 'by' => $user->public_id,
                ]);
            }
            ProductEvent::record('scan_completed', $this->tenant()->id, $user->id, ['kind' => 'tapcard']);

            return response()->json(['data' => [
                'kind' => 'tapcard', 'duplicate' => $dup, 'connection' => Present::connection($c->load('stage')),
            ]], $dup ? 200 : 201);
        }

        if ($token) {
            throw new ApiError('card_not_found', 'Kartu TapCard ini tidak dikenal. Periksa kodenya lagi.', 404);
        }
        // Bukan TapCard: simpan apa adanya, nama wajib diisi pengguna (AFD F-09 cabang EXT)
        if (empty($data['name'])) {
            throw new ApiError('name_required', 'Ini bukan kartu TapCard. Isi nama orangnya untuk menyimpan sebagai koneksi.', 422, ['external' => true]);
        }
        $url = filter_var($data['value'], FILTER_VALIDATE_URL) ? $data['value'] : null;
        ['connection' => $c, 'duplicate' => $dup] = $create->handle([
            'name' => $data['name'], 'external_url' => $url,
            'note' => trim(($url ? '' : "Hasil scan: {$data['value']}\n").($data['note'] ?? '')) ?: null,
            'tags' => $data['tags'] ?? [],
        ], LeadSource::ScanExternal, $user->id, $refs);
        ProductEvent::record('scan_completed', $this->tenant()->id, $user->id, ['kind' => 'external']);

        return response()->json(['data' => [
            'kind' => 'external', 'duplicate' => $dup, 'connection' => Present::connection($c->load('stage')),
        ]], 201);
    }

    /** GET /api/scans/inbound — orang yang menyimpan saya lewat Mode Reader (30 hari), untuk "Simpan balik". */
    public function inbound(): JsonResponse
    {
        $rows = Notification::where('user_id', $this->user()->id)->where('type', 'saved_back')
            ->where('created_at', '>=', now()->subDays(30))->latest()->limit(20)->get();
        $savedBack = Connection::whereNull('merged_into_id')->where('captured_by_user_id', $this->user()->id)
            ->whereIn('counterpart_card_id', Card::acrossTenants()->whereIn('card_token', $rows->pluck('data.card')->filter())->select('id'))
            ->with('counterpartCard')->get()->pluck('counterpartCard.card_token')->filter()->all();

        return response()->json(['data' => $rows->map(fn ($n) => [
            'name' => $n->data['name'] ?? '—', 'card' => $n->data['card'] ?? null,
            'at' => $n->created_at->toIso8601String(),
            'saved_back' => in_array($n->data['card'] ?? null, $savedBack, true),
        ])->unique('card')->values()]);
    }

    /** Token kartu dari URL TapCard (`/c/{token}`), token polos, atau kode pendek (`TC·7K2M-9QXP`). */
    public static function tokenFrom(string $value): ?string
    {
        $v = trim($value);
        $host = parse_url(config('tapcard.resolver_url'), PHP_URL_HOST);
        if (preg_match('#^https?://([^/:]+)(?::\d+)?/c/([0-9A-Za-z]{10})(?:[/?\#]|$)#', $v, $m)) {
            return strcasecmp($m[1], $host) === 0 ? $m[2] : null;
        }
        if (preg_match('/^[0-9A-Za-z]{10}$/', $v) && preg_match('/[a-z]/', $v)) {
            return $v; // token base62 (punya huruf kecil, jadi tidak tertukar dengan kode pendek)
        }
        $code = Crockford::normalize($v);
        if (strlen($code) === 8) {
            return Card::acrossTenants()->where('short_code', $code)->value('card_token');
        }

        return null;
    }

    private function activeEventId(): ?int
    {
        $m = TenantMember::where('user_id', $this->user()->id)->where('status', 'active')->first();

        return $m?->active_event_until?->isFuture() ? $m->active_event_id : null;
    }

    /** Kartu aktif pemindai (untuk tombol Simpan balik di pihak lain). */
    private function myCardToken(): ?string
    {
        return Card::acrossTenants()->where('holder_user_id', $this->user()->id)->where('status', 'active')
            ->orderBy('activated_at')->value('card_token');
    }
}
