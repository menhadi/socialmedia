<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Services\Social\FacebookClient;
use App\Services\Social\PublishPost;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicationController extends Controller
{
    private function own(Request $request, Post $post): void
    {
        abort_unless($post->brand->user_id === $request->user()->id, 404);
    }

    public function preview(Request $request, Post $post): View
    {
        $this->own($request, $post);
        $accounts = SocialAccount::where('brand_id', $post->brand_id)->where('provider', 'facebook')
            ->whereNotNull('verified_at')->whereNotNull('access_token')->orderBy('page_name')->get();
        $publications = $post->publications()->latest()->get();
        $ready = $post->status === 'reviewed' && $post->reviewed_at && $post->channel === 'facebook'
            && ! $publications->contains(fn (Publication $publication): bool => in_array($publication->status, ['publishing', 'published', 'uncertain'], true));

        return view('publish-post', [
            'post' => $post, 'accounts' => $accounts, 'publications' => $publications,
            'ready' => $ready, 'requestKey' => (string) Str::uuid(),
        ]);
    }

    public function publish(Request $request, Post $post, PublishPost $publisher): RedirectResponse
    {
        $this->own($request, $post);
        $data = $request->validate([
            'social_account_id' => 'required|integer',
            'request_key' => 'required|uuid',
            'fingerprint' => 'required|string|size:64',
            'include_link' => 'nullable|boolean',
            'confirm' => 'accepted',
        ]);
        $publication = $publisher->run($request->user(), $post, $data);

        return redirect()->route('posts.publish', $post)->with('success', match ($publication->status) {
            'published' => 'Published on Facebook. The result is saved below.',
            'failed' => 'Facebook rejected the submission. Check the recorded error below.',
            'uncertain' => 'The outcome needs checking. Open your Facebook Page before taking further action.',
            default => 'This submission has already started. Its status is shown below.',
        });
    }

    public function refreshLink(Request $request, Publication $publication, FacebookClient $client): RedirectResponse
    {
        $this->own($request, $publication->post);
        abort_unless($publication->status === 'published' && $publication->remote_post_id, 422);
        $account = $publication->account;
        if (! $account->access_token) {
            return back()->withErrors(['connection' => 'Save and verify a Page token in Social accounts first.']);
        }
        $url = $client->permalink($account, $publication->remote_post_id);
        if (! $url) {
            return back()->withErrors(['connection' => 'The public link is not available yet. The post remains recorded as published.']);
        }
        $publication->permalink_url = $url;
        $publication->save();

        return back()->with('success', 'Public link updated. No post was created.');
    }
}
