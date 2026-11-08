<?php

namespace App\Http\Controllers;

use App\Support\Uptime\StatusPage;
use Illuminate\Http\JsonResponse;

/** status.{base} — data halaman status publik (tanpa login). */
class StatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => StatusPage::summary()])->header('Cache-Control', 'public, max-age=15');
    }
}
