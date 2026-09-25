<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiConnection extends Model
{
    use HasFactory;

    public const PROVIDERS = ['deepseek' => 'DeepSeek', 'openai' => 'OpenAI', 'gemini' => 'Google Gemini', 'anthropic' => 'Anthropic Claude'];

    protected $fillable = ['model', 'api_key'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'enabled' => 'boolean', 'daily_budget_micros' => 'integer', 'daily_request_limit' => 'integer', 'max_output_tokens' => 'integer'];
    }
}
