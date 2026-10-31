<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Tabel server-side seragam untuk semua daftar di dashboard: pencarian `q`, urutan `sort`
 * (`kolom` / `-kolom`, hanya dari daftar putih), `per_page` 10–100, dan `page`.
 * Respons: `{data: [...], meta: {page, last_page, total, per_page, sort}}`.
 */
final class Table
{
    /**
     * @param  array<string, string|Closure(Builder, string): void>  $sorts  kunci publik → kolom, closure, atau "Kelas::metode"
     * @param  list<string>  $search  kolom untuk `ilike %q%`
     */
    public static function paginate(Builder $q, Request $r, array $sorts, string $default, array $search = [], int $perPage = 25): LengthAwarePaginator
    {
        if ($search && ($s = trim((string) $r->query('q'))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s).'%';
            $q->where(fn ($w) => collect($search)->each(fn ($col) => $w->orWhere($col, 'ilike', $like)));
        }

        [$key, $dir] = self::sort($r, $sorts, $default);
        $col = $sorts[$key];
        $col instanceof Closure || str_contains($col, '::') ? call_user_func($col, $q, $dir) : $q->orderByRaw("$col $dir nulls last");
        $q->orderBy($q->getModel()->getQualifiedKeyName(), $dir); // pemutus seri → urutan halaman stabil

        $per = min(max((int) $r->query('per_page', $perPage), 10), 100);

        return $q->paginate($per)->withQueryString();
    }

    /** @return array{0:string, 1:'asc'|'desc'} */
    public static function sort(Request $r, array $sorts, string $default): array
    {
        $raw = (string) $r->query('sort', $default);
        $key = ltrim($raw, '-');
        if (! isset($sorts[$key])) {
            [$raw, $key] = [$default, ltrim($default, '-')];
        }

        return [$key, str_starts_with($raw, '-') ? 'desc' : 'asc'];
    }

    public static function json(LengthAwarePaginator $p, callable $map, Request $r, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => collect($p->items())->map($map)->values(),
            'meta' => [
                'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total(),
                'per_page' => $p->perPage(), 'sort' => $r->query('sort'),
            ] + $meta,
        ]);
    }
}
