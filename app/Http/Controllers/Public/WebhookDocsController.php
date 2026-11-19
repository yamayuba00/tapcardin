<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Webhooks\WebhookEvents;
use Illuminate\Http\JsonResponse;

/** wbh.{base}/api/catalog — katalog event webhook publik untuk dokumentasi developer (contoh workspace fiktif). */
class WebhookDocsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $sample = new Tenant(['name' => 'Maju Jaya', 'slug' => 'majujaya']);
        $sample->public_id = '01JD0WORKSPACE000000000000';

        return response()->json(['data' => WebhookEvents::catalog($sample)])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
