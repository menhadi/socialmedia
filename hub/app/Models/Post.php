<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Post extends Model
{
    public const CHANNELS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'x' => 'X', 'youtube' => 'YouTube', 'whatsapp' => 'WhatsApp Business', 'other' => 'Other'];

    protected $fillable = ['title', 'channel', 'body', 'source_url'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    public function publishingFingerprint(): string
    {
        return hash('sha256', json_encode([$this->id, $this->brand_id, $this->title, $this->channel, $this->body, $this->source_url, $this->reviewed_at?->toISOString()], JSON_THROW_ON_ERROR));
    }

    public function assertEditable(): void
    {
        if (! in_array($this->status, ['draft', 'reviewed'], true) || $this->publications()->whereIn('status', ['publishing', 'published', 'uncertain'])->exists()) {
            throw ValidationException::withMessages(['post' => 'This post has been submitted to Facebook and cannot be edited or submitted again. Check its publishing history below.']);
        }
    }
}
