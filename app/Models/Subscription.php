<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Langganan paket satu workspace (BSD `subscriptions`). */
class Subscription extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime', 'current_period_start' => 'datetime', 'current_period_end' => 'datetime',
            'grace_until' => 'datetime', 'cancelled_at' => 'datetime', 'cancel_at_period_end' => 'boolean',
            'reminders_sent' => 'array',
        ];
    }

    public const STATUS_LABELS = [
        'trialing' => 'Uji coba', 'active' => 'Aktif', 'past_due' => 'Masa tenggang', 'expired' => 'Masa aktif habis', 'cancelled' => 'Berhenti',
    ];

    /**
     * Masa aktif satu-satunya sumber tanggal (panel & ringkasan perusahaan): mulai → akhir periode (uji coba: s/d trial_ends_at),
     * status, batas tenggang, dan sisa hari (dibulatkan ke atas; 0 bila sudah lewat).
     *
     * @return array{status:string, label:string, start:?string, end:?string, grace_until:?string, days_left:int}
     */
    public function activePeriod(): array
    {
        $end = $this->status === 'trialing' ? ($this->trial_ends_at ?? $this->current_period_end) : $this->current_period_end;
        $until = $this->status === 'past_due' ? ($this->grace_until ?? $end) : $end;

        return [
            'status' => $this->status,
            'label' => self::STATUS_LABELS[$this->status] ?? $this->status,
            'start' => $this->current_period_start?->toIso8601String(),
            'end' => $end?->toIso8601String(),
            'grace_until' => $this->grace_until?->toIso8601String(),
            'days_left' => $until && in_array($this->status, ['trialing', 'active', 'past_due'], true) && $until->isFuture()
                ? (int) ceil(now()->diffInSeconds($until) / 86400) : 0,
        ];
    }

    /** Pengingat masa aktif sudah terkirim untuk periode ini? Kunci = tanggal akhir periode + tahap (h30/h7/h1/trial). */
    public function reminded(string $stage): bool
    {
        return in_array($this->reminderKey($stage), $this->reminders_sent ?? [], true);
    }

    public function markReminded(string $stage): void
    {
        $sent = array_values(array_slice(array_unique([...($this->reminders_sent ?? []), $this->reminderKey($stage)]), -12));
        $this->forceFill(['reminders_sent' => $sent])->save();
    }

    private function reminderKey(string $stage): string
    {
        return $this->current_period_end?->format('Y-m-d').":$stage";
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
