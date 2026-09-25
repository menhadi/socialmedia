<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'website', 'description', 'audience', 'tone', 'language', 'instructions', 'ai_connection_id'];

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
