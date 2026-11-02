<?php

namespace App\Models;

use App\Enums\LeadSource;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Lead / koneksi (BSD §4.6). */
class Connection extends Model
{
    use BelongsToTenant, HasPublicId, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'custom' => 'array',
            'expected_close_at' => 'date',
            'closed_at' => 'datetime',
            'first_activity_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'next_followup_at' => 'datetime',
            'consent_at' => 'datetime',
            'scanned_at' => 'datetime',
        ];
    }

    /** Kolom text[] PostgreSQL ↔ array PHP. */
    public function getTagsAttribute(?string $value): array
    {
        if ($value === null || $value === '{}') {
            return [];
        }

        return array_map(
            fn ($t) => str_replace(['\\"', '\\\\'], ['"', '\\'], $t),
            str_getcsv(substr($value, 1, -1), ',', '"', '\\'),
        );
    }

    public function setTagsAttribute(array $tags): void
    {
        $escaped = array_map(
            fn ($t) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $t).'"',
            array_values(array_unique(array_filter($tags, 'strlen'))),
        );
        $this->attributes['tags'] = '{'.implode(',', $escaped).'}';
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(LeadStage::class, 'stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function counterpartCard(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'counterpart_card_id')->withoutGlobalScopes();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(LeadReminder::class)->orderBy('due_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * Paket dengan batas koneksi (Personal Free: 100) tetap menyimpan semuanya — tidak ada yang ditolak
     * atau dihapus. Yang bisa dilihat hanya N koneksi pertama (terlama); sisanya terbuka saat upgrade.
     */
    public function scopeWithinPlan(Builder $q, ?int $limit): Builder
    {
        return $limit === null ? $q : $q->whereIn('connections.id', self::query()->whereNull('merged_into_id')
            ->orderBy('created_at')->orderBy('id')->limit($limit)->select('id'));
    }

    /**
     * TRD §5.3 — cakupan izin pada query daftar.
     *
     * @param  array{perms: array<string,true>, managed_team_ids: int[]}  $ctx
     */
    public function scopeVisibleTo(Builder $q, User $u, array $ctx): Builder
    {
        $p = $ctx['perms'];

        return match (true) {
            isset($p['leads.view.all']) => $q,
            isset($p['leads.view.team']) => $q->where(fn ($w) => $w
                ->whereIn('team_id', $ctx['managed_team_ids'])
                ->orWhere('assignee_user_id', $u->id)->orWhere('captured_by_user_id', $u->id)),
            isset($p['leads.view.own']) => $q->where(fn ($w) => $w
                ->where('assignee_user_id', $u->id)->orWhere('captured_by_user_id', $u->id)),
            default => $q->whereRaw('false'),
        };
    }
}
