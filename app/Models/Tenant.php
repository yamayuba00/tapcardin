<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Enums\TenantType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasPublicId, SoftDeletes;

    protected $guarded = ['id'];

    public const DEFAULT_SETTINGS = [
        'lead_ownership_on_leave' => 'reassign',
        'auto_followup_hours' => 24,
        'notify_manager_on_new_lead' => true,
        'public_page_enabled' => false,
        'directory_enabled' => false,
        'leaderboard_enabled' => true,
        'viewer_can_export' => false,
        'require_impersonation_notice' => false,
        'lost_reasons' => ['Harga', 'Waktu belum tepat', 'Pilih kompetitor', 'Tidak merespons', 'Lainnya'],
    ];

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'status' => TenantStatus::class,
            'brand' => 'array',
            'locked_fields' => 'array',
            'settings' => 'array',
            'billing_profile' => 'array',
            'limit_overrides' => 'array',
            'price_overrides' => 'array',
            'suspended_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isPersonal(): bool
    {
        return $this->type === TenantType::Personal;
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::Suspended;
    }

    public function setting(string $key): mixed
    {
        return data_get($this->settings, $key, self::DEFAULT_SETTINGS[$key] ?? null);
    }

    /** Label kontak: "Koneksi" di Personal, "Lead" di Perusahaan (PRD §3.1). */
    public function contactLabel(): string
    {
        return $this->isPersonal() ? 'Koneksi' : 'Lead';
    }
}
