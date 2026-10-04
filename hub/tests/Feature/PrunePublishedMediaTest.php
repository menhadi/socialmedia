<?php

namespace Tests\Feature;

use App\Models\Publication;
use App\Models\WebsiteDailyBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrunePublishedMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_video_waits_for_every_destination_and_current_batch(): void
    {
        Storage::fake('local');
        $path = 'chart-videos/example.mp4';
        Storage::disk('local')->put($path, 'video');
        Storage::disk('local')->put('chart-videos/example.png', 'cover');
        $first = Publication::factory()->create(['status' => 'published', 'remote_post_id' => '123', 'video_path' => $path]);
        $first->post->forceFill(['status' => 'published', 'video_path' => $path])->save();
        $second = Publication::factory()->create(['video_path' => $path]);
        $second->post->forceFill(['status' => 'publishing', 'video_path' => $path])->save();
        $this->artisan('hub:prune-published-media')->assertSuccessful();
        Storage::disk('local')->assertExists($path);
        $second->forceFill(['status' => 'published', 'remote_post_id' => '456'])->save();
        $second->post->forceFill(['status' => 'published'])->save();
        $batch = WebsiteDailyBatch::create(['brand_id' => $first->post->brand_id, 'run_date' => now('Asia/Kolkata')->toDateString(),
            'payloads' => ['posts' => [['video_media' => ['video_path' => $path]]]]]);
        $this->artisan('hub:prune-published-media')->assertSuccessful();
        Storage::disk('local')->assertExists($path);
        $batch->update(['run_date' => now('Asia/Kolkata')->subDay()->toDateString()]);
        $this->artisan('hub:prune-published-media --dry-run')->assertSuccessful();
        Storage::disk('local')->assertExists($path);
        $this->artisan('hub:prune-published-media')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertMissing('chart-videos/example.png');
        $this->assertSame('123', $first->fresh()->remote_post_id);
    }

    public function test_cards_are_removed_but_drafts_failed_uploads_and_unmanaged_files_remain(): void
    {
        Storage::fake('local');
        $cards = [['path' => 'post-images/one.png'], ['path' => 'post-images/two.png']];
        foreach (['post-images/one.png', 'post-images/two.png', 'post-images/draft.png', 'post-images/failed.png', 'private/keep.png'] as $path) {
            Storage::disk('local')->put($path, 'image');
        }
        $done = Publication::factory()->create(['status' => 'published', 'remote_post_id' => '123', 'card_images' => $cards, 'image_path' => 'private/keep.png']);
        $done->post->forceFill(['status' => 'published', 'card_images' => $cards, 'image_path' => 'private/keep.png'])->save();
        $done->post->brand->posts()->create(['title' => 'Draft', 'channel' => 'facebook', 'body' => 'Draft'])->forceFill(['image_path' => 'post-images/draft.png'])->save();
        $failed = Publication::factory()->create(['status' => 'failed', 'image_path' => 'post-images/failed.png']);
        $failed->post->forceFill(['image_path' => 'post-images/failed.png'])->save();
        $this->artisan('hub:prune-published-media')->assertSuccessful();
        Storage::disk('local')->assertMissing('post-images/one.png');
        Storage::disk('local')->assertMissing('post-images/two.png');
        foreach (['post-images/draft.png', 'post-images/failed.png', 'private/keep.png'] as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }
}
