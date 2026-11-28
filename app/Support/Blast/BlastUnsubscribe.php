<?php

namespace App\Support\Blast;

use Illuminate\Support\Facades\URL;

/**
 * Tautan berhenti berlangganan per penerima: URL bertanda tangan (tanpa kedaluwarsa) `/unsubscribe` di domain utama.
 * GET = halaman konfirmasi (SPA), POST = berhenti berlangganan — juga dipakai sebagai one-click POST dari header
 * List-Unsubscribe (RFC 8058, tanpa CSRF). Di luar /api agar penyedia email tidak butuh X-Tap-Key.
 */
final class BlastUnsubscribe
{
    public static function url(string $email, ?string $blastId = null): string
    {
        return URL::signedRoute('blast.unsubscribe', array_filter(['e' => mb_strtolower(trim($email)), 'b' => $blastId]));
    }
}
