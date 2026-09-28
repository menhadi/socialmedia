<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaGeneration extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['api_key', 'operation'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'started_at' => 'datetime', 'checked_at' => 'datetime', 'automation_context' => 'array'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MediaConnection::class, 'media_connection_id');
    }
}
