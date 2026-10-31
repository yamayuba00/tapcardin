<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id'];
}
