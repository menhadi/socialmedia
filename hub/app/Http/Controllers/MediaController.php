<?php

namespace App\Http\Controllers;

use App\Models\MediaConnection;
use App\Models\MediaGeneration;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\GenerateMedia;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function settings(Request $request): View
    {
        $connections = MediaConnection::where('user_id', $request->user()->id)->get()->keyBy('kind');
        $usage = MediaGeneration::where('user_id', $request->user()->id)->where('created_at', '>=', now('UTC')->startOfDay())
            ->selectRaw('kind, COUNT(*) AS requests, SUM(cost_micros) AS cost')->groupBy('kind')->get()->keyBy('kind');

        return view('media-settings', compact('connections', 'usage'));
    }

    public function saveSettings(Request $request, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, ['image', 'video'], true), 404);
        $data = $request->validate([
            'model' => ['required', 'string', 'max:150', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D'],
            'api_key' => 'nullable|string|max:2000', 'remove_key' => 'nullable|boolean', 'enabled' => 'nullable|boolean',
            'daily_budget' => 'required|numeric|min:0|max:1000', 'request_cost' => 'required|numeric|min:0|max:100',
            'daily_request_limit' => 'required|integer|min:1|max:100',
        ]);
        DB::transaction(function () use ($request, $kind, $data): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $connection = MediaConnection::firstOrNew(['user_id' => $request->user()->id, 'kind' => $kind]);
            $connection->model = $data['model'];
            if ($request->boolean('remove_key')) {
                $connection->api_key = null;
            } elseif ($request->filled('api_key')) {
                $connection->api_key = $data['api_key'];
            }
            $connection->enabled = $request->boolean('enabled');
            $connection->daily_budget_micros = (int) round((float) $data['daily_budget'] * 1000000);
            $connection->request_cost_micros = (int) ceil((float) $data['request_cost'] * 1000000);
            $connection->daily_request_limit = $data['daily_request_limit'];
            if ($connection->enabled && (! $connection->api_key || $connection->request_cost_micros < 1 || $connection->daily_budget_micros < $connection->request_cost_micros)) {
                throw ValidationException::withMessages(['media' => 'To enable media, save a key, a positive per-request estimate and a daily budget covering at least one request.']);
            }
            $connection->save();
        });

        return back()->with('success', 'Media settings saved. No paid generation was started.');
    }

    public function index(Request $request, Post $post): View
    {
        abort_unless($post->brand->user_id === $request->user()->id, 404);
        $generations = MediaGeneration::where('post_id', $post->id)->latest()->paginate(10);

        return view('media', compact('post', 'generations'));
    }

    public function store(Request $request, Post $post, GenerateMedia $service): RedirectResponse
    {
        abort_unless($post->brand->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['image', 'video'])], 'request_key' => 'required|uuid',
            'fingerprint' => 'required|string|size:64', 'prompt' => 'required|string|max:3000',
            'aspect_ratio' => ['required', Rule::in(['16:9', '9:16', '1:1'])], 'use_image' => 'nullable|boolean', 'confirm' => 'accepted',
        ]);
        if ($data['kind'] === 'video' && $data['aspect_ratio'] === '1:1') {
            throw ValidationException::withMessages(['aspect_ratio' => 'Choose landscape or portrait for video.']);
        }
        $service->reserve($request->user(), $post, $data);

        return back()->with('success', 'Media request queued. The server scheduler will generate it. Refresh this page to see progress; nothing is published.');
    }

    public function attach(Request $request, MediaGeneration $generation, GenerateMedia $service): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
        $request->validate(['confirm' => 'accepted']);
        $service->attach($generation);

        return redirect()->route('posts.edit', $generation->post_id)->with('success', 'Media attached. Review the post and media together before publishing or scheduling.');
    }

    public function file(Request $request, MediaGeneration $generation): StreamedResponse
    {
        abort_unless($generation->user_id === $request->user()->id && $generation->path && in_array($generation->status, ['completed', 'attached'], true), 404);

        return Storage::disk('local')->response($generation->path, $generation->kind === 'image' ? 'image.png' : 'video.mp4',
            ['Content-Type' => $generation->kind === 'image' ? 'image/png' : 'video/mp4', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function video(Request $request, Post $post): StreamedResponse
    {
        abort_unless($post->brand->user_id === $request->user()->id && $post->video_path, 404);

        return Storage::disk('local')->response($post->video_path, 'post.mp4', ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
