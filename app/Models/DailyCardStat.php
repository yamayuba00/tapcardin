<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DailyCardStat extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
