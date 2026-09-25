<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthIncident extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(HealthMonitor::class, 'health_monitor_id');
    }
}
