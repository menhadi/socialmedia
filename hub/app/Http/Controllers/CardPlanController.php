<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\Ai\CardPlanner;
use App\Services\Ai\GenerateContent;
use App\Services\Research\ContentVisual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CardPlanController extends Controller
{
    public function __invoke(Request $request, Post $post, GenerateContent $ai, CardPlanner $planner): RedirectResponse
    {
        abort_unless($post->brand->user_id === $request->user()->id, 404);
        $post->assertEditable();
        $request->validate(['request_key' => 'required|uuid', 'fingerprint' => 'required|string|size:64']);
        $fingerprint = $post->publishingFingerprint();
        if (! hash_equals($fingerprint, $request->string('fingerprint')->toString()) || ! $post->card_sources) {
            throw ValidationException::withMessages(['cards' => 'Save source cards and open the current post before asking AI to plan.']);
        }
        $generation = $ai->run($request->user(), $post->brand, ['request_key' => $request->input('request_key'), 'task' => 'card_plan', 'title' => $post->title, 'channel' => $post->channel, 'language' => 'English', 'source_text' => json_encode(['approved_content' => $post->body, 'source_cards' => $post->card_sources, 'max_cards' => CardPlanner::limit($post->channel)], JSON_THROW_ON_ERROR)]);
        $plan = json_decode($generation->result ?? '', true);
        if ($generation->status !== 'completed' || ! is_array($plan)) {
            throw ValidationException::withMessages(['cards' => 'AI planning did not complete. Check provider limits and generation history.']);
        }
        $visual = $planner->select($post->card_sources, $plan, $post->channel);
        $body = app(ContentVisual::class)->caption($planner->caption($post->body, $plan), $visual);
        if ($post->brand->pyp_only) {
            app(ContentVisual::class)->assertPreviousYearQuestion($visual, $post->source_url);
        }
        DB::transaction(function () use ($post, $visual, $body, $plan, $fingerprint, $generation): void {
            $current = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            $current->assertEditable();
            if (! hash_equals($fingerprint, $current->publishingFingerprint())) {
                throw ValidationException::withMessages(['cards' => 'Post changed while AI was planning. The result was not applied.']);
            }
            $current->schedules()->where('status', 'queued')->update(['status' => 'cancelled', 'reason' => 'AI changed the card plan.']);
            $current->forceFill(['visual' => $visual, 'body' => $body, 'card_plan_note' => $plan['reason'], 'card_images' => null, 'image_path' => null, 'image_hash' => null, 'video_path' => null, 'video_hash' => null, 'status' => 'draft', 'reviewed_at' => null])->save();
            $generation->forceFill(['post_id' => $post->id])->save();
        });

        return back()->with('success', 'AI selected and ordered the cards. Generate the complete set, then review or assess for automatic scheduling.');
    }
}
