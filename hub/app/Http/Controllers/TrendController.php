<?php

namespace App\Http\Controllers;

use App\Models\AutomationRule;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\TrendRun;
use App\Services\Research\FetchSource;
use App\Services\Trends\GenerateTrendDraft;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrendController extends Controller
{
    public function index(Request $request): View
    {
        $brands = Brand::where('user_id', $request->user()->id)->orderBy('name')->get();
        $application = $request->filled('brand') ? $brands->firstWhere('id', $request->integer('brand')) : $brands->first();
        abort_if($request->filled('brand') && ! $application, 404);
        $runs = TrendRun::where('brand_id', $application?->id)->with('post')->latest()->paginate(12)->withQueryString();

        $accounts = SocialAccount::where('brand_id', $application?->id)->orderBy('provider')->get();
        $rules = AutomationRule::where('brand_id', $application?->id)->where('category', 'trend')->get();

        return view('trends', compact('brands', 'application', 'runs', 'accounts', 'rules'));
    }

    public function saveAccount(Request $request, SocialAccount $account, FetchSource $reader): RedirectResponse
    {
        $brand = $account->brand;
        abort_unless($brand->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'enabled' => 'nullable|boolean', 'workflow' => 'required|in:review,automatic',
            'region' => ['required', 'regex:/^[A-Z]{2}$/D'], 'keywords' => 'required|string|max:500',
            'timezone' => 'required|timezone', 'daily_time' => 'required|date_format:H:i',
            'window_start' => 'required|date_format:H:i', 'window_end' => 'required|date_format:H:i|after:window_start',
            'preferred_time' => 'required|date_format:H:i|after_or_equal:window_start|before_or_equal:window_end',
            'landing_pages' => 'required|string|max:6500', 'hashtags' => 'nullable|string|max:200',
            'woeid' => 'nullable|integer|min:1|max:2147483647', 'minimum_views' => 'nullable|integer|min:1|max:1000000000',
            'media_kind' => 'required|in:none,image,video', 'made_for_kids' => 'nullable|boolean',
        ]);
        if ($request->boolean('enabled') && (! $account->verified_at || ! $account->access_token || ($brand->pyp_only && ! $brand->trend_posts_allowed)
            || ! in_array($account->provider, ['facebook', 'instagram', 'x', 'youtube'], true))) {
            throw ValidationException::withMessages(['enabled' => 'Enable only a verified account with supported platform discovery and an application that permits trend content.']);
        }
        if ($account->provider === 'youtube' && $data['media_kind'] !== 'video') {
            throw ValidationException::withMessages(['media_kind' => 'YouTube requires an original video. Configure a video provider and budget in Media first.']);
        }
        if ($account->provider === 'instagram' && ! ($data['hashtags'] ?? null)) {
            throw ValidationException::withMessages(['hashtags' => 'Enter 1–3 relevant hashtags for Instagram discovery.']);
        }
        $pages = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', $data['landing_pages'])))));
        if (count($pages) < 1 || count($pages) > 3 || ! $brand->website || array_filter(array_map('trim', explode(',', $data['keywords']))) === []) {
            throw ValidationException::withMessages(['landing_pages' => 'Set the application website, topic keywords and 1–3 public website page URLs.']);
        }
        foreach ($pages as $url) {
            try {
                if (strtolower($reader->validate($url)) !== strtolower(parse_url($brand->website, PHP_URL_HOST) ?? '')) {
                    throw new \RuntimeException;
                }
            } catch (\RuntimeException) {
                throw ValidationException::withMessages(['landing_pages' => 'Each page must be a public URL on the configured website hostname, without credentials or fragments.']);
            }
        }
        $settings = array_intersect_key($data, array_flip(['region', 'keywords', 'timezone', 'daily_time', 'window_start', 'window_end', 'preferred_time', 'hashtags', 'woeid', 'minimum_views']));
        $settings += ['woeid' => 23424848, 'minimum_views' => 100];
        $settings['woeid'] = $settings['woeid'] ?: 23424848;
        $settings['minimum_views'] = $settings['minimum_views'] ?: 100;
        $settings += ['version' => (string) Str::uuid(), 'channel' => $account->provider, 'landing_pages' => $pages];
        DB::transaction(function () use ($brand, $account, $request, $data, $settings): void {
            Brand::whereKey($brand->id)->lockForUpdate()->firstOrFail();
            $rule = AutomationRule::firstOrNew(['brand_id' => $brand->id, 'channel' => $account->provider, 'category' => 'trend']);
            $rule->fill(['social_account_id' => $account->id, 'enabled' => $request->boolean('enabled'),
                'delay_minutes' => 60, 'daily_limit' => 1, 'trust_intake' => false, 'learn' => true,
                'with_image' => $data['media_kind'] === 'image', 'version' => ($rule->version ?? 0) + 1,
                'options' => ['workflow' => $data['workflow'], 'media_kind' => $data['media_kind'],
                    'made_for_kids' => $request->boolean('made_for_kids'), 'trend' => $settings]])->save();
        });

        return redirect()->route('trends', ['brand' => $brand->id])->with('success', 'Platform trend settings saved. Older schedules will be held. Eligible new posts follow the selected review or automatic workflow.');
    }

    public function generateAccount(Request $request, AutomationRule $rule, GenerateTrendDraft $service): RedirectResponse
    {
        $brand = Brand::where('user_id', $request->user()->id)->findOrFail($rule->brand_id);
        abort_unless($rule->category === 'trend', 404);
        if (! $rule->enabled) {
            return back()->withErrors(['enabled' => 'Enable this platform trend rule first.']);
        }
        $service->run($brand, $rule, retryDiscovery: true);

        return redirect()->route('trends', ['brand' => $brand->id])->with('success', 'Platform check finished. An eligible automatic post is scheduled inside its audience window; held or skipped attempts are shown below.');
    }

    public function save(Request $request, Brand $brand, FetchSource $reader): RedirectResponse
    {
        abort_unless($brand->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'enabled' => 'nullable|boolean', 'region' => ['required', 'string', 'regex:/^[A-Z]{2}$/D'],
            'keywords' => 'required|string|max:500', 'feed_url' => 'nullable|url:http,https|max:2048',
            'channel' => ['required', Rule::in(array_keys(Post::CHANNELS))],
            'timezone' => 'required|timezone', 'daily_time' => 'required|date_format:H:i',
            'landing_pages' => 'required|string|max:6500',
        ]);
        if ($request->boolean('enabled') && $brand->pyp_only && ! $brand->trend_posts_allowed) {
            throw ValidationException::withMessages(['enabled' => 'This application permits only sourced previous-year questions. Allow the website-grounded trend exception in application settings before enabling trend posts.']);
        }
        $pages = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', $data['landing_pages'])))));
        if (count($pages) < 1 || count($pages) > 3 || ! $brand->website) {
            throw ValidationException::withMessages(['landing_pages' => 'Set the application website and enter 1–3 public page URLs, one per line.']);
        }
        $websiteHost = strtolower(parse_url($brand->website, PHP_URL_HOST) ?? '');
        foreach ($pages as $url) {
            try {
                if (strtolower($reader->validate($url)) !== $websiteHost) {
                    throw new \RuntimeException;
                }
            } catch (\RuntimeException) {
                throw ValidationException::withMessages(['landing_pages' => 'Each page must be a public URL on the configured website hostname, without credentials or fragments.']);
            }
        }
        if ($data['feed_url'] ?? null) {
            try {
                $reader->validate($data['feed_url']);
            } catch (\RuntimeException) {
                throw ValidationException::withMessages(['feed_url' => 'Use a public RSS or Atom feed URL without credentials or fragments.']);
            }
        }
        if (array_filter(array_map('trim', explode(',', $data['keywords']))) === []) {
            throw ValidationException::withMessages(['keywords' => 'Enter at least one topic keyword.']);
        }
        $brand->forceFill(['trend_settings' => [
            'enabled' => $request->boolean('enabled'), 'version' => (string) Str::uuid(), 'region' => $data['region'],
            'keywords' => $data['keywords'], 'feed_url' => $data['feed_url'] ?? null,
            'channel' => $data['channel'], 'timezone' => $data['timezone'], 'daily_time' => $data['daily_time'], 'landing_pages' => $pages,
        ]])->save();

        return redirect()->route('trends', ['brand' => $brand->id])->with('success', 'Trend settings saved. One daily draft can be generated for review; existing posts continue separately.');
    }

    public function generate(Request $request, Brand $brand, GenerateTrendDraft $service): RedirectResponse
    {
        abort_unless($brand->user_id === $request->user()->id, 404);
        if (! ($brand->trend_settings['enabled'] ?? false)) {
            return back()->withErrors(['enabled' => 'Save and enable trend settings first.']);
        }
        $service->run($brand);

        return redirect()->route('trends', ['brand' => $brand->id])->with('success', 'Daily trend check finished. See the result and evidence below. Each website has one attempt per local day.');
    }
}
