<?php

namespace App\Services\Ai;

use App\Models\AiConnection;
use App\Models\AiGeneration;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateContent
{
    public function __construct(private AiClient $client, private ContentPrompt $prompts) {}

    public function run(User $user, Brand $brand, array $data): AiGeneration
    {
        $prompt = $this->prompts->build($brand, $data);
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        [$generation,$connection,$created] = DB::transaction(function () use ($user, $brand, $data, $prompt, $fingerprint) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = AiGeneration::where('user_id', $user->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['title' => 'This request has already been submitted with different details. Open a new request.']);
                }

                return [$existing, null, false];
            }
            $connectionId = $data['ai_connection_id'] ?? $brand->ai_connection_id;
            $connection = AiConnection::where('user_id', $user->id)->whereKey($connectionId)->lockForUpdate()->first();
            if (! $connection || ! $connection->enabled || ! $connection->model || ! $connection->api_key) {
                throw ValidationException::withMessages(['ai_connection_id' => 'Choose an enabled provider with a model and saved API key in AI providers.']);
            }
            if ($connection->input_rate === null || $connection->output_rate === null || $connection->daily_budget_micros < 1 || $connection->daily_request_limit < 1) {
                throw ValidationException::withMessages(['ai_connection_id' => 'Set the provider’s daily request limit, estimated budget and model prices before generating.']);
            }
            $bytes = strlen($prompt['system']) + strlen($prompt['user']);
            if ($bytes > 40000) {
                throw ValidationException::withMessages(['source_text' => 'The combined application details and source text are too long. Shorten them before generating.']);
            }
            $start = now('UTC')->startOfDay();
            $daily = AiGeneration::where('ai_connection_id', $connection->id)->where('created_at', '>=', $start)->where('created_at', '<', $start->copy()->addDay());
            if ((clone $daily)->count() >= $connection->daily_request_limit) {
                throw ValidationException::withMessages(['ai_connection_id' => 'This provider’s daily request limit has been reached. It resets at midnight UTC.']);
            }
            $reserved = $this->estimate($bytes + 1024, $connection->max_output_tokens, (float) $connection->input_rate, (float) $connection->output_rate);
            if ((clone $daily)->sum('cost_micros') + $reserved > $connection->daily_budget_micros) {
                throw ValidationException::withMessages(['ai_connection_id' => 'This request would exceed the provider’s daily estimated budget. Shorten the input, lower the output limit or adjust the budget.']);
            }
            $generation = new AiGeneration;
            $generation->forceFill([
                'user_id' => $user->id, 'brand_id' => $brand->id, 'ai_connection_id' => $connection->id,
                'request_key' => $data['request_key'], 'fingerprint' => $fingerprint,
                'provider' => $connection->provider, 'model' => $connection->model,
                'task' => $data['task'], 'title' => $data['title'], 'channel' => $data['channel'],
                'language' => $data['language'], 'source_url' => $data['source_url'] ?? null,
                'cost_micros' => $reserved, 'input_rate' => $connection->input_rate, 'output_rate' => $connection->output_rate,
            ])->save();

            return [$generation, $connection, true];
        }, 3);
        if (! $created) {
            return $generation;
        }
        try {
            $result = $this->client->generate($connection, $prompt);
        } catch (AiFailure $failure) {
            $generation->status = $failure->uncertain ? 'uncertain' : 'failed';
            $generation->error_code = $failure->reason;
            $generation->save();

            return $generation;
        } catch (\Throwable) {
            $generation->status = 'uncertain';
            $generation->error_code = 'response';
            $generation->save();

            return $generation;
        }
        $generation->result = $result['text'];
        $generation->status = $result['partial'] ? 'partial' : 'completed';
        $generation->input_tokens = $result['input'];
        $generation->output_tokens = $result['output'];
        $generation->usage_reported = $result['input'] !== null && $result['output'] !== null;
        if ($generation->usage_reported) {
            $generation->cost_micros = $this->estimate($result['input'], $result['output'], (float) $generation->input_rate, (float) $generation->output_rate);
        }
        $generation->save();

        return $generation;
    }

    private function estimate(int $input, int $output, float $inputRate, float $outputRate): int
    {
        return (int) ceil($input * $inputRate + $output * $outputRate);
    }
}
