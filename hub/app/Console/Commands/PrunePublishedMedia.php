<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Publication;
use App\Models\WebsiteDailyBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PrunePublishedMedia extends Command
{
    protected $signature = 'hub:prune-published-media {--dry-run}';

    protected $description = 'Remove published local media while preserving pending and shared uploads';

    private function paths(array $data): array
    {
        $paths = array_filter([$data['image_path'] ?? null, $data['video_path'] ?? null]);
        foreach ($data['card_images'] ?? [] as $card) {
            $paths[] = $card['path'] ?? null;
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    public function handle(): int
    {
        $candidates = [];
        $protected = [];
        foreach (Post::with(['publications', 'schedules'])->lazyById() as $post) {
            $done = $post->status === 'published'
                && $post->publications->contains(fn ($publication) => $publication->status === 'published' && filled($publication->remote_post_id))
                && ! $post->publications->contains(fn ($publication) => in_array($publication->status, ['publishing', 'uncertain'], true))
                && ! $post->schedules->contains(fn ($schedule) => ! in_array($schedule->status, ['published', 'cancelled'], true));
            foreach ($this->paths($post->toArray()) as $path) {
                if ($done) {
                    $candidates[$path] = true;
                } else {
                    $protected[$path] = true;
                }
            }
        }
        foreach (Publication::cursor() as $publication) {
            foreach ($this->paths($publication->toArray()) as $path) {
                if ($publication->status === 'published' && filled($publication->remote_post_id)) {
                    $candidates[$path] = true;
                } elseif (in_array($publication->status, ['publishing', 'uncertain'], true)) {
                    $protected[$path] = true;
                }
            }
        }
        // Today's batch can still add destinations using its shared video.
        foreach (WebsiteDailyBatch::where('run_date', '>=', now('Asia/Kolkata')->toDateString())->cursor() as $batch) {
            foreach ($batch->payloads['posts'] ?? [] as $package) {
                foreach ($this->paths($package['video_media'] ?? []) as $path) {
                    $protected[$path] = true;
                }
            }
        }
        $disk = Storage::disk('local');
        $count = 0;
        foreach (array_diff_key($candidates, $protected) as $path => $_) {
            if (! preg_match('~\A(?:post-images|generated-media|chart-videos)/[a-zA-Z0-9/_-]+\.(?:png|jpg|jpeg|mp4)\z~D', $path)) {
                continue;
            }
            $paths = [$path];
            if (str_starts_with($path, 'chart-videos/') && str_ends_with($path, '.mp4')) {
                $thumbnail = substr($path, 0, -4).'.png';
                if (! isset($protected[$thumbnail])) {
                    $paths[] = $thumbnail;
                }
            }
            foreach ($paths as $file) {
                if ($disk->exists($file)) {
                    if (! $this->option('dry-run') && ! $disk->delete($file)) {
                        $this->error('Could not remove '.$file);

                        return self::FAILURE;
                    }
                    $count++;
                }
            }
        }
        $this->info($count.' published media files '.($this->option('dry-run') ? 'eligible for removal.' : 'removed.'));

        return self::SUCCESS;
    }
}
