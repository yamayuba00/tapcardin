<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Info gangguan / maintenance untuk halaman status publik, dengan linimasa pembaruan. */
class Incident extends Model
{
    use HasPublicId;

    public const KINDS = ['incident', 'maintenance'];

    public const IMPACTS = ['minor', 'major', 'maintenance'];

    /** Status per jenis; status terakhir menandai selesai. */
    public const STATUSES = [
        'incident' => ['investigating' => 'Diselidiki', 'identified' => 'Penyebab ditemukan', 'monitoring' => 'Dipantau', 'resolved' => 'Selesai'],
        'maintenance' => ['scheduled' => 'Dijadwalkan', 'in_progress' => 'Sedang berlangsung', 'completed' => 'Selesai'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, ['resolved', 'completed'], true);
    }
}
