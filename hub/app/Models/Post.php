<?php

namespace App\Models;

use App\Services\Research\ContentVisual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class Post extends Model
{
    public const CHANNELS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'x' => 'X', 'youtube' => 'YouTube', 'whatsapp' => 'WhatsApp Business', 'other' => 'Other'];

    protected $fillable = ['title', 'channel', 'body', 'source_url', 'visual'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'archived_at' => 'datetime', 'visual' => 'array', 'card_images' => 'array', 'card_sources' => 'array'];
    }

    public function scopeForSocialAccount(Builder $query, SocialAccount $account): Builder
    {
        return $query->where('brand_id', $account->brand_id)->where('channel', $account->provider)
            ->where(fn ($query) => $query
                ->whereHas('publications', fn ($query) => $query->where('social_account_id', $account->id))
                ->orWhereHas('schedules', fn ($query) => $query->where('social_account_id', $account->id))
                ->orWhere(fn ($query) => $query->doesntHave('publications')->doesntHave('schedules')));
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(PostSchedule::class);
    }

    public function trendRun(): HasOne
    {
        return $this->hasOne(TrendRun::class);
    }

    public function publishingFingerprint(): string
    {
        $parts = [$this->id, $this->brand_id, $this->title, $this->channel, $this->body, $this->source_url, $this->reviewed_at?->toISOString(), $this->image_hash];
        if ($this->video_hash) {
            $parts[] = $this->video_hash;
        }
        if ($this->visual) {
            $parts[] = $this->visual;
        }
        if ($this->card_images) {
            $parts[] = $this->card_images;
        }
        if ($this->card_sources) {
            $parts[] = $this->card_sources;
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    public function assertEditable(): void
    {
        if ($this->archived_at) {
            throw ValidationException::withMessages(['post' => 'Restore this archived post before editing or scheduling.']);
        }
        if ($this->schedules()->whereIn('status', ['running', 'processing', 'uncertain'])->exists()) {
            throw ValidationException::withMessages(['post' => 'A scheduled submission is running. Check the schedule before editing.']);
        }
        if (! in_array($this->status, ['draft', 'reviewed'], true) || $this->publications()->whereIn('status', ['publishing', 'published', 'uncertain'])->exists()) {
            throw ValidationException::withMessages(['post' => 'This post has been submitted to the platform and cannot be edited or submitted again. Check its publishing history below.']);
        }
    }

    public function assertContentPolicy(): void
    {
        if (($this->visual['type'] ?? '') === 'collection' && count($this->card_images ?? []) !== count($this->visual['cards'])) {
            throw ValidationException::withMessages(['visual' => 'Generate all cards and review the complete set before publishing.']);
        }
        if ($this->brand()->value('pyp_only')) {
            app(ContentVisual::class)->assertPreviousYearQuestion($this->visual, $this->source_url);
            if (! $this->image_hash) {
                throw ValidationException::withMessages(['visual' => 'Create the previous-year question card with its exam and year before publishing.']);
            }
        }
    }
}
