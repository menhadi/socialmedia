<?php

namespace App\Http\Controllers;

use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Automation\RunAutomation;
use App\Services\Social\ChannelRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AutomationController extends Controller
{
    public function index(Request $r): View
    {
        $brands = Brand::where('user_id', $r->user()->id)->get();

        return view('automation', ['brands' => $brands, 'accounts' => SocialAccount::whereIn('brand_id', $brands->pluck('id'))->get(), 'rules' => AutomationRule::whereIn('brand_id', $brands->pluck('id'))->get(), 'items' => ContentItem::whereIn('brand_id', $brands->pluck('id'))->latest()->paginate(20)]);
    }

    public function save(Request $r): RedirectResponse
    {
        $brand = Brand::where('user_id', $r->user()->id)->findOrFail($r->integer('brand_id'));
        $data = $r->validate(['channel' => ['required', Rule::in(['facebook', 'instagram', 'linkedin', 'x', 'youtube', 'whatsapp'])], 'category' => 'required|in:general,question,official', 'social_account_id' => ['required', Rule::exists('social_accounts', 'id')->where('brand_id', $brand->id)->where('provider', $r->input('channel'))], 'delay_minutes' => 'required|integer|min:5|max:10080', 'daily_limit' => 'required|integer|min:1|max:20']);
        $options = ChannelRules::options($data['channel'], $r->input('options', []));
        $workflow = $r->validate(['workflow' => 'sometimes|required|in:review,automatic', 'media_kind' => 'sometimes|required|in:none,image,video', 'aspect_ratio' => 'sometimes|required|in:16:9,9:16,1:1', 'question_difficulty' => 'sometimes|required|in:any,hard']);
        if (($workflow['media_kind'] ?? '') === 'video' && ($workflow['aspect_ratio'] ?? '') === '1:1') {
            throw ValidationException::withMessages(['aspect_ratio' => 'Choose landscape or portrait for video.']);
        }
        $options += $workflow + ['workflow' => 'review', 'media_kind' => 'none', 'aspect_ratio' => '16:9'];
        DB::transaction(function () use ($brand, $data, $r, $options): void {
            Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $rule = AutomationRule::firstOrNew(['brand_id' => $brand->id, 'channel' => $data['channel'], 'category' => $data['category']]);
            $rule->fill($data + ['enabled' => $r->boolean('enabled'), 'trust_intake' => $r->boolean('trust_intake'), 'learn' => $r->boolean('learn'), 'with_image' => $r->boolean('with_image'), 'options' => $options, 'version' => ($rule->version ?? 0) + 1])->save();
        });

        return back()->with('success', 'Automation rule saved. Existing schedules under older rules will be held; existing drafts are not automatically published.');
    }

    public function assess(Request $r, Post $post, RunAutomation $runner): RedirectResponse
    {
        abort_unless($post->brand->user_id === $r->user()->id, 404);
        $r->validate(['category' => 'required|in:general,question', 'confirmed' => 'accepted']);
        $post->assertEditable();
        $runner->assess($post, $r->string('category')->toString());

        return back()->with('success', $post->fresh()->automation_reason ?? 'Assessment complete.');
    }

    public function approve(Request $r, ContentItem $item): RedirectResponse
    {
        abort_unless(Brand::whereKey($item->brand_id)->where('user_id', $r->user()->id)->exists(), 404);
        abort_unless(in_array($item->status, ['pending', 'held']) && ! $item->post_id, 422);
        $item->update(['approved' => true, 'status' => 'pending', 'reason' => null]);

        return back()->with('success', 'Content approved for assessment.');
    }

    public function archive(Request $r, Post $post): RedirectResponse
    {
        abort_unless($post->brand->user_id === $r->user()->id, 404);
        DB::transaction(function () use ($post): void {
            $post = Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            abort_if($post->publications()->whereIn('status', ['publishing', 'uncertain'])->exists() || $post->schedules()->whereIn('status', ['running', 'processing', 'uncertain'])->exists(), 422, 'Resolve the active or uncertain publication before archiving.');
            $post->schedules()->where('status', 'queued')->update(['status' => 'cancelled', 'reason' => 'Post archived in Content Hub.']);
            $post->forceFill(['archived_at' => $post->archived_at ? null : now()])->save();
        });

        return back()->with('success', 'Archive state updated in Content Hub only. No platform post was deleted. Restoring does not requeue publishing.');
    }
}
