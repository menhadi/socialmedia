<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TrendRunFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrendRun extends Model
{
    /** @use HasFactory<TrendRunFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'package' => 'array', 'expires_at' => 'immutable_datetime'];
    }

    protected function runDate(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): CarbonImmutable => CarbonImmutable::parse($value),
            set: fn ($value): string => CarbonImmutable::parse($value)->toDateString(),
        );
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
