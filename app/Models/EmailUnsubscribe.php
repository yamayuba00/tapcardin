<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Alamat yang berhenti berlangganan email promo (huruf kecil). Email transaksional tetap terkirim. */
class EmailUnsubscribe extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public static function has(string $email): bool
    {
        return static::where('email', mb_strtolower(trim($email)))->exists();
    }
}
