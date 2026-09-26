<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\AiGeneration;
use App\Models\Brand;
use App\Models\User;
use App\Services\Ai\GenerateContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkspace(string $provider = 'deepseek'): array
    {
        $user = User::factory()->create();
        $connection = AiConnection::factory()->enabled()->create(['user_id' => $user->id, 'provider' => $provider]);
        $brand = Brand::factory()->create(['user_id' => $user->id, 'name' => 'Selected application', 'language' => 'Hindi', 'ai_connection_id' => $connection->id]);
        $this->actingAs($user);

        return [$user, $brand, $connection];
    }

    private function payload(Brand $brand): array
    {
        return ['request_key' => (string) Str::uuid(), 'brand_id' => $brand->id, 'task' => 'draft', 'title' => 'Useful ideas', 'channel' => 'facebook', 'language' => '', 'source_text' => 'Verified source text', 'source_url' => 'https://example.test/source'];
    }

    private function completion(string $text = 'नमस्ते दुनिया'): array
    {
        return ['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]];
    }

    public static function providers(): array
    {
        return [
            'DeepSeek' => ['deepseek', 'https://api.deepseek.com/chat/completions', ['choices' => [['message' => ['content' => 'नमस्ते दुनिया'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]], 'Authorization', 'Bearer fake-test-key', 'max_tokens'],
            'OpenAI' => ['openai', 'https://api.openai.com/v1/responses', ['status' => 'completed', 'output' => [['type' => 'reasoning', 'summary' => []], ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'नमस्ते दुनिया']]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]], 'Authorization', 'Bearer fake-test-key', 'max_output_tokens'],
            'Claude' => ['anthropic', 'https://api.anthropic.com/v1/messages', ['content' => [['type' => 'thinking', 'thinking' => 'private reasoning'], ['type' => 'text', 'text' => 'नमस्ते दुनिया']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]], 'x-api-key', 'fake-test-key', 'max_tokens'],
            'Gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/models/test-model:generateContent', ['candidates' => [['content' => ['parts' => [['text' => 'private reasoning', 'thought' => true], ['text' => 'नमस्ते दुनिया']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 15, 'thoughtsTokenCount' => 5, 'totalTokenCount' => 120]], 'x-goog-api-key', 'fake-test-key', 'generationConfig.maxOutputTokens'],
        ];
    }

    #[DataProvider('providers')]
    public function test_provider_generates_hindi_preview_with_usage_without_publishing(string $provider, string $endpoint, array $response, string $header, string $key, string $limitField): void
    {
        Http::preventStrayRequests();
        Http::fake([$endpoint => Http::response($response)]);
        [$user,$brand] = $this->setupWorkspace($provider);
        Brand::factory()->create(['user_id' => $user->id, 'name' => 'Other brand private context']);
        $this->post('/ai-assistant', $this->payload($brand))->assertRedirect()->assertSessionHasNoErrors();
        $generation = AiGeneration::firstOrFail();
        $this->assertSame('completed', $generation->status);
        $this->assertSame('Hindi', $generation->language);
        $this->assertSame('नमस्ते दुनिया', $generation->result);
        $this->assertSame(140, $generation->cost_micros);
        $this->assertSame(20, $generation->output_tokens);
        $this->assertDatabaseCount('posts', 0);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($header, $key, $limitField, $provider) {
            $data = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return ($provider !== 'deepseek' || data_get($request->data(), 'thinking.type') === 'disabled') && $request->hasHeader($header, $key) && data_get($request->data(), $limitField) === 1200 && str_contains($data, 'Hindi') && str_contains($data, 'Selected application') && ! str_contains($data, 'Other brand private context') && ! str_contains($data, 'fake-test-key');
        });
        $this->get('/ai-assistant/'.$generation->id)->assertSee('नमस्ते दुनिया')->assertDontSee('private reasoning');
    }

    public function test_repeated_submission_and_saving_a_result_do_not_duplicate_calls_or_posts(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::response($this->completion())]);
        [, $brand] = $this->setupWorkspace();
        $data = $this->payload($brand);
        $this->post('/ai-assistant', $data)->assertRedirect();
        $this->post('/ai-assistant', $data)->assertRedirect();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_generations', 1);
        $generation = AiGeneration::firstOrFail();
        $this->post('/ai-assistant/'.$generation->id.'/draft', ['title' => 'My draft', 'body' => 'Reviewed wording'])->assertRedirect();
        $this->post('/ai-assistant/'.$generation->id.'/draft', ['title' => 'Duplicate', 'body' => 'Do not overwrite'])->assertRedirect();
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseHas('posts', ['title' => 'My draft', 'body' => 'Reviewed wording', 'status' => 'draft', 'brand_id' => $brand->id]);
        $this->post('/ai-assistant', array_merge($data, ['title' => 'Changed']))->assertSessionHasErrors('title');
        Http::assertSentCount(1);
    }

    public function test_disabled_or_missing_provider_blocks_generation_without_a_request(): void
    {
        Http::preventStrayRequests();
        [, $brand,$connection] = $this->setupWorkspace();
        $connection->enabled = false;
        $connection->save();
        $this->post('/ai-assistant', $this->payload($brand))->assertSessionHasErrors('ai_connection_id');
        $brand->ai_connection_id = null;
        $brand->save();
        $this->post('/ai-assistant', $this->payload($brand))->assertSessionHasErrors('ai_connection_id');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_generations', 0);
    }

    public function test_pending_duplicate_does_not_make_a_second_provider_request(): void
    {
        [$user, $brand] = $this->setupWorkspace();
        $data = $this->payload($brand);
        $normalized = array_merge($data, ['language' => 'Hindi']);
        $attempts = 0;
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => function () use ($user, $brand, $normalized, &$attempts) {
            $attempts++;
            $pending = app(GenerateContent::class)->run($user, $brand, $normalized);
            $this->assertSame('pending', $pending->status);

            return Http::response($this->completion());
        }]);
        $this->post('/ai-assistant', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $attempts);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_generations', 1);
        $this->assertDatabaseHas('ai_generations', ['status' => 'completed']);
    }

    public function test_explicit_provider_override_is_used_instead_of_application_preference(): void
    {
        [$user, $brand] = $this->setupWorkspace();
        $override = AiConnection::factory()->enabled()->create(['user_id' => $user->id, 'provider' => 'openai']);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(self::providers()['OpenAI'][2])]);
        $this->post('/ai-assistant', array_merge($this->payload($brand), ['ai_connection_id' => $override->id]))->assertRedirect();
        $this->assertDatabaseHas('ai_generations', ['provider' => 'openai', 'status' => 'completed']);
        Http::assertSentCount(1);
    }

    public function test_provider_can_be_enabled_without_making_a_paid_request(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $this->put('/ai-providers/deepseek', [
            'model' => 'test-model', 'api_key' => 'fake-key', 'enabled' => 1,
            'daily_request_limit' => 3, 'max_output_tokens' => 500,
            'daily_budget' => '0.25', 'input_rate' => '0.25', 'output_rate' => '1.50',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('ai_connections', ['enabled' => 1, 'daily_budget_micros' => 250000, 'daily_request_limit' => 3]);
        Http::assertNothingSent();
    }

    public function test_daily_request_limit_resets_at_midnight_utc(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 9, 25)->setTime(23, 59, 0));
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::response($this->completion())]);
        [, $brand,$connection] = $this->setupWorkspace();
        $connection->daily_request_limit = 1;
        $connection->save();
        $this->post('/ai-assistant', $this->payload($brand))->assertRedirect();
        $this->post('/ai-assistant', $this->payload($brand))->assertSessionHasErrors('ai_connection_id');
        Http::assertSentCount(1);
        $this->travel(2)->minutes();
        $this->post('/ai-assistant', $this->payload($brand))->assertSessionHasNoErrors()->assertRedirect();
        Http::assertSentCount(2);
    }

    public function test_budget_reservation_blocks_requests_before_any_charge(): void
    {
        Http::preventStrayRequests();
        [, $brand,$connection] = $this->setupWorkspace();
        $connection->daily_budget_micros = 1;
        $connection->save();
        $this->post('/ai-assistant', $this->payload($brand))->assertSessionHasErrors('ai_connection_id');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_generations', 0);
    }

    public function test_timeouts_keep_the_reservation_and_do_not_retry(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::failedConnection()]);
        [, $brand,$connection] = $this->setupWorkspace();
        $data = $this->payload($brand);
        $this->post('/ai-assistant', $data)->assertRedirect();
        $generation = AiGeneration::firstOrFail();
        $this->assertSame('uncertain', $generation->status);
        $this->assertSame('timeout', $generation->error_code);
        $this->assertGreaterThan(0, $generation->cost_micros);
        $this->assertFalse($generation->usage_reported);
        $this->post('/ai-assistant', $data)->assertRedirect();
        $this->assertDatabaseCount('ai_generations', 1);
        $this->get('/ai-assistant/'.$generation->id)->assertSee('may have charged')->assertDontSee('fake-test-key');
        $this->post('/ai-assistant/'.$generation->id.'/draft', ['title' => 'No', 'body' => 'No'])->assertUnprocessable();
        $this->assertDatabaseCount('posts', 0);
    }

    public static function errors(): array
    {
        return ['bad key' => [401, 'authentication'], 'rate limit' => [429, 'rate_limit'], 'invalid model' => [400, 'model'], 'missing model' => [404, 'model'], 'provider down' => [503, 'unavailable']];
    }

    #[DataProvider('errors')]
    public function test_provider_errors_are_sanitized_and_not_retried(int $status, string $code): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::response(['error' => 'private-provider-error-with-key'], $status)]);
        [, $brand] = $this->setupWorkspace();
        $this->post('/ai-assistant', $this->payload($brand))->assertRedirect();
        $generation = AiGeneration::firstOrFail();
        $this->assertSame($code, $generation->error_code);
        $this->get('/ai-assistant/'.$generation->id)->assertDontSee('private-provider-error-with-key');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_partial_output_is_flagged_and_reasoning_is_not_shown(): void
    {
        $response = $this->completion('An unfinished sentence');
        $response['choices'][0]['finish_reason'] = 'length';
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::response($response)]);
        [, $brand] = $this->setupWorkspace();
        $this->post('/ai-assistant', $this->payload($brand))->assertRedirect();
        $generation = AiGeneration::firstOrFail();
        $this->assertSame('partial', $generation->status);
        $this->get('/ai-assistant/'.$generation->id)->assertSee('may be incomplete');
        Http::assertSentCount(1);
    }

    public function test_empty_response_does_not_produce_a_draft(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::response($this->completion(''))]);
        [, $brand] = $this->setupWorkspace();
        $this->post('/ai-assistant', $this->payload($brand))->assertRedirect();
        $this->assertDatabaseHas('ai_generations', ['status' => 'failed', 'error_code' => 'empty']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_missing_usage_keeps_reserved_cost_instead_of_reporting_zero(): void
    {
        $response = $this->completion();
        unset($response['usage']);
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::response($response)]);
        [, $brand] = $this->setupWorkspace();
        $this->post('/ai-assistant', $this->payload($brand))->assertRedirect();
        $generation = AiGeneration::firstOrFail();
        $this->assertFalse($generation->usage_reported);
        $this->assertGreaterThan(0, $generation->cost_micros);
        Http::assertSentCount(1);
    }

    public function test_cross_owner_brand_provider_history_and_result_access_are_blocked(): void
    {
        Http::preventStrayRequests();
        [$user,$brand] = $this->setupWorkspace();
        $other = User::factory()->create();
        $otherBrand = Brand::factory()->create(['user_id' => $other->id]);
        $otherConnection = AiConnection::factory()->enabled()->create(['user_id' => $other->id]);
        $generation = AiGeneration::factory()->create(['brand_id' => $otherBrand->id, 'user_id' => $other->id, 'ai_connection_id' => $otherConnection->id, 'title' => 'Private generation', 'status' => 'completed', 'result' => 'Private text']);
        $this->post('/ai-assistant', $this->payload($otherBrand))->assertSessionHasErrors('brand_id');
        $this->post('/ai-assistant', array_merge($this->payload($brand), ['ai_connection_id' => $otherConnection->id]))->assertSessionHasErrors('ai_connection_id');
        $this->get('/ai-assistant/'.$generation->id)->assertNotFound();
        $this->post('/ai-assistant/'.$generation->id.'/draft', ['title' => 'Hijack', 'body' => 'Hijack'])->assertNotFound();
        $this->get('/ai-assistant')->assertDontSee('Private generation');
        Http::assertNothingSent();
    }

    public function test_translation_requires_source_text_and_can_override_language(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::response($this->completion('Hello'))]);
        [, $brand] = $this->setupWorkspace();
        $this->post('/ai-assistant', array_merge($this->payload($brand), ['task' => 'translate', 'source_text' => '']))->assertSessionHasErrors('source_text');
        Http::assertNothingSent();
        $this->post('/ai-assistant', array_merge($this->payload($brand), ['task' => 'translate', 'language' => 'English', 'source_text' => 'नमस्ते']))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('ai_generations', ['language' => 'English', 'task' => 'translate']);
        Http::assertSentCount(1);
    }

    public function test_enabling_requires_credentials_and_valid_limits(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        $this->put('/ai-providers/openai', ['enabled' => 1, 'model' => 'text-model'])->assertSessionHasErrors(['daily_budget', 'input_rate', 'output_rate', 'daily_request_limit', 'max_output_tokens']);
        $this->put('/ai-providers/openai', ['enabled' => 1, 'model' => 'text-model', 'daily_budget' => 1, 'input_rate' => 1, 'output_rate' => 2, 'daily_request_limit' => 5, 'max_output_tokens' => 1000])->assertSessionHasErrors('api_key');
        $this->assertDatabaseCount('ai_connections', 0);
        Http::assertNothingSent();
    }

    public function test_views_and_output_are_safe_and_ai_requires_login(): void
    {
        Http::preventStrayRequests();
        $this->get('/ai-assistant')->assertRedirect('/login');
        $this->post('/ai-assistant', [])->assertRedirect('/login');
        [$user,$brand,$connection] = $this->setupWorkspace();
        $generation = AiGeneration::factory()->create(['brand_id' => $brand->id, 'user_id' => $user->id, 'ai_connection_id' => $connection->id, 'status' => 'completed', 'result' => '<script>bad()</script>']);
        $this->get('/ai-assistant')->assertOk()->assertSee('Hindi');
        $this->get('/ai-providers')->assertOk()->assertSee('Daily estimated budget');
        $this->get('/ai-assistant/'.$generation->id)->assertOk()->assertDontSee('<script>bad()</script>', false)->assertSee('&lt;script&gt;', false);
        Http::assertNothingSent();
    }
}
