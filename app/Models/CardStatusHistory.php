<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardStatusHistory extends Model
{
    protected $table = 'card_status_history';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
