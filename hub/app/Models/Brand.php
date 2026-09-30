<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Brand extends Model
{
    use HasFactory;

    protected $hidden = ['intake_token_hash'];

    protected $fillable = ['name', 'website', 'description', 'audience', 'tone', 'language', 'instructions', 'ai_connection_id', 'image_connection_id', 'video_connection_id', 'pyp_only'];

    protected function casts(): array
    {
        return ['pyp_only' => 'boolean'];
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function aiConnection()
    {
        return $this->belongsTo(AiConnection::class);
    }

    public function healthMonitor(): HasOne
    {
        return $this->hasOne(HealthMonitor::class);
    }
}
