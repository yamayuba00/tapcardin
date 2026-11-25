<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Notifikasi in-app; dibaca SSE dengan kursor id (TRD §9). */
class Notification extends Model
{
    public const UPDATED_AT = null;

    /** Tipe yang `data.id`-nya berisi public_id lead (koneksi). */
    public const LEAD_TYPES = ['lead_new', 'lead_assigned', 'reminder_due'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }

    /**
     * Buang notifikasi yang menunjuk lead yang sudah tidak ada (dihapus, atau milik workspace lain):
     * tautannya pasti 404. Lead yang digabung tetap ada → tetap tampil (detail mengarahkan ke tujuan gabungan).
     */
    public function scopeLiveLead(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w
            ->whereNotIn('type', self::LEAD_TYPES)
            ->orWhereRaw("data->>'id' IS NULL")
            ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('connections')
                ->whereRaw("connections.public_id = notifications.data->>'id'")
                ->whereRaw('connections.tenant_id = notifications.tenant_id')
                ->whereNull('connections.deleted_at')));
    }

    /** Hapus notifikasi lead dalam satu workspace (dipanggil saat lead dihapus). */
    public static function forgetLead(int $tenantId, string $leadPublicId): int
    {
        return self::where('tenant_id', $tenantId)->whereIn('type', self::LEAD_TYPES)
            ->whereRaw("data->>'id' = ?", [$leadPublicId])->delete();
    }
}
