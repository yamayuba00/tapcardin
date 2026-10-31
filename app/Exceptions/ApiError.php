<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** BSD §8.1 — error dengan kode stabil: `{"error":{"code","message",...}}`. */
class ApiError extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function planLimit(string $message, string $limit): self
    {
        return new self('plan_limit_reached', $message, 402, ['limit' => $limit]);
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return false;
        }

        return response()->json(['error' => ['code' => $this->errorCode, 'message' => $this->getMessage(), ...$this->extra]], $this->status);
    }
}
