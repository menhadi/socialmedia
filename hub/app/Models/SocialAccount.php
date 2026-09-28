<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasFactory;

    protected $hidden = ['access_token', 'oauth_credentials'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'oauth_credentials' => 'encrypted:array', 'token_expires_at' => 'datetime', 'verified_at' => 'datetime', 'settings' => 'array'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
