<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\ContentSource;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\SocialAccount;
use App\Models\SourceSnapshot;
use App\Services\Research\FetchSource;
use App\Services\Research\ResearchSource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResearchController extends Controller
{
    public function index(Request $request): View
    {
        $brands = Brand::where('user_id', $request->user()->id)->orderBy('name')->get();
        $sources = ContentSource::whereIn('brand_id', $brands->pluck('id'))->with('brand')->latest()->get();
        $accounts = SocialAccount::whereIn('brand_id', $brands->pluck('id'))->whereIn('provider', ['facebook', 'instagram', 'linkedin', 'x'])->whereNotNull('verified_at')->get();
        $editing = $request->filled('edit') ? $sources->firstWhere('id', $request->integer('edit')) : null;
        $snapshots = SourceSnapshot::whereIn('content_source_id', $sources->pluck('id'))->with(['source.brand', 'post'])->latest()->paginate(12);

        return view('research', compact('brands', 'sources', 'accounts', 'editing', 'snapshots'));
    }

    public function save(Request $request, FetchSource $reader, ?ContentSource $source = null): RedirectResponse
    {
        if ($source) {
            abort_unless($source->brand->user_id === $request->user()->id, 404);
        }
        $data = $request->validate([
            'brand_id' => ['required', Rule::exists('brands', 'id')->where('user_id', $request->user()->id)],
            'name' => 'required|string|max:150', 'topic' => 'required|string|max:200',
            'url' => 'required|url:http,https|max:2048', 'comparison_url' => 'nullable|url:http,https|max:2048',
            'element_id' => ['nullable', 'string', 'max:150', 'regex:/^[a-zA-Z][a-zA-Z0-9_:.-]*$/D'],
            'channel' => ['required', Rule::in(array_keys(Post::CHANNELS))],
            'interval_minutes' => ['required', Rule::in([60, 180, 360, 1440])],
            'delay_minutes' => 'required|integer|min:15|max:10080',
            'social_account_id' => 'nullable|integer', 'enabled' => 'nullable|boolean',
            'auto_publish' => 'nullable|boolean', 'official' => 'nullable|boolean', 'with_image' => 'nullable|boolean',
            'media_kind' => ['nullable', Rule::in(['branded', 'image', 'video'])],
        ]);
        try {
            $reader->validate($data['url']);
            if ($data['comparison_url'] ?? null) {
                $reader->validate($data['comparison_url']);
            }
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['url' => 'Use public HTTP or HTTPS hostnames on port 80 or 443, without credentials or fragments.']);
        }
        if ($source && (int) $data['brand_id'] !== $source->brand_id) {
            throw ValidationException::withMessages(['brand_id' => 'Create a separate source for another application.']);
        }
        if (! empty($data['social_account_id']) && ! SocialAccount::whereKey($data['social_account_id'])->where('brand_id', $data['brand_id'])->where('provider', $data['channel'])->whereNotNull('verified_at')->exists()) {
            throw ValidationException::withMessages(['social_account_id' => 'Choose a verified account matching this channel and belonging to this application.']);
        }
        if ($request->boolean('auto_publish') && (! $request->boolean('official') || ! $request->boolean('enabled') || empty($data['social_account_id']) || ! in_array($data['channel'], ['facebook', 'instagram', 'linkedin', 'x'], true))) {
            throw ValidationException::withMessages(['auto_publish' => 'Automatic publishing requires an enabled, approved official source and a verified matching account. YouTube and WhatsApp require manual audience or recipient settings.']);
        }
        if ($request->boolean('auto_publish') && (parse_url($data['url'], PHP_URL_SCHEME) !== 'https'
            || (! empty($data['comparison_url']) && parse_url($data['comparison_url'], PHP_URL_SCHEME) !== 'https'))) {
            throw ValidationException::withMessages(['url' => 'Use HTTPS source and comparison URLs for automatic publishing.']);
        }
        unset($data['official']);
        $data['media_kind'] = $data['media_kind'] ?? 'branded';
        foreach (['enabled', 'auto_publish', 'with_image'] as $field) {
            $data[$field] = $request->boolean($field);
        }
        $data['approved_at'] = $request->boolean('official') ? now() : null;
        $data['version'] = (string) Str::uuid();
        $data['next_check_at'] = now();
        $data['last_hash'] = null;
        $data['last_error'] = null;
        DB::transaction(function () use ($source, $data): void {
            if ($source) {
                $locked = ContentSource::whereKey($source->id)->lockForUpdate()->firstOrFail();
                $locked->update($data);
                PostSchedule::whereIn('source_snapshot_id', $locked->snapshots()->select('id'))->where('status', 'queued')
                    ->update(['status' => 'cancelled', 'reason' => 'Source settings changed.']);
            } else {
                ContentSource::create($data);
            }
        }, 3);

        return redirect()->route('research')->with('success', 'Source saved. The first capture is a review draft; future changes follow your publishing settings.');
    }

    public function check(Request $request, ContentSource $source, ResearchSource $service): RedirectResponse
    {
        abort_unless($source->brand->user_id === $request->user()->id, 404);
        $service->run($source);

        return back()->with('success', 'Source check finished. See its status and evidence below.');
    }

    public function website(Request $request, Brand $brand, FetchSource $reader): RedirectResponse
    {
        abort_unless($brand->user_id === $request->user()->id, 404);
        if (! $brand->website) {
            return back()->withErrors(['website' => 'Set this application’s website URL first.']);
        }
        try {
            $text = $reader->fetch($brand->website);
            $brand->forceFill(['website_context' => mb_strcut($text, 0, 6000, 'UTF-8'), 'website_read_at' => now()])->save();
        } catch (\Throwable) {
            return back()->withErrors(['website' => 'Could not read the website. Use a public, readable page with a final URL, or enter brand details manually.']);
        }

        return back()->with('success', 'Website context saved for AI assistance. Review the captured text below.');
    }
}
