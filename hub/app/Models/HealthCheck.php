<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthCheck extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(HealthMonitor::class, 'health_monitor_id');
    }
}
