<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EmailBlast;
use App\Models\EmailUnsubscribe;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /unsubscribe?e=…&b=…&signature=… (domain utama, URL bertanda tangan, tanpa CSRF, dibatasi throttle).
 * Dipanggil tombol di halaman konfirmasi (GET = SPA) dan one-click dari penyedia email (List-Unsubscribe-Post).
 */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $email = mb_strtolower(trim((string) $request->query('e')));
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422, 'Alamat email tidak valid.');

        $oneClick = $request->input('List-Unsubscribe') === 'One-Click';
        $blast = $request->query('b') ? EmailBlast::where('public_id', (string) $request->query('b'))->value('subject') : null;
        EmailUnsubscribe::firstOrCreate(['email' => $email], [
            'reason' => mb_substr(($oneClick ? 'one-click' : 'halaman').($blast ? ' · '.$blast : ''), 0, 120),
        ]);

        return $request->expectsJson()
            ? response()->json(['data' => ['ok' => true, 'email' => $email]])
            : response('Anda sudah berhenti berlangganan email promo TapCard.', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
