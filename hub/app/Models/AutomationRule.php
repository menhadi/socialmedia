<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AutomationRule extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public static function assertSchedule(PostSchedule $schedule): void
    {
        if (! $schedule->automation_rule_id) {
            return;
        }
        $rule = self::find($schedule->automation_rule_id);
        if (! $rule || ! $rule->enabled || $rule->version !== $schedule->automation_version || $rule->brand_id !== $schedule->post->brand_id || $rule->social_account_id !== $schedule->social_account_id || $rule->channel !== $schedule->post->channel) {
            throw new \RuntimeException('Automation settings changed.');
        }
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'trust_intake' => 'boolean', 'learn' => 'boolean', 'with_image' => 'boolean', 'options' => 'encrypted:array'];
    }
}
