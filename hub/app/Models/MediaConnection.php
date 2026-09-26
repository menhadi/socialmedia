<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaConnection extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'enabled' => 'boolean'];
    }
}
