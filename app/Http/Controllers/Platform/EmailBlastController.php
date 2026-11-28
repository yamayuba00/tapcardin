<?php

namespace App\Http\Controllers\Platform;

use App\Exceptions\ApiError;
use App\Jobs\SendEmailBlast;
use App\Mail\BlastMail;
use App\Models\EmailBlast;
use App\Models\EmailBlastRecipient;
use App\Models\Plan;
use App\Support\Audit;
use App\Support\Blast\BlastAudience;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Email blast promo (platform.comms.manage) — terpisah dari pengumuman in-app. Draf → uji kirim / jadwalkan /
 * kirim sekarang → SendEmailBlast (antrean `mail`). Kirim uji hanya untuk Pemilik/Admin Platform.
 */
class EmailBlastController extends PlatformController
{
    private const TESTERS = ['platform_owner', 'platform_admin'];

    public function index(Request $request): JsonResponse
    {
        $this->can('comms.manage');

        return Table::json(Table::paginate(EmailBlast::with('plan'), $request, ['created_at' => 'created_at'], '-created_at', ['subject'], 20),
            fn (EmailBlast $b) => self::present($b), $request, ['can_test' => $this->canTest()]);
    }

    public function show(EmailBlast $blast): JsonResponse
    {
        $this->can('comms.manage');

        return $this->ok(self::present($blast->load('plan'), true), 200, ['can_test' => $this->canTest()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can('comms.manage');
        $blast = EmailBlast::create([...$this->validated($request), 'status' => 'draft', 'created_by' => auth('staff')->id()]);
        Audit::platform('email_blast.created', $blast, null, ['subject' => $blast->subject, 'audience' => $blast->audience]);

        return $this->ok(self::present($blast->fresh('plan'), true), 201);
    }

    public function update(Request $request, EmailBlast $blast): JsonResponse
    {
        $this->can('comms.manage');
        $this->editable($blast);
        $blast->update($this->validated($request));

        return $this->ok(self::present($blast->fresh('plan'), true));
    }

    /** Pratinjau email lengkap (renderer & template yang sama dengan email terkirim). */
    public function preview(Request $request): JsonResponse
    {
        $this->can('comms.manage');
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:150'], 'preheader' => ['nullable', 'string', 'max:200'], 'body' => ['nullable', 'string', 'max:100000'],
        ]);
        $mail = new BlastMail($data['subject'] ?? '', $data['preheader'] ?? null, $data['body'] ?? '', auth('staff')->user()->email);
        $html = $mail->render();
        // iframe srcdoc mewarisi CSP panel (gaya inline diblokir) → pratinjau disajikan sebagai dokumen sendiri
        $token = Str::random(40);
        Cache::put('blast-preview:'.auth('staff')->id().':'.$token, $html, now()->addMinutes(10));

        return $this->ok(['html' => $html, 'url' => route('admin.email-blasts.preview-doc', $token)]);
    }

