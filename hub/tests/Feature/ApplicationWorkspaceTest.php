<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function application(): Brand
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Http::preventStrayRequests();

        return Brand::factory()->create(['user_id' => $user->id]);
    }

    private function postFor(Brand $brand, string $channel = 'facebook'): Post
    {
        return $brand->posts()->create(['title' => fake()->uuid(), 'body' => 'Useful content', 'channel' => $channel]);
    }

    public function test_application_filters_accounts_and_posts_and_prefills_new_posts(): void
    {
        $brand = $this->application();
        $other = Brand::factory()->create(['user_id' => $brand->user_id]);
        SocialAccount::factory()->create(['brand_id' => $brand->id, 'display_name' => 'Matching account']);
        SocialAccount::factory()->create(['brand_id' => $other->id, 'display_name' => 'Other account']);
        $match = $this->postFor($brand, 'x');
        $wrongPlatform = $this->postFor($brand);
        $wrongBrand = $this->postFor($other, 'x');
        $this->get(route('social', ['brand' => $brand->id]))->assertOk()->assertSee('Matching account')->assertDontSee('Other account');
        $this->get(route('posts', ['brand' => $brand->id, 'channel' => 'x']))->assertOk()->assertSee($match->title)->assertDontSee($wrongPlatform->title)->assertDontSee($wrongBrand->title);
        $this->get(route('posts.create', ['brand' => $brand->id, 'channel' => 'x']))->assertOk()->assertViewHas('post', fn ($post) => $post->brand_id === $brand->id && $post->channel === 'x');
        $this->get(route('applications'))->assertOk()->assertSee(route('applications.show', $brand), false);
        $foreign = Brand::factory()->create();
        $this->get(route('social', ['brand' => $foreign->id]))->assertNotFound();
        $this->get(route('posts', ['brand' => $foreign->id]))->assertNotFound();
    }

    public function test_account_workspace_and_bulk_archive_exclude_other_accounts_on_same_platform(): void
    {
        $brand = $this->application();
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id]);
        $other = SocialAccount::factory()->create(['brand_id' => $brand->id]);
        $ownPublication = Publication::factory()->create(['social_account_id' => $account->id, 'status' => 'published']);
        $otherPublication = Publication::factory()->create(['social_account_id' => $other->id, 'status' => 'published']);
        $ownPublication->post->update(['title' => 'Selected account history']);
        $otherPublication->post->update(['title' => 'Other account history']);
        $draft = $this->postFor($brand);
        $filters = ['brand' => $brand->id, 'channel' => 'facebook', 'account' => $account->id];
        $this->get(route('applications.show', $brand))->assertOk()->assertSee('View posts')->assertSee(route('posts', $filters));
        $this->get(route('posts', $filters))->assertOk()->assertSee('Selected account history')->assertDontSee('Other account history')->assertSee($draft->title);
        $this->post(route('posts.bulk-archive'), $filters + ['scope' => 'filtered'])->assertRedirect();
        $this->assertNotNull($ownPublication->post->fresh()->archived_at);
        $this->assertNotNull($draft->fresh()->archived_at);
        $this->assertNull($otherPublication->post->fresh()->archived_at);
        $foreign = SocialAccount::factory()->create();
        $this->get(route('posts', ['account' => $foreign->id]))->assertNotFound();
        $this->post(route('posts.bulk-archive'), ['account' => $foreign->id, 'scope' => 'filtered'])->assertNotFound();
        $this->get(route('applications.show', $foreign->brand))->assertNotFound();
    }

    public function test_selected_archive_is_idempotent_and_preserves_published_posts(): void
    {
        $brand = $this->application();
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id]);
        $publication = Publication::factory()->create(['social_account_id' => $account->id, 'status' => 'published']);
        $schedule = PostSchedule::create(['post_id' => $publication->post_id, 'social_account_id' => $account->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'credential_version' => (string) Str::uuid(), 'scheduled_at' => now()->addHour(), 'status' => 'queued']);
        $other = $this->postFor($brand);
        $payload = ['scope' => 'selected', 'post_ids' => [$publication->post_id]];
        $this->post(route('posts.bulk-archive'), $payload)->assertRedirect();
        $archived = Post::findOrFail($publication->post_id)->archived_at;
        $this->assertNotNull($archived);
        $this->post(route('posts.bulk-archive'), $payload)->assertRedirect();
        $this->assertEquals($archived, Post::findOrFail($publication->post_id)->archived_at);
        $this->assertSame('cancelled', $schedule->fresh()->status);
        $this->assertNull($other->fresh()->archived_at);
        $this->assertSame('published', $publication->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_all_matching_spans_pages_but_respects_application_and_platform(): void
    {
        $brand = $this->application();
        for ($i = 0; $i < 23; $i++) {
            $this->postFor($brand, 'x');
        }
        $others = [$this->postFor($brand), $this->postFor(Brand::factory()->create(['user_id' => $brand->user_id]), 'x'), $this->postFor(Brand::factory()->create(), 'x')];
        $this->post(route('posts.bulk-archive'), ['scope' => 'filtered', 'brand' => $brand->id, 'channel' => 'x'])->assertRedirect();
        $this->assertSame(23, Post::whereNotNull('archived_at')->count());
        foreach ($others as $post) {
            $this->assertNull($post->fresh()->archived_at);
        }
    }

    public function test_foreign_or_out_of_filter_selection_archives_nothing(): void
    {
        $brand = $this->application();
        $own = $this->postFor($brand);
        $foreign = $this->postFor(Brand::factory()->create());
        $this->post(route('posts.bulk-archive'), ['scope' => 'selected', 'post_ids' => [$own->id, $foreign->id]])->assertNotFound();
        $this->post(route('posts.bulk-archive'), ['scope' => 'selected', 'post_ids' => [$own->id], 'channel' => 'x'])->assertNotFound();
        $this->assertSame(0, Post::whereNotNull('archived_at')->count());
    }

    public function test_uncertain_publication_rolls_back_entire_batch(): void
    {
        $brand = $this->application();
        $first = $this->postFor($brand);
        $account = SocialAccount::factory()->create(['brand_id' => $brand->id]);
        $publication = Publication::factory()->create(['social_account_id' => $account->id, 'status' => 'uncertain']);
        $this->post(route('posts.bulk-archive'), ['scope' => 'selected', 'post_ids' => [$first->id, $publication->post_id]])->assertSessionHasErrors('post_ids');
        $this->assertSame(0, Post::whereNotNull('archived_at')->count());
    }
}
