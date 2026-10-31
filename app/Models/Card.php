<?php

namespace App\Models;

use App\Enums\CardStatus;
use App\Enums\ChipType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Card extends Model
{
    use BelongsToTenant;

    public const PHYSICAL = 'physical';

    /** Kartu tanpa chip/batch: langsung aktif, dibagikan lewat QR di layar & tautan (lihat CreateDigitalCard). */
    public const DIGITAL = 'digital';

    /** Kartu stok boleh dibuat tanpa tenant (BSD §4.5). */
    public bool $tenantOptional = true;

    protected $guarded = ['id'];

    protected $hidden = ['activation_code_hash', 'chip_uid_enc', 'chip_uid_hash'];

    protected function casts(): array
    {
        return [
            'status' => CardStatus::class,
            'chip_type' => ChipType::class,
            'activation_locked_until' => 'datetime',
            'encoded_at' => 'datetime',
            'qc_passed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'activated_at' => 'datetime',
            'lost_at' => 'datetime',
            'revoked_at' => 'datetime',
            'replacement_requested_at' => 'datetime',
            'last_tap_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'card_token';
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class)->withoutGlobalScopes();
    }

    /** Desain tampilan (kartu digital); null = template bawaan. */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Status tujuan yang boleh dipilih staf. `active` hanya lewat aktivasi (butuh pemegang & profil),
     * jadi tidak ditawarkan untuk kartu tanpa pemegang.
     *
     * @return list<CardStatus>
     */
    public function staffTargets(): array
    {
        if ($this->isDigital()) {
            return $this->status === CardStatus::Revoked ? [] : [...$this->allowedNext(), CardStatus::Revoked];
        }

        return array_values(array_filter($this->status->allowedNext(), fn (CardStatus $s) => $s !== CardStatus::Active
            || ($this->holder_user_id && $this->profile_id)));
    }

    public function isDigital(): bool
    {
        return $this->form === self::DIGITAL;
    }

    /**
     * Transisi yang boleh dipilih pemegang. Kartu digital tidak bisa hilang/dikembalikan ke stok — cukup aktif ↔ jeda.
     *
     * @return list<CardStatus>
     */
    public function allowedNext(): array
    {
        $next = $this->status->allowedNext();

        return $this->isDigital() ? array_values(array_filter($next, fn (CardStatus $s) => in_array($s, [CardStatus::Active, CardStatus::Paused], true))) : $next;
    }

    /** Kartu tujuan `tap/@nama` (WS-07, workspace personal): kartu digital dulu, lalu kartu aktif yang paling baru dipakai. */
    public static function forHandle(User $user): ?self
    {
        return self::acrossTenants()->where('holder_user_id', $user->id)->where('status', CardStatus::Active->value)
            ->where('tenant_id', $user->personal_tenant_id)->orderByRaw("form = 'digital' desc")
            ->orderByRaw('last_tap_at desc nulls last')->orderByDesc('activated_at')->first();
    }

    public function resolverUrl(): string
    {
        return config('tapcard.resolver_url').'/c/'.$this->card_token;
    }

    public function qrUrl(): string
    {
        return $this->resolverUrl().'?src=qr';
    }

    /** Kode pendek tercetak `XXXX-XXXX`. */
    public function formattedShortCode(): string
    {
        return substr($this->short_code, 0, 4).'-'.substr($this->short_code, 4);
    }
}
