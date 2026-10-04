<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteDailyRotation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['state' => 'array'];
    }
}
