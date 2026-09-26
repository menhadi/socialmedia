<?php

namespace App\Services\Social;

use App\Models\Post;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PublishPost
{
    public function __construct(private PlatformClient $client) {}

    public function run(User $user, Post $post, array $data): Publication
    {
        $options = ChannelRules::options($post->channel, $data['options'] ?? []);
        $parts = [$data['fingerprint'], (int) $data['social_account_id'], (bool) ($data['include_link'] ?? false)];
        if ($options) {
            $parts[] = $options;
        }
        $fingerprint = hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
        [$publication, $account, $created] = DB::transaction(function () use ($user, $post, $data, $fingerprint, $options): array {
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
            $activeSchedule = $post->schedules()->whereIn('status', ['queued', 'running', 'processing'])->first();
            if ($activeSchedule && ($activeSchedule->id !== ($data['schedule_id'] ?? null) || $activeSchedule->status !== 'running'
                || $activeSchedule->request_key !== $data['request_key'])) {
                throw ValidationException::withMessages(['post' => 'Cancel the active schedule before publishing manually.']);
            }
            if (! $activeSchedule) {
                $post->assertEditable();
            } elseif ($post->publications()->whereIn('status', ['publishing', 'published', 'uncertain'])->exists()) {
                throw ValidationException::withMessages(['post' => 'A publication already exists for this post.']);
            }
            if ($post->status !== 'reviewed' || ! $post->reviewed_at || ! ChannelRules::supported($post->channel)) {
                throw ValidationException::withMessages(['post' => 'Save and review a post before publishing.']);
            }
            if (! hash_equals($post->publishingFingerprint(), $data['fingerprint'])) {
                throw ValidationException::withMessages(['post' => 'The saved post changed. Open a new preview and check the current version.']);
            }
            $account = SocialAccount::whereKey($data['social_account_id'])->where('brand_id', $post->brand_id)->where('provider', $post->channel)->lockForUpdate()->first();
            if (! $account || ! $account->verified_at || ! $account->access_token) {
                throw ValidationException::withMessages(['social_account_id' => 'Choose a verified account connected to this application.']);
            }
            if (isset($data['credential_version']) && $account->credential_version !== $data['credential_version']) {
                throw ValidationException::withMessages(['post' => 'The account connection changed.']);
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
            ChannelRules::validate($post, (bool) ($data['include_link'] ?? false), $options);
            $publication = new Publication;
            $publication->forceFill([
                'provider' => $account->provider, 'title_snapshot' => $post->title, 'options' => $options,
                'credential_version' => $account->credential_version, 'next_check_at' => now()->addMinutes(30),
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
            return $this->finish($publication, ['status' => 'uncertain', 'error_code' => $account->provider === 'facebook' ? 'response' : 'platform_response']);
        }

        if ($remoteId === null) {
            return $publication->refresh();
        }

        return $this->complete($publication, $account, $remoteId);
    }

    private function complete(Publication $publication, SocialAccount $account, string $remoteId): Publication
    {
        $publication = $this->finish($publication, [
            'status' => 'published', 'next_check_at' => null, 'remote_post_id' => $remoteId, 'published_at' => now(),
        ]);
        $url = $this->client->permalink($account, $remoteId);
        if ($url) {
            $publication->permalink_url = $url;
            $publication->save();
        }

        return $publication;
    }

    public function resume(Publication $publication): void
    {
        $lock = Cache::lock('publication:'.$publication->id, 180);
        if (! $lock->get()) {
            return;
        }
        try {
            $publication->refresh();
            if ($publication->status !== 'publishing' || ! $publication->next_check_at || $publication->next_check_at->isFuture()) {
                return;
            }
            if (($publication->transfer['stage'] ?? '') !== 'waiting') {
                $this->finish($publication, ['status' => 'uncertain', 'error_code' => 'platform_response']);

                return;
            }
            $account = $publication->account;
            if (! $account || ! $account->verified_at || ! $account->access_token || $account->credential_version !== $publication->credential_version) {
                $this->finish($publication, ['status' => 'failed', 'error_code' => 'platform_changed']);

                return;
            }
            if ($publication->created_at->lt(now()->subHour())) {
                $this->finish($publication, ['status' => 'failed', 'error_code' => 'platform_media']);

                return;
            }
            $schedule = $publication->post->schedules()->where('request_key', $publication->request_key)->first();
            if ($schedule?->automatic) {
                $snapshot = $schedule->snapshot;
                $source = $snapshot?->source;
                if (! $source || ! $source->enabled || ! $source->auto_publish || ! $source->approved_at || $source->version !== $snapshot->source_version) {
                    $this->finish($publication, ['status' => 'failed', 'error_code' => 'platform_approval']);

                    return;
                }
            }
            try {
                $id = $this->client->resume($account, $publication);
                if ($id !== null) {
                    $this->complete($publication, $account, $id);
                }
            } catch (\Throwable $failure) {
                $publication->refresh();
                $submitting = ($publication->transfer['stage'] ?? '') === 'submitting';
                if (! $submitting && ! ($failure instanceof FacebookFailure && ! in_array($failure->reason, ['platform_response', 'platform_rate'], true))) {
                    $publication->forceFill(['next_check_at' => now()->addMinute()])->save();

                    return;
                }
                $uncertain = $failure instanceof FacebookFailure ? $failure->uncertain : $submitting;
                $this->finish($publication, ['status' => $uncertain ? 'uncertain' : 'failed', 'error_code' => $failure instanceof FacebookFailure ? $failure->reason : 'platform_response']);
            }
        } finally {
            $lock->release();
        }
    }

    private function finish(Publication $publication, array $result): Publication
    {
        return DB::transaction(function () use ($publication, $result): Publication {
            $post = Post::whereKey($publication->post_id)->lockForUpdate()->firstOrFail();
            $publication->forceFill($result)->save();
            $post->status = $result['status'] === 'failed' ? 'reviewed' : $result['status'];
            $post->save();
            $post->schedules()->where('request_key', $publication->request_key)->update(['status' => $result['status'], 'reason' => $result['status'] === 'published' ? null : 'Check publishing history before retrying.']);

            return $publication;
        }, 5);
    }
}
