<?php

namespace App\Http\Controllers;

use App\Models\AiConnection;
use App\Models\AiGeneration;
use App\Models\Brand;
use App\Models\MediaConnection;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Research\ContentVisual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HubController extends Controller
{
    private function brands(Request $r)
    {
        return Brand::where('user_id', $r->user()->id);
    }

    private function ownBrand(Request $r, Brand $brand): void
    {
        abort_unless($brand->user_id === $r->user()->id, 404);
    }

    private function connections(Request $r)
    {
        return AiConnection::where('user_id', $r->user()->id)->get();
    }

    public function dashboard(Request $r)
    {
        $brands = $this->brands($r)->with('healthMonitor')->withCount('posts')->latest()->get();
        $posts = Post::whereIn('brand_id', $brands->pluck('id'))->with('brand')->latest()->limit(6)->get();

        $savedAccounts = SocialAccount::whereIn('brand_id', $brands->pluck('id'))->count();
        $connectedPages = SocialAccount::whereIn('brand_id', $brands->pluck('id'))->whereNotNull('verified_at')->whereNotNull('access_token')->count();

        return view('dashboard', compact('brands', 'posts', 'connectedPages', 'savedAccounts'));
    }

    public function applications(Request $r)
    {
        $brands = $this->brands($r)->withCount('posts')->latest()->get();

        return view('applications', compact('brands'));
    }

    public function brandForm(Request $r, ?Brand $brand = null)
    {
        if ($brand) {
            $this->ownBrand($r, $brand);
        }

        return view('brand-form', ['brand' => $brand ?? new Brand, 'connections' => $this->connections($r),
            'mediaConnections' => MediaConnection::where('user_id', $r->user()->id)->get()]);
    }

    public function saveBrand(Request $r, ?Brand $brand = null)
    {
        if ($brand) {
            $this->ownBrand($r, $brand);
        }
        $data = $r->validate([
            'pyp_only' => 'sometimes|boolean',
            'name' => 'required|string|max:150', 'website' => 'nullable|url:http,https|max:2048', 'description' => 'nullable|string|max:5000', 'audience' => 'nullable|string|max:2000', 'tone' => 'required|string|max:150', 'language' => 'required|string|max:100', 'instructions' => 'nullable|string|max:10000',
            'ai_connection_id' => ['nullable', Rule::exists('ai_connections', 'id')->where('user_id', $r->user()->id)],
            'image_connection_id' => ['nullable', Rule::exists('media_connections', 'id')->where('user_id', $r->user()->id)->where('kind', 'image')],
            'video_connection_id' => ['nullable', Rule::exists('media_connections', 'id')->where('user_id', $r->user()->id)->where('kind', 'video')],
        ]);
        $brand ??= new Brand;
        $brand->fill($data);
        $brand->user_id = $r->user()->id;
        $brand->save();

        return redirect()->route('applications')->with('success', 'Application saved.');
    }

    public function posts(Request $r)
    {
        $brands = $this->brands($r)->orderBy('name')->get();
        $query = Post::whereIn('brand_id', $brands->pluck('id'))->with(['brand', 'schedules']);
        $r->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        if ($r->filled('brand')) {
            $query->where('brand_id', $r->integer('brand'));
        }
        $posts = $query->latest()->paginate(20)->withQueryString();

        return view('posts', compact('posts', 'brands'));
    }

    public function postForm(Request $r, ?Post $post = null)
    {
        if ($post) {
            $this->ownBrand($r, $post->brand);
        }

        return view('post-form', ['post' => $post ?? new Post, 'brands' => $this->brands($r)->orderBy('name')->get()]);
    }

    public function savePost(Request $r, ?Post $post = null)
    {
        if ($post) {
            $this->ownBrand($r, $post->brand);
        }
        $data = $r->validate([
            'brand_id' => ['required', Rule::exists('brands', 'id')->where('user_id', $r->user()->id)],
            'title' => 'required|string|max:200', 'channel' => ['required', Rule::in(array_keys(Post::CHANNELS))],
            'body' => 'required|string|max:20000', 'source_url' => 'nullable|url:http,https|max:2048',
        ]);
        $visuals = app(ContentVisual::class);
        $data['visual'] = $visuals->validate($r->input('visual'), $data['source_url'] ?? null);
        $data['body'] = $visuals->caption($data['body'], $data['visual']);
        $post = DB::transaction(function () use ($post, $data): Post {
            if ($post) {
                $post = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
                $post->assertEditable();
                $post->schedules()->where('status', 'queued')->update(['status' => 'cancelled', 'reason' => 'Post edited; review and schedule the new version.']);
                $post->image_path = null;
                $post->image_hash = null;
                $post->video_path = null;
                $post->video_hash = null;
            } else {
                $post = new Post;
            }
            $post->fill($data);
            $post->brand_id = $data['brand_id'];
            $post->status = 'draft';
            $post->reviewed_at = null;
            $post->automation_reason = null;
            $post->save();

            return $post;
        }, 5);

        return redirect()->route('posts.edit', $post)->with('success', 'Draft saved. You can review it below.');
    }

    public function reviewPost(Request $r, Post $post)
    {
        $this->ownBrand($r, $post->brand);
        DB::transaction(function () use ($post): void {
            $post = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            $post->assertEditable();
            $post->assertContentPolicy();
            if ($post->visual && ! $post->image_hash) {
                throw ValidationException::withMessages(['visual' => 'Create and inspect the content card before reviewing this post.']);
            }
            $post->status = 'reviewed';
            $post->reviewed_at = now();
            $post->save();
        }, 5);

        return back()->with('success', 'Marked as reviewed. This post has not been published.');
    }

    public function providers(Request $r)
    {
        $usage = AiGeneration::where('user_id', $r->user()->id)
            ->where('created_at', '>=', now('UTC')->startOfDay())
            ->selectRaw('ai_connection_id, COUNT(*) AS requests, SUM(cost_micros) AS cost')
            ->groupBy('ai_connection_id')->get()->keyBy('ai_connection_id');

        return view('providers', ['connections' => $this->connections($r)->keyBy('provider'), 'usage' => $usage]);
    }

    public function saveProvider(Request $r, string $provider)
    {
        abort_unless(array_key_exists($provider, AiConnection::PROVIDERS), 404);
        $data = $r->validate([
            'model' => ['nullable', 'required_if:enabled,1', 'string', 'max:150', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/D'],
            'api_key' => 'nullable|string|max:2000', 'remove_key' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
            'daily_request_limit' => 'nullable|required_if:enabled,1|integer|min:1|max:100',
            'max_output_tokens' => 'nullable|required_if:enabled,1|integer|min:256|max:4096',
            'daily_budget' => 'nullable|required_if:enabled,1|numeric|min:0.01|max:1000',
            'input_rate' => 'nullable|required_if:enabled,1|numeric|decimal:0,4|min:0|max:10000',
            'output_rate' => 'nullable|required_if:enabled,1|numeric|decimal:0,4|min:0|max:10000',
        ]);
        $connection = AiConnection::where('user_id', $r->user()->id)->where('provider', $provider)->first() ?? new AiConnection;
        $connection->user_id = $r->user()->id;
        $connection->provider = $provider;
        $connection->model = $data['model'] ?? null;
        if ($r->boolean('remove_key')) {
            $connection->api_key = null;
        } elseif ($r->filled('api_key')) {
            $connection->api_key = $data['api_key'];
        }
        $connection->enabled = $r->boolean('enabled');
        if ($connection->enabled && ! $connection->api_key) {
            throw ValidationException::withMessages(['api_key' => 'Save an API key before enabling generation.']);
        }
        foreach (['daily_request_limit', 'max_output_tokens', 'input_rate', 'output_rate'] as $setting) {
            if (isset($data[$setting])) {
                $connection->{$setting} = $data[$setting];
            }
        }
        if (isset($data['daily_budget'])) {
            $connection->daily_budget_micros = (int) round((float) $data['daily_budget'] * 1000000);
        }
        $connection->save();

        return back()->with('success', 'Provider settings saved. No API request was made.');
    }
}
