<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGeneration extends Model
{
    use HasFactory;

    public const TASKS = ['draft' => 'Create a post', 'rewrite' => 'Rewrite text', 'translate' => 'Translate text', 'ideas' => 'Suggest content ideas'];

    protected function casts(): array
    {
        return ['usage_reported' => 'boolean', 'cost_micros' => 'integer'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
