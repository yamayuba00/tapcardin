<?php

namespace App\Http\Controllers\Platform;

use App\Authz\PlatformAccess;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

abstract class PlatformController extends Controller
{
    protected function can(string $permission): void
    {
        app(PlatformAccess::class)->authorize($permission);
    }

    protected function ok(mixed $data = ['ok' => true], int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(array_filter(['data' => $data, 'meta' => $meta ?: null], fn ($v) => $v !== null), $status);
    }

    /** Paginasi halaman: `{data: [...], meta: {page, last_page, total}}`. */
    protected function paged(LengthAwarePaginator $p, callable $map): JsonResponse
    {
        return response()->json([
            'data' => collect($p->items())->map($map)->values(),
            'meta' => ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ]);
    }

    protected static function dt($value): ?string
    {
        return $value?->toIso8601String();
    }
}