    /** GET /email-blasts/preview/{token} — HTML pratinjau dengan CSP sendiri: tanpa skrip, gaya inline & gambar luar boleh. */
    public function previewDocument(string $token): Response
    {
        $this->can('comms.manage');
        $html = Cache::get('blast-preview:'.auth('staff')->id().':'.$token);
        abort_unless(is_string($html), 404);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox allow-popups allow-popups-to-escape-sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function audienceCount(Request $request): JsonResponse
    {
        $this->can('comms.manage');
        $data = $request->validate([
            'audience' => ['required', Rule::in(EmailBlast::AUDIENCES)],
            'plan' => ['required_if:audience,plan', 'nullable', Rule::exists('plans', 'key')],
        ]);

        return $this->ok(['count' => BlastAudience::count($data['audience'], $this->planId($data))]);
    }

    /** Gambar untuk isi email → disk `blast` (folder blast/ di S3 atau storage/app/public/blast), URL absolut. */
    public function image(Request $request): JsonResponse
    {
        $this->can('comms.manage');
        $request->validate(['image' => ['required', 'file', 'max:2048', 'mimetypes:image/jpeg,image/png,image/webp']], [
            'image.max' => 'Ukuran gambar maksimal 2 MB.', 'image.mimetypes' => 'Format gambar harus JPG, PNG, atau WebP.',
        ]);
        $file = $request->file('image');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $size = @getimagesize($file->getRealPath());
        if (! $size || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $size['mime'] !== $mime) {
            throw ValidationException::withMessages(['image' => 'Gambar tidak bisa dibaca.']);
        }
        if ($size[0] > 2400 || $size[1] > 2400) {
            throw ValidationException::withMessages(['image' => 'Ukuran gambar maksimal 2400 × 2400 piksel.']);
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $bytes = self::reencode($file->getRealPath(), $mime) ?? $file->get(); // buang metadata (EXIF/GPS)
        $path = now()->format('Y/m').'/'.Str::lower(Str::random(32)).'.'.$ext;
        Storage::disk('blast')->put($path, $bytes, ['ContentType' => $mime, 'CacheControl' => 'public, max-age=31536000, immutable']);

        $url = Storage::disk('blast')->url($path);
        if (! preg_match('~^https?://~', $url)) {
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
        }

        return $this->ok(['path' => 'blast/'.$path, 'url' => $url, 'width' => $size[0], 'height' => $size[1]], 201);
    }

    public function test(Request $request, EmailBlast $blast): JsonResponse
    {
        $this->can('comms.manage');
        if (! $this->canTest()) {
            throw new ApiError('forbidden', 'Kirim uji hanya untuk Pemilik atau Admin Platform.', 403);
        }
        $data = $request->validate(['email' => ['required', 'email:filter', 'max:190']]);
        Mail::to($data['email'])->send(new BlastMail($blast->subject, $blast->preheader, $blast->body, $data['email'], $blast->public_id, true));
        Audit::platform('email_blast.test_sent', $blast, null, ['email' => $data['email']]);

        return $this->ok(['ok' => true]);
    }

    public function schedule(Request $request, EmailBlast $blast): JsonResponse
    {
        $this->can('comms.manage');
        $this->editable($blast);
        $data = $request->validate(['scheduled_at' => ['required', 'date', 'after:now']], ['scheduled_at.after' => 'Waktu kirim harus di masa depan.']);
        $before = ['status' => $blast->status, 'scheduled_at' => self::dt($blast->scheduled_at)];
        $blast->update(['status' => 'scheduled', 'scheduled_at' => $data['scheduled_at']]);
        Audit::platform('email_blast.scheduled', $blast, $before, ['status' => 'scheduled', 'scheduled_at' => self::dt($blast->scheduled_at)]);

        return $this->ok(self::present($blast->fresh('plan'), true));
    }

    public function send(EmailBlast $blast): JsonResponse
    {
        $this->can('comms.manage');
        $this->editable($blast);
        $count = BlastAudience::count($blast->audience, $blast->plan_id);
        if ($count === 0) {
            throw new ApiError('no_recipients', 'Tidak ada penerima untuk audiens ini.', 422);
        }
        $ok = EmailBlast::whereKey($blast->id)->whereIn('status', ['draft', 'scheduled'])->update(['status' => 'sending', 'started_at' => now()]);
        if ($ok) {
            Audit::platform('email_blast.sent', $blast, ['status' => $blast->status], ['status' => 'sending', 'recipients' => $count]);
            SendEmailBlast::dispatch($blast->id);
        }

        return $this->ok(self::present($blast->fresh('plan'), true));
    }

    public function cancel(EmailBlast $blast): JsonResponse
    {
        $this->can('comms.manage');
        if (! in_array($blast->status, ['draft', 'scheduled', 'sending'], true)) {
            throw new ApiError('not_cancellable', 'Blast ini sudah selesai atau dibatalkan.', 422);
        }
        $before = $blast->status;
        DB::transaction(function () use ($blast) {
            EmailBlast::whereKey($blast->id)->update(['status' => 'cancelled', 'finished_at' => now()]);
            EmailBlastRecipient::where('blast_id', $blast->id)->where('status', 'pending')->update(['status' => 'skipped', 'error' => 'Dibatalkan']);
        });
        $blast->refresh()->refreshCounts();
        Audit::platform('email_blast.cancelled', $blast, ['status' => $before], ['status' => 'cancelled', 'sent' => $blast->sent_count]);

        return $this->ok(self::present($blast->fresh('plan'), true));
    }

    public static function present(EmailBlast $b, bool $full = false): array
    {
        return [
            'id' => $b->public_id, 'subject' => $b->subject, 'preheader' => $b->preheader, 'audience' => $b->audience,
            'plan' => $b->plan?->key, 'plan_name' => $b->plan?->name, 'status' => $b->status,
            'scheduled_at' => self::dt($b->scheduled_at), 'started_at' => self::dt($b->started_at), 'finished_at' => self::dt($b->finished_at),
            'counts' => ['total' => $b->total_count, 'sent' => $b->sent_count, 'failed' => $b->failed_count, 'skipped' => $b->skipped_count],
            'created_at' => self::dt($b->created_at),
            ...($full ? ['body' => $b->body] : []),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:100000'],
            'audience' => ['required', Rule::in(EmailBlast::AUDIENCES)],
            'plan' => ['required_if:audience,plan', 'nullable', Rule::exists('plans', 'key')],
        ]);

        return [
            'subject' => $data['subject'], 'preheader' => $data['preheader'] ?? null, 'body' => $data['body'],
            'audience' => $data['audience'], 'plan_id' => $this->planId($data),
        ];
    }

    private function planId(array $data): ?int
    {
        return $data['audience'] === 'plan' ? Plan::where('key', $data['plan'])->value('id') : null;
    }

    private function editable(EmailBlast $blast): void
    {
        if (! $blast->isEditable()) {
            throw new ApiError('not_editable', 'Blast yang sedang/sudah dikirim atau dibatalkan tidak bisa diubah.', 422);
        }
    }

    private function canTest(): bool
    {
        return in_array(auth('staff')->user()?->loadMissing('role')->role?->key, self::TESTERS, true);
    }

    private static function reencode(string $path, string $mime): ?string
    {
        $img = @imagecreatefromstring((string) file_get_contents($path));
        if (! $img) {
            return null;
        }
        ob_start();
        match ($mime) {
            'image/png' => (imagesavealpha($img, true) && imagepng($img, null, 8)),
            'image/webp' => (imagesavealpha($img, true) && imagewebp($img, null, 85)),
            default => imagejpeg($img, null, 85),
        };

        return ob_get_clean() ?: null;
    }
}
