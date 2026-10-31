<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** BSD §4.10 audit_logs — semua aksi admin & perubahan sensitif. */
final class Audit
{
    public static function platform(string $action, Model $subject, ?array $before = null, ?array $after = null): void
    {
        self::write('platform', $subject->tenant_id ?? null, $action, $subject, $before, $after);
    }

    public static function tenant(int $tenantId, string $action, Model $subject, ?array $before = null, ?array $after = null): void
    {
        self::write('tenant', $tenantId, $action, $subject, $before, $after);
    }

    private static function write(string $scope, ?int $tenantId, string $action, Model $subject, ?array $before, ?array $after): void
    {
        $request = app()->runningInConsole() ? null : request();
        AuditLog::create([
            'scope' => $scope,
            'tenant_id' => $tenantId,
            'actor_user_id' => auth()->id(),
            'impersonation_id' => $request?->hasSession() ? $request->session()->get('impersonation_id') : null,
            'action' => $action,
            'subject_type' => class_basename($subject),
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_hash' => $request ? Hasher::ip($request->ip()) : null,
        ]);
    }
}
