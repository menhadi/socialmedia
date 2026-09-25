<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\Brand;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentHubTest extends TestCase
{
    use RefreshDatabase;

    private function brand(User $user, string $name = 'Sample application'): Brand
    {
        $brand = new Brand(['name' => $name, 'tone' => 'Helpful', 'language' => 'English']);
        $brand->user_id = $user->id;
        $brand->save();

        return $brand;
    }

    private function payload(Brand $brand): array
    {
        return ['brand_id' => $brand->id, 'title' => 'A useful update', 'body' => 'Source-grounded content.', 'channel' => 'facebook'];
    }

    public function test_workspace_requires_login(): void
    {
        foreach (['/', '/applications', '/posts', '/ai-providers'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_application_and_draft_work_without_ai_and_review_resets_on_edit(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->post('/applications', ['name' => 'Any business', 'tone' => 'Warm', 'language' => 'English'])->assertSessionHasNoErrors()->assertRedirect('/applications');
        $brand = Brand::firstOrFail();
        $this->post('/posts', $this->payload($brand))->assertSessionHasNoErrors();
        $post = Post::firstOrFail();
        $this->post("/posts/{$post->id}/review")->assertSessionHasNoErrors();
        $this->assertSame('reviewed', $post->fresh()->status);
        $this->put("/posts/{$post->id}", array_merge($this->payload($brand), ['body' => 'Changed content']))->assertSessionHasNoErrors();
        $this->assertSame('draft', $post->fresh()->status);
        $this->assertNull($post->fresh()->reviewed_at);
        Http::assertNothingSent();
    }

    public function test_other_owners_data_cannot_be_read_or_changed(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $brand = $this->brand($other, 'Private application');
        $post = $brand->posts()->create($this->payload($brand));
        $this->actingAs($owner)->get('/applications')->assertDontSee('Private application');
        $this->get("/applications/{$brand->id}/edit")->assertNotFound();
        $this->put("/applications/{$brand->id}", ['name' => 'Hijack'])->assertNotFound();
        $this->get("/posts/{$post->id}/edit")->assertNotFound();
        $this->post("/posts/{$post->id}/review")->assertNotFound();
        $this->put("/posts/{$post->id}", $this->payload($brand))->assertNotFound();
        $this->post('/posts', $this->payload($brand))->assertSessionHasErrors('brand_id');
        $this->get('/posts?brand='.$brand->id)->assertDontSee('A useful update');
    }

    public function test_api_keys_are_encrypted_hidden_and_preserved_or_removed_explicitly(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->put('/ai-providers/deepseek', ['model' => 'chosen-later', 'api_key' => 'test-secret-value'])->assertRedirect()->assertSessionHasNoErrors();
        $connection = AiConnection::firstOrFail();
        $this->assertSame('test-secret-value', $connection->api_key);
        $this->assertNotSame('test-secret-value', DB::table('ai_connections')->value('api_key'));
        $this->assertArrayNotHasKey('api_key', $connection->toArray());
        $this->get('/ai-providers')->assertOk()->assertDontSee('test-secret-value');
        $this->put('/ai-providers/deepseek', ['model' => 'another-model', 'api_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame('test-secret-value', $connection->fresh()->api_key);
        $this->put('/ai-providers/deepseek', ['remove_key' => 1])->assertSessionHasNoErrors();
        $this->assertNull($connection->fresh()->api_key);
        Http::assertNothingSent();
    }

    public function test_provider_key_not_flashed_on_validation_error(): void
    {
        $this->actingAs(User::factory()->create())->put('/ai-providers/openai', ['api_key' => 'private-value', 'model' => str_repeat('x', 151)])->assertSessionHasErrors('model');
        $this->assertArrayNotHasKey('api_key', session()->getOldInput());
    }

    public function test_an_application_cannot_use_another_owners_ai_connection(): void
    {
        $other = User::factory()->create();
        $connection = new AiConnection;
        $connection->user_id = $other->id;
        $connection->provider = 'openai';
        $connection->save();
        $this->actingAs(User::factory()->create())->post('/applications', ['name' => 'Mine', 'tone' => 'Clear', 'language' => 'English', 'ai_connection_id' => $connection->id])->assertSessionHasErrors('ai_connection_id');
    }

    public function test_all_workspace_views_render_and_escape_untrusted_content(): void
    {
        $user = User::factory()->create();
        $brand = $this->brand($user);
        $post = $brand->posts()->create(array_merge($this->payload($brand), ['body' => '<script>alert(1)</script>']));
        $this->actingAs($user);
        foreach (['/', '/applications', '/applications/new', "/applications/{$brand->id}/edit", '/posts', '/posts/new', "/posts/{$post->id}/edit", '/ai-providers'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get("/posts/{$post->id}/edit")->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $this->post('/posts', array_merge($this->payload($brand),['source_url' => 'javascript:alert(1)']))->assertSessionHasErrors('source_url');
    }
}
