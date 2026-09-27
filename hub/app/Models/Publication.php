<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Publication extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['remote_deleted_at' => 'datetime', 'published_at' => 'datetime', 'options' => 'encrypted:array', 'transfer' => 'encrypted:array', 'next_check_at' => 'datetime'];
    }

    public function deletions(): HasMany
    {
        return $this->hasMany(PublicationDeletion::class);
    }

    public function latestAnalytics(): HasOne
    {
        return $this->hasOne(AnalyticsSnapshot::class)->latestOfMany();
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
