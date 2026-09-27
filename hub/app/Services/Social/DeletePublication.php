<?php

namespace App\Services\Social;

use App\Models\Post;
use App\Models\Publication;
use App\Models\PublicationDeletion;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeletePublication
{
    public static function supported(string $provider): bool
    {
        return in_array($provider, ['facebook', 'x', 'linkedin', 'youtube'], true);
    }

    public static function fingerprint(Publication $publication): string
    {
        return hash('sha256', json_encode([$publication->id, $publication->remote_post_id, $publication->provider, $publication->page_id, $publication->account?->credential_version], JSON_THROW_ON_ERROR));
    }

    public function run(User $user, Publication $publication, array $data): PublicationDeletion
    {
        [$attempt,$account,$created,$publication] = DB::transaction(function () use ($user, $publication, $data): array {
            Post::whereKey($publication->post_id)->lockForUpdate()->firstOrFail();
            $publication = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            abort_unless($publication->post->brand->user_id === $user->id, 404);
            $existing = PublicationDeletion::where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->publication_id === $publication->id && $existing->user_id === $user->id, 404);
                $this->ensure(hash_equals($existing->fingerprint, $data['fingerprint']), 'This deletion preview changed.');

                return [$existing, null, false, $publication];
            }
            $this->ensure($publication->status === 'published' && $publication->remote_post_id && ! $publication->remote_deleted_at, 'Only a recorded live publication can be deleted.');
            $this->ensure(self::supported($publication->provider), 'This platform has no deletion adapter in Content Hub. Delete it on the platform and record the removal here.');
            $this->ensure(! $publication->deletions()->whereIn('status', ['pending', 'uncertain'])->exists(), 'A deletion is pending or uncertain. Check the platform before recording its outcome. Do not resubmit.');
            $account = SocialAccount::whereKey($publication->social_account_id)->lockForUpdate()->first();
            $this->ensure($account && $account->verified_at && $account->access_token && $account->brand_id === $publication->post->brand_id && $account->provider === $publication->provider && $account->page_id === $publication->page_id, 'Reconnect and verify the original publishing account before deleting.');
            $publication->setRelation('account', $account);
            $this->ensure(hash_equals(self::fingerprint($publication), $data['fingerprint']), 'The account or publication changed. Open a fresh deletion preview.');
            $this->ensure($this->validId($publication), 'The saved remote post ID is invalid.');
            $attempt = PublicationDeletion::create(['publication_id' => $publication->id, 'user_id' => $user->id, 'request_key' => $data['request_key'], 'fingerprint' => $data['fingerprint']]);

            return [$attempt, $account, true, $publication];
        }, 3);
        if (! $created) {
            return $attempt;
        }
        try {
            $http = Http::withToken($account->access_token)->acceptJson()->connectTimeout(5)->timeout(25)->withoutRedirecting();
            $id = rawurlencode($publication->remote_post_id);
            $response = match ($publication->provider) {
                'facebook' => $http->delete('https://graph.facebook.com/'.config('services.facebook.version').'/'.$id),
                'x' => $http->delete('https://api.x.com/2/tweets/'.$id),
                'linkedin' => $http->withHeaders(['LinkedIn-Version' => config('services.linkedin.version'), 'X-Restli-Protocol-Version' => '2.0.0', 'X-RestLi-Method' => 'DELETE'])->delete('https://api.linkedin.com/rest/posts/'.$id),
                'youtube' => $http->delete('https://www.googleapis.com/youtube/v3/videos?id='.$id),
            };
            $success = match ($publication->provider) {
                'facebook' => $response->successful() && ($response->json('success') === true || $response->json() === true),
                'x' => $response->successful() && $response->json('data.deleted') === true && ! $response->json('errors'),
                default => $response->status() === 204,
            };
            if ($success) {
                $this->confirm($publication, 'hub', $attempt);

                return $attempt->fresh();
            }
            $definite = in_array($response->status(), [400, 401, 403, 422], true) && ! $response->json('error.is_transient', false);
            $attempt->newQuery()->whereKey($attempt->id)->where('status', 'pending')->update(['status' => $definite ? 'rejected' : 'uncertain', 'reason' => $definite ? 'Deletion rejected. Check the original account token, permissions and post type before opening another preview.' : 'Deletion outcome is unknown. A missing/private post or API error is not proof of deletion. Check the platform; this request will not be repeated.']);
        } catch (\Throwable) {
            $attempt->newQuery()->whereKey($attempt->id)->where('status', 'pending')->update(['status' => 'uncertain', 'reason' => 'The deletion response could not be confirmed. Check the platform. No automatic retry will be made.']);
        }

        return $attempt->fresh();
    }

    public function confirm(Publication $publication, string $origin, ?PublicationDeletion $attempt = null): void
    {
        DB::transaction(function () use ($publication, $origin, $attempt): void {
            $post = Post::whereKey($publication->post_id)->lockForUpdate()->firstOrFail();
            $publication = Publication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if (! $publication->remote_deleted_at) {
                $publication->forceFill(['remote_deleted_at' => now(), 'deletion_origin' => $origin])->save();
                if (! $attempt) {
                    $attempt = PublicationDeletion::create(['publication_id' => $publication->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => self::fingerprint($publication), 'origin' => $origin]);
                }
            }
            if ($attempt) {
                $attempt->update(['status' => 'succeeded', 'reason' => 'Removal confirmed; local content and publishing history retained.']);
            }
            $publication->deletions()->whereIn('status', ['pending', 'uncertain'])->update(['status' => 'succeeded', 'reason' => 'Removal subsequently confirmed by '.$origin.'.']);
            $post->schedules()->where('status', 'queued')->update(['status' => 'cancelled', 'reason' => 'Remote publication removed.']);
            $post->forceFill(['archived_at' => $post->archived_at ?? now()])->save();
        }, 3);
    }

    public function recordExternal(User $user, Publication $publication): void
    {
        abort_unless($publication->post->brand->user_id === $user->id, 404);
        $this->ensure($publication->status === 'published' && $publication->remote_post_id, 'This publication has no confirmed remote ID.');
        if ($publication->fresh()->remote_deleted_at) {
            return;
        }
        $attempt = PublicationDeletion::create(['publication_id' => $publication->id, 'user_id' => $user->id, 'request_key' => (string) Str::uuid(), 'fingerprint' => self::fingerprint($publication), 'origin' => 'owner_confirmation']);
        $this->confirm($publication, 'owner_confirmation', $attempt);
    }

    private function validId(Publication $p): bool
    {
        return (bool) preg_match(match ($p->provider) {
            'facebook' => $p->video_path ? '/^[0-9]{1,64}$/D' : '/^'.preg_quote($p->page_id, '/').'_[0-9]+$/D','x' => '/^[0-9]{1,64}$/D','youtube' => '/^[a-zA-Z0-9_-]{11}$/D','linkedin' => '/^urn:li:(share|ugcPost):[0-9]+$/D'
        }, $p->remote_post_id);
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['deletion' => $message]);
        }
    }
}
