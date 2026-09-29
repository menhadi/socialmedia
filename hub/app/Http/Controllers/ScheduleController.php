<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostSchedule;
use App\Services\Research\PostImage;
use App\Services\Social\SchedulePost;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $schedules = PostSchedule::whereHas('post.brand', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('post.brand')->orderByDesc('id')->paginate(20);

        return view('schedules', compact('schedules'));
    }

    public function store(Request $request, Post $post, SchedulePost $service): RedirectResponse
    {
        abort_unless($post->brand->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'social_account_id' => 'required|integer', 'scheduled_at' => 'required|date_format:Y-m-d\TH:i',
            'timezone' => 'required|timezone', 'fingerprint' => 'required|string|size:64',
            'include_link' => 'nullable|boolean', 'confirm' => 'accepted', 'options' => 'nullable|array',
        ]);
        $when = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['scheduled_at'], $data['timezone'])->setSecond(0)->utc();
        if ($when->lessThan(now()->addMinute()) || $when->greaterThan(now()->addDays(90))) {
            throw ValidationException::withMessages(['scheduled_at' => 'Choose a time at least one minute ahead and within 90 days.']);
        }
        DB::transaction(function () use ($post, $data, $service, $when, $request): void {
            $post = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($post->publishingFingerprint(), $data['fingerprint'])) {
                throw ValidationException::withMessages(['post' => 'This post changed. Open a fresh publishing preview.']);
            }
            $service->create($post, (int) $data['social_account_id'], $when, $request->boolean('include_link'), options: $data['options'] ?? []);
        });

        return redirect()->route('schedules')->with('success', 'Post scheduled. The server scheduler will publish it when due.');
    }

    public function cancel(Request $request, PostSchedule $schedule): RedirectResponse
    {
        abort_unless($schedule->post->brand->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($schedule): void {
            Post::whereKey($schedule->post_id)->lockForUpdate()->firstOrFail();
            if (! PostSchedule::whereKey($schedule->id)->where('status', 'queued')->update(['status' => 'cancelled', 'reason' => 'Cancelled by owner.'])) {
                throw ValidationException::withMessages(['schedule' => 'Only a queued post can be cancelled. Check its publishing history.']);
            }
        });

        return back()->with('success', 'Schedule cancelled.');
    }

    public function image(Request $request, Post $post): StreamedResponse
    {
        abort_unless($post->brand->user_id === $request->user()->id && $post->image_path, 404);

        $index = $request->query('card');
        $path = $post->image_path;
        if ($index !== null) {
            abort_unless(ctype_digit((string) $index) && isset($post->card_images[(int) $index]), 404);
            $path = $post->card_images[(int) $index]['path'];
        }

        return Storage::disk('local')->response($path, 'post.png', ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    public function createImage(Request $request, Post $post, PostImage $images): RedirectResponse
    {
        abort_unless($post->brand->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($post, $images): void {
            $post = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            $post->assertEditable();
            try {
                $data = $images->create($post);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['image' => 'Image renderer unavailable. The server needs ImageMagick with Pango; configure HUB_IMAGE_CONVERT.']);
            }
            $post->schedules()->where('status', 'queued')->update(['status' => 'cancelled', 'reason' => 'Image changed.']);
            $post->forceFill($data + ['status' => 'draft', 'reviewed_at' => null, 'video_path' => null, 'video_hash' => null])->save();
        });

        return back()->with('success', 'Branded image created. Review the post and image together before publishing.');
    }
}
