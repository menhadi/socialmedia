<?php

namespace App\Services\Ai;

use App\Models\AiConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AiClient
{
    /** @return array{text:string,input:?int,output:?int,partial:bool} */
    public function generate(AiConnection $connection, array $prompt): array
    {
        $request = Http::acceptJson()->asJson()->connectTimeout(8)->timeout(25)->withoutRedirecting();
        $model = $connection->model;
        $limit = $connection->max_output_tokens;
        $messages = [['role' => 'system', 'content' => $prompt['system']], ['role' => 'user', 'content' => $prompt['user']]];
        [$url,$body,$headers] = match ($connection->provider) {
            'openai' => ['https://api.openai.com/v1/responses', [
                'model' => $model, 'instructions' => $prompt['system'], 'input' => $prompt['user'], 'max_output_tokens' => $limit, 'store' => false,
            ], ['Authorization' => 'Bearer '.$connection->api_key]],
            'deepseek' => ['https://api.deepseek.com/chat/completions', [
                'model' => $model, 'messages' => $messages, 'max_tokens' => $limit, 'stream' => false,
            ], ['Authorization' => 'Bearer '.$connection->api_key]],
            'anthropic' => ['https://api.anthropic.com/v1/messages', [
                'model' => $model, 'system' => $prompt['system'], 'messages' => [['role' => 'user', 'content' => $prompt['user']]], 'max_tokens' => $limit,
            ], ['x-api-key' => $connection->api_key, 'anthropic-version' => '2023-06-01']],
            'gemini' => ['https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                'systemInstruction' => ['parts' => [['text' => $prompt['system']]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt['user']]]]],
                'generationConfig' => ['maxOutputTokens' => $limit, 'candidateCount' => 1],
            ], ['x-goog-api-key' => $connection->api_key]],
            default => throw new AiFailure('model'),
        };
        try {
            $response = $request->withHeaders($headers)->post($url, $body);
        } catch (ConnectionException) {
            throw new AiFailure('timeout', true);
        }
        if (! $response->successful()) {
            throw new AiFailure(match ($response->status()) {
                401,403 => 'authentication',429 => 'rate_limit',400,404,422 => 'model',default => 'unavailable',
            }, $response->status() >= 500);
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new AiFailure('response', true);
        }
        $result = match ($connection->provider) {
            'openai' => $this->openAi($json),
            'deepseek' => [
                'text' => data_get($json, 'choices.0.message.content', ''),
                'input' => $this->tokens(data_get($json, 'usage.prompt_tokens')),
                'output' => $this->tokens(data_get($json, 'usage.completion_tokens')),
                'partial' => data_get($json, 'choices.0.finish_reason') !== 'stop',
            ],
            'anthropic' => [
                'text' => $this->textBlocks($json['content'] ?? [], 'text'),
                'input' => $this->tokens(data_get($json, 'usage.input_tokens')),
                'output' => $this->tokens(data_get($json, 'usage.output_tokens')),
                'partial' => ($json['stop_reason'] ?? null) !== 'end_turn',
            ],
            'gemini' => $this->gemini($json),
        };
        if (! is_string($result['text']) || trim($result['text']) === '') {
            throw new AiFailure('empty');
        }
        $result['text'] = trim($result['text']);
        if (mb_strlen($result['text']) > 20000) {
            $result['text'] = mb_substr($result['text'], 0, 20000);
            $result['partial'] = true;
        }

        return $result;
    }

    private function tokens(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function textBlocks(array $blocks, string $type): string
    {
        return implode("\n", array_column(array_filter($blocks, fn ($block) => is_array($block) && ($block['type'] ?? null) === $type && is_string($block['text'] ?? null)), 'text'));
    }

    private function openAi(array $json): array
    {
        $text = [];
        foreach ($json['output'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'message') {
                $text[] = $this->textBlocks($item['content'] ?? [], 'output_text');
            }
        }

        return ['text' => implode("\n", $text), 'input' => $this->tokens(data_get($json, 'usage.input_tokens')), 'output' => $this->tokens(data_get($json, 'usage.output_tokens')), 'partial' => ($json['status'] ?? null) !== 'completed'];
    }

    private function gemini(array $json): array
    {
        $parts = data_get($json, 'candidates.0.content.parts', []);
        $texts = [];
        foreach ($parts as $part) {
            if (empty($part['thought']) && is_string($part['text'] ?? null)) {
                $texts[] = $part['text'];
            }
        }
        $input = $this->tokens(data_get($json, 'usageMetadata.promptTokenCount'));
        $total = $this->tokens(data_get($json, 'usageMetadata.totalTokenCount'));

        return ['text' => implode("\n", $texts), 'input' => $input, 'output' => $total !== null && $input !== null && $total >= $input ? $total - $input : null, 'partial' => data_get($json,'candidates.0.finishReason') !== 'STOP'];
    }
}
