<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\SocialAccount;
use App\Services\Social\AccountSetup;
use App\Services\Social\FacebookClient;
use App\Services\Social\FacebookFailure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SocialAccountController extends Controller
{
    private function own(Request $request, SocialAccount $account): void
    {
        abort_unless($account->brand->user_id === $request->user()->id, 404);
    }

    public function index(Request $request): View
    {
        $accounts = SocialAccount::whereHas('brand', fn ($query) => $query->where('user_id', $request->user()->id))->with('brand')->latest()->get();
        $brands = Brand::where('user_id', $request->user()->id)->orderBy('name')->get();

        $providers = AccountSetup::PROVIDERS;
        $selected = $request->old('provider', $request->query('provider', 'facebook'));
        if (! is_string($selected) || ! array_key_exists($selected, $providers)) {
            $selected = 'facebook';
        }

        return view('social-accounts', compact('accounts', 'brands', 'providers', 'selected'));
    }

    public function store(Request $request): RedirectResponse
    {
        $provider = $request->input('provider', 'facebook');
        $request->merge(['provider' => $provider]);
        $request->validate(['provider' => ['required', 'string', Rule::in(array_keys(AccountSetup::PROVIDERS))]]);
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(AccountSetup::PROVIDERS))],
            'brand_id' => ['required', Rule::exists('brands', 'id')->where('user_id', $request->user()->id)],
            'page_id' => ['required', 'string', 'max:50', 'regex:'.AccountSetup::PROVIDERS[$provider]['id_pattern']],
        ] + AccountSetup::rules($provider), [
            'page_id.regex' => AccountSetup::PROVIDERS[$provider]['id_hint'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $brand = Brand::whereKey($data['brand_id'])->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            if (SocialAccount::where('brand_id', $brand->id)->where('provider', $data['provider'])->where('page_id', $data['page_id'])->exists()) {
                throw ValidationException::withMessages(['page_id' => 'This account is already saved for this application. Edit its setup below.']);
            }
            $account = new SocialAccount;
            $account->forceFill([
                'brand_id' => $brand->id, 'provider' => $data['provider'], 'page_id' => $data['page_id'],
                'display_name' => $data['display_name'] ?? null, 'access_token' => $data['access_token'] ?? null,
                'settings' => AccountSetup::settings($data['provider'], $data),
                'credential_version' => (string) Str::uuid(),
            ])->save();
        }, 5);

        return redirect()->route('social', ['provider' => $provider])->with('success', $provider === 'facebook'
            ? 'Page setup saved. Add a token and verify its identity before publishing.'
            : 'Account setup saved. Connection testing and posting will be added later. No external request was made.');
    }

    public function update(Request $request, SocialAccount $account): RedirectResponse
    {
        $this->own($request, $account);
        $data = $request->validate([
            'page_id' => ['sometimes', 'required', 'string', 'max:50', 'regex:'.AccountSetup::PROVIDERS[$account->provider]['id_pattern']],
        ] + AccountSetup::rules($account->provider), [
            'page_id.regex' => AccountSetup::PROVIDERS[$account->provider]['id_hint'],
        ]);
        DB::transaction(function () use ($account, $data): void {
            Brand::whereKey($account->brand_id)->lockForUpdate()->firstOrFail();
            $account = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (isset($data['page_id']) && $data['page_id'] !== $account->page_id) {
                if (SocialAccount::where('brand_id', $account->brand_id)->where('provider', $account->provider)
                    ->where('page_id', $data['page_id'])->whereKeyNot($account->id)->exists()) {
                    throw ValidationException::withMessages(['page_id' => 'This account is already saved for this application. Edit its setup below.']);
                }
                $account->page_id = $data['page_id'];
                $account->page_name = null;
                $account->verified_at = null;
                $account->error_code = null;
                $account->credential_version = (string) Str::uuid();
            }
            if (array_key_exists('display_name', $data)) {
                $account->display_name = $data['display_name'];
            }
            $settings = array_merge($account->settings ?? [], AccountSetup::settings($account->provider, $data));
            if ($settings !== ($account->settings ?? [])) {
                $account->settings = $settings;
                $account->credential_version = (string) Str::uuid();
                $account->verified_at = null;
                $account->error_code = null;
            }
            if (! empty($data['access_token'])) {
                $account->access_token = $data['access_token'];
                $account->credential_version = (string) Str::uuid();
                $account->verified_at = null;
                $account->error_code = null;
            }
            $account->save();
        }, 5);

        return back()->with('success', $account->provider === 'facebook'
            ? 'Setup saved. If you changed the Page ID or token, verify the Page again before publishing.'
            : 'Setup saved for later testing. Posting is not enabled for this account.');
    }

    public function verify(Request $request, SocialAccount $account, FacebookClient $client): RedirectResponse
    {
        $this->own($request, $account);
        if ($account->provider !== 'facebook') {
            return back()->withErrors(['connection' => 'This account is saved for later testing. Connection checks and posting are not enabled for this platform yet.']);
        }
        if (! $account->access_token) {
            throw ValidationException::withMessages(['access_token' => 'Save a Page access token first.']);
        }
        $name = null;
        $error = null;
        try {
            $name = $client->verify($account);
        } catch (FacebookFailure $failure) {
            $error = $failure->reason;
        } catch (\Throwable) {
            $error = 'response';
        }
        DB::transaction(function () use ($account, $name, $error): void {
            $current = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($current->credential_version !== $account->credential_version) {
                throw ValidationException::withMessages(['access_token' => 'The saved token changed during verification. Verify the current token again.']);
            }
            $current->verified_at = $error ? null : now();
            $current->error_code = $error;
            if ($name !== null) {
                $current->page_name = $name;
            }
            $current->save();
        }, 5);
        if ($error) {
            return back()->withErrors(['connection' => FacebookFailure::description($error)]);
        }

        return back()->with('success', 'Page identity verified: '.$name.'. Publishing still requires the appropriate Meta permissions.');
    }

    public function disconnect(Request $request, SocialAccount $account): RedirectResponse
    {
        $this->own($request, $account);
        DB::transaction(function () use ($account): void {
            $account = SocialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $account->forceFill([
                'access_token' => null, 'credential_version' => (string) Str::uuid(),
                'verified_at' => null, 'error_code' => null,
            ])->save();
        }, 5);

        return back()->with('success', 'Token removed. New submissions are blocked; submissions already started may still complete. Publishing history is preserved.');
    }
}
