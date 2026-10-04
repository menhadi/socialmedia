<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\User;
use App\Services\Research\ChartVideo;
use App\Services\Research\FetchSource;
use App\Services\Research\PollmediaChart;
use App\Services\Social\PublishPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublishConstituencyVideo extends Command
{
    protected $signature = 'hub:publish-constituency-video {brand} {url} {--publish : Submit to the verified Facebook and X accounts}';

    protected $description = 'Prepare one constituency history video for Facebook and X; repeated calls do not resubmit existing publications';

    public function handle(FetchSource $source, PollmediaChart $charts, ChartVideo $renderer, PublishPost $publisher): int
    {
        $url = $this->argument('url');
        $brand = Brand::findOrFail($this->argument('brand'));
        if (! in_array(parse_url($url, PHP_URL_HOST), ['pollmedia.org', 'www.pollmedia.org'], true)
            || parse_url($url, PHP_URL_PATH) !== '/india/constituency'
            || ! in_array(parse_url($brand->website, PHP_URL_HOST), ['pollmedia.org', 'www.pollmedia.org'], true)) {
            $this->error('Use the Pollmedia application and a Pollmedia constituency URL.');

            return self::FAILURE;
        }
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if (! filled($query['name'] ?? null) || ! filled($query['state'] ?? null) || ! in_array($query['kind'] ?? null, ['pc', 'ac'], true)) {
            $this->error('The URL must identify the constituency name, state and election kind.');

            return self::FAILURE;
        }
        $url = 'https://pollmedia.org/india/constituency?'.http_build_query(array_intersect_key($query, array_flip(['kind', 'state', 'name'])));
        $lock = Cache::lock('constituency-video:'.$brand->id.':'.hash('sha256', $url), 600);
        if (! $lock->get()) {
            $this->error('This constituency is already being prepared.');

            return self::FAILURE;
        }
        try {
            $accounts = $brand->socialAccounts()->whereIn('provider', ['facebook', 'x'])->whereNotNull('verified_at')->get();
            if ($accounts->count() !== 2 || $accounts->pluck('provider')->unique()->count() !== 2 || $accounts->contains(fn ($a) => ! $a->access_token)) {
                throw new \RuntimeException('Exactly one verified Facebook and X account is required.');
            }
            $title = $query['name'].' | Constituency election history';
            $posts = [];
            $visual = null;
            $media = null;
            foreach ($accounts as $account) {
                $post = $brand->posts()->where('source_url', $url)->where('title', $title)->where('channel', $account->provider)->first();
                if (! $post) {
                    $visual ??= $charts->select($source->publicHtml($url), $url)['visual'] ?? null;
                    if (! isset($visual['history'])) {
                        throw new \RuntimeException('The source does not have a consistent constituency history table. No post was submitted.');
                    }
                    $post = $brand->posts()->create(['title' => $title, 'channel' => $account->provider, 'source_url' => $url, 'visual' => $visual,
                        'body' => $query['name'].': turnout, winners and winning margins, '.min($visual['labels']).'–'.max($visual['labels']).'. Watch history unfold and compare the latest elections. Boundaries may change; source review flags retained. #Pollmedia']);
                }
                if (! $post->publications()->exists() && ! $post->video_hash) {
                    $media ??= $renderer->create(['title' => $title, 'source_url' => $url, 'visual' => $post->visual]);
                    $post->forceFill($media)->save();
                }
                $posts[] = [$post, $account];
            }
            foreach ($posts as [$post, $account]) {
                if ($publication = $post->publications()->latest('id')->first()) {
                    $this->line($account->provider.': existing '.$publication->status.' '.($publication->permalink_url ?? $publication->remote_post_id));

                    continue;
                }
                if (! $this->option('publish')) {
                    $this->line($account->provider.': draft '.$post->id.' prepared. Add --publish to submit.');

                    continue;
                }
                $post->forceFill(['status' => 'reviewed', 'reviewed_at' => now()])->save();
                $publication = $publisher->run(User::findOrFail($brand->user_id), $post, [
                    'social_account_id' => $account->id, 'fingerprint' => $post->publishingFingerprint(),
                    'request_key' => (string) Str::uuid(), 'include_link' => true,
                ]);
                $this->line($account->provider.': '.$publication->status.' '.($publication->permalink_url ?? $publication->remote_post_id ?? $publication->error_code));
            }

            return self::SUCCESS;
        } catch (ValidationException $error) {
            $this->error(implode(' ', array_merge(...array_values($error->errors()))));

            return self::FAILURE;
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
