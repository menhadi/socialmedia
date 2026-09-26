<?php

namespace App\Services\Social;

use App\Models\Post;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PublishPost
{
    public function __construct(private FacebookClient $client) {}

    public function run(User $user, Post $post, array $data): Publication
    {
        $fingerprint = hash('sha256', json_encode([$data['fingerprint'], (int) $data['social_account_id'], (bool) ($data['include_link'] ?? false)], JSON_THROW_ON_ERROR));
        [$publication, $account, $created] = DB::transaction(function () use ($user, $post, $data, $fingerprint): array {
            $post = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            abort_unless($post->brand->user_id === $user->id, 404);
            $existing = Publication::where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->post_id === $post->id, 404);
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['post' => 'This submission was already used with different details. Open a new publishing preview.']);
                }

                return [$existing, null, false];
            }
            $activeSchedule = $post->schedules()->whereIn('status', ['queued', 'running'])->first();
            if ($activeSchedule && ($activeSchedule->id !== ($data['schedule_id'] ?? null) || $activeSchedule->status !== 'running'
                || $activeSchedule->request_key !== $data['request_key'])) {
                throw ValidationException::withMessages(['post' => 'Cancel the active schedule before publishing manually.']);
            }
            if (! $activeSchedule) {
                $post->assertEditable();
            } elseif ($post->publications()->whereIn('status', ['publishing', 'published', 'uncertain'])->exists()) {
                throw ValidationException::withMessages(['post' => 'A publication already exists for this post.']);
            }
            if ($post->status !== 'reviewed' || ! $post->reviewed_at || $post->channel !== 'facebook') {
                throw ValidationException::withMessages(['post' => 'Save and review a Facebook post before publishing.']);
            }
            if (! hash_equals($post->publishingFingerprint(), $data['fingerprint'])) {
                throw ValidationException::withMessages(['post' => 'The saved post changed. Open a new preview and check the current version.']);
            }
            $account = SocialAccount::whereKey($data['social_account_id'])->where('brand_id', $post->brand_id)->where('provider', 'facebook')->lockForUpdate()->first();
            if (! $account || ! $account->verified_at || ! $account->access_token) {
                throw ValidationException::withMessages(['social_account_id' => 'Choose a verified Facebook Page connected to this application.']);
            }
            if (isset($data['credential_version']) && $account->credential_version !== $data['credential_version']) {
                throw ValidationException::withMessages(['post' => 'The Page connection changed.']);
            }
            if ($activeSchedule?->automatic) {
                $snapshot = $activeSchedule->snapshot;
                $source = $snapshot?->source()->lockForUpdate()->first();
                if (! $source || ! $source->enabled || ! $source->auto_publish || ! $source->approved_at || $source->version !== $snapshot->source_version) {
                    throw ValidationException::withMessages(['post' => 'Source approval changed.']);
                }
            }
            if ($post->image_path && (! Storage::disk('local')->exists($post->image_path)
                || ! hash_equals($post->image_hash, hash('sha256', Storage::disk('local')->get($post->image_path))))) {
                throw ValidationException::withMessages(['post' => 'The saved image is missing or changed. Regenerate it and review again.']);
            }
            if ($post->video_path && (! Storage::disk('local')->exists($post->video_path)
                || ! hash_equals($post->video_hash, hash('sha256', Storage::disk('local')->get($post->video_path))))) {
                throw ValidationException::withMessages(['post' => 'The saved video is missing or changed. Generate it and review again.']);
            }
            $publication = new Publication;
            $publication->forceFill([
                'post_id' => $post->id, 'social_account_id' => $account->id,
                'request_key' => $data['request_key'], 'fingerprint' => $fingerprint,
                'page_id' => $account->page_id, 'page_name' => $account->page_name,
                'message' => $post->body, 'link' => ($data['include_link'] ?? false) ? $post->source_url : null,
                'status' => 'publishing',
                'image_path' => $post->image_path,
                'video_path' => $post->video_path,
            ])->save();
            $post->status = 'publishing';
            $post->save();

            return [$publication, $account, true];
        }, 5);
        if (! $created) {
            return $publication;
        }
        try {
            $remoteId = $this->client->publish($account, $publication);
        } catch (FacebookFailure $failure) {
            return $this->finish($publication, [
                'status' => $failure->uncertain ? 'uncertain' : 'failed',
                'error_code' => $failure->reason,
            ]);
        } catch (\Throwable) {
            return $this->finish($publication, ['status' => 'uncertain', 'error_code' => 'response']);
        }

        $publication = $this->finish($publication, [
            'status' => 'published', 'remote_post_id' => $remoteId, 'published_at' => now(),
        ]);
        $url = $this->client->permalink($account, $remoteId);
        if ($url) {
            $publication->permalink_url = $url;
            $publication->save();
        }

        return $publication;
    }

    private function finish(Publication $publication, array $result): Publication
    {
        return DB::transaction(function () use ($publication, $result): Publication {
            $post = Post::whereKey($publication->post_id)->lockForUpdate()->firstOrFail();
            $publication->forceFill($result)->save();
            $post->status = $result['status'] === 'failed' ? 'reviewed' : $result['status'];
            $post->save();

            return $publication;
        }, 5);
    }
}
