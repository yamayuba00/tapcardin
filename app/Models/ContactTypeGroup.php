<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grup jenis kontak (Kontak utama, Sosial media, Pembayaran, …) — data referensi global. */
class ContactTypeGroup extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    public function types(): HasMany
    {
        return $this->hasMany(ContactType::class, 'group_id')->orderBy('sort');
    }
}
