<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthMonitor extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'expected_status' => 'integer', 'interval_minutes' => 'integer',
            'last_checked_at' => 'datetime', 'next_check_at' => 'datetime', 'running_since' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(HealthCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(HealthIncident::class);
    }

    public function displayStatus(): string
    {
        if (! $this->enabled) {
            return 'paused';
        }
        if ($this->running_key && $this->running_since?->gt(now()->subMinutes(2))) {
            return 'checking';
        }
        if (! $this->last_checked_at) {
            return 'unknown';
        }
        if ($this->last_checked_at->lt(now()->subSeconds($this->interval_minutes * 120 + 60))) {
            return 'stale';
        }

        return $this->last_status;
    }

    public function closeIncident(string $reason): void
    {
        $this->incidents()->whereNull('ended_at')->update([
            'ended_at' => $this->last_checked_at ?? now(), 'end_reason' => $reason,
        ]);
    }
}
