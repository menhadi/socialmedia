<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteDailyBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payloads' => 'array', 'posts' => 'array'];
    }
}
