<?php

namespace App\Http\Controllers\Platform;

use App\Models\Voucher;
use App\Support\Audit;
use App\Support\Http\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Voucher diskon checkout paket (platform.vouchers.manage): persen/nominal, kuota, masa berlaku, paket tertentu. */
class VoucherController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $this->can('vouchers.manage');
        $q = Voucher::query()->when($request->query('active') === '1', fn ($w) => $w->where('is_active', true)->where(fn ($x) => $x->whereNull('ends_at')->orWhere('ends_at', '>', now())));

        return Table::json(Table::paginate($q, $request, ['code' => 'code', 'created_at' => 'created_at', 'ends_at' => 'ends_at', 'redeemed' => 'redeemed_count'], '-created_at', ['code', 'description'], 25),
            fn (Voucher $v) => self::present($v), $request);
    }

    public function store(Request $request): JsonResponse
    {
        $this->can('vouchers.manage');
        $v = Voucher::create($this->validated($request) + ['created_by' => auth()->id()]);
        Audit::platform('voucher.created', $v, null, self::present($v));

        return $this->ok(self::present($v), 201);
    }

    public function update(Request $request, Voucher $voucher): JsonResponse
    {
        $this->can('vouchers.manage');
        $before = self::present($voucher);
        $voucher->update($this->validated($request, $voucher));
        Audit::platform('voucher.updated', $voucher, $before, self::present($voucher));

        return $this->ok(self::present($voucher));
    }

    private function validated(Request $request, ?Voucher $v = null): array
    {
        $s = $v ? 'sometimes' : 'required';
        $data = $request->validate([
            'code' => [$s, 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('vouchers', 'code')->ignore($v?->id)],
            'description' => ['nullable', 'string', 'max:160'],
            'kind' => [$s, Rule::in(['percent', 'amount'])],
            'value' => [$s, 'integer', 'min:1', 'max:100000000'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'plan_keys' => ['sometimes', 'array'],
            'applies_to' => ['sometimes', Rule::in(['plan', 'order', 'any'])],
            'plan_keys.*' => [Rule::exists('plans', 'key')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'Kode hanya huruf, angka, - dan _.']);
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }
        $kind = $data['kind'] ?? $v?->kind;
        if ($kind === 'percent' && ($data['value'] ?? $v?->value) > 100) {
            throw ValidationException::withMessages(['value' => 'Diskon persen maksimal 100.']);
        }

        return $data;
    }

    public static function present(Voucher $v): array
    {
        return [
            'id' => $v->public_id, 'code' => $v->code, 'description' => $v->description, 'kind' => $v->kind, 'value' => $v->value,
            'max_redemptions' => $v->max_redemptions, 'redeemed' => $v->redeemed_count, 'plan_keys' => $v->plan_keys ?? [], 'applies_to' => $v->applies_to,
            'starts_at' => self::dt($v->starts_at), 'ends_at' => self::dt($v->ends_at), 'is_active' => $v->is_active,
        ];
    }
}
