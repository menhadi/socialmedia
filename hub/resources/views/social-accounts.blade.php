@extends('layouts.app')
@section('title','Social accounts')
@section('content')
@if($application)@include('application-nav')@endif
@unless($application)
<form class="filter" method="get"><label for="account-brand">Application</label><select id="account-brand" name="brand"><option value="">Choose application</option>@foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select><button class="button secondary">Continue</button></form>
@endunless
<div class="page-heading"><div><div class="eyebrow">ACCOUNT CONNECTIONS</div><h1>{{ $editingAccount ? $providers[$selected]['name'].' account settings' : ($adding ? 'Add an account to '.$application->name : ($application?->name ?? 'Social').' connections') }}</h1><p class="muted">{{ $editingAccount ? ($editingAccount->display_name ?: $editingAccount->page_name ?: $application->name).' · '.$application->name : 'Choose a platform and connect your account.' }}</p></div>@if($application)<a class="button secondary" href="{{ route('applications.show',$application) }}">Back to accounts</a>@endif</div>
@unless($editingAccount)
<div class="platform-grid" aria-label="Choose a platform">
    @foreach($providers as $key=>$provider)
        <a class="platform-card {{ $selected===$key?'selected':'' }}" href="{{ ($adding ? route('applications.accounts.create', ['brand'=>$application->id,'provider'=>$key]) : route('social',['provider'=>$key,'brand'=>$application?->id])) }}" @if($selected===$key) aria-current="page" @endif><strong>{{ $provider['name'] }}</strong><small>{{ $provider['kind'] }}</small><span>Setup + publishing</span></a>
    @endforeach
</div>
@endunless
@php($setup = $providers[$selected])
@if($selected === 'x' && !$editingAccount)
    <div class="notice"><strong>Connect X with automatic renewal</strong>
        <p>Save the X account ID below, then use Connect X on that account. Sign in to the matching brand on X and review its permission screen. Each brand authorizes separately.</p>
        @unless(\App\Services\Social\XToken::configured())
            <p>Server setup required: configure X_CLIENT_ID, X_CLIENT_SECRET and X_REDIRECT_URI for your confidential Web App. Callback path: <code>/social-accounts/x/callback</code>.</p>
        @endunless
        <p>X API requests may require prepaid credits. Connecting an account does not enable automatic publishing or purchase credits.</p>
    </div>
@endif
@unless($editingAccount)
<details class="connect-account-details" @if($adding || $accounts->isEmpty() || $errors->any()) open @endif><summary>＋ Add {{ $setup['name'] }} account</summary><div class="editor-grid" id="account-setup">
    <section class="panel form-panel">
        <h2>Add {{ $setup['name'] }} account</h2>
        <p class="muted small">{{ $setup['description'] }}</p>
        @if($brands->isEmpty())
            <p>Add an application before saving an account.</p><a class="button" href="{{ route('applications.create') }}">Add application</a>
        @else
            <form method="post" action="{{ ($application ? route('applications.accounts.store',$application) : route('social.store')) }}">
                @csrf
                <input type="hidden" name="provider" value="{{ $selected }}">
                @if($application)<input type="hidden" name="brand_id" value="{{ $application->id }}"><p class="muted small">Application: <strong>{{ $application->name }}</strong></p>@else<label>Application<select name="brand_id" required><option value="">Choose an application</option>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(old('brand_id',$application?->id)==$brand->id)>{{ $brand->name }}</option>@endforeach</select></label>@endif
                <label>{{ $setup['id_label'] }}<input name="page_id" required maxlength="50" value="{{ old('page_id') }}" placeholder="{{ $selected==='linkedin'?'urn:li:organization:123456':($selected==='youtube'?'UC…':'Numeric account ID') }}"></label>
                <p class="muted small">{{ $setup['id_hint'] }}</p>
                @include('social-account-fields',['platform'=>$selected,'setup'=>$setup,'editing'=>false,'account'=>null])
                <button class="button">Save account setup</button>
            </form>
        @endif
    </section>
    <aside>
        <div class="notice"><strong>{{ $setup['name'] }} setup</strong><p>{{ $setup['help'] }}</p><p><a href="{{ $setup['docs'] }}" target="_blank" rel="noopener noreferrer">Official setup documentation ↗</a></p></div>
        <div class="notice">
            <strong>Verify when you are ready</strong>
            <p>Save a token, then choose Verify account. This checks identity without posting. Publishing still requires the platform’s write permissions. Access tokens can expire; update and verify them again when needed.</p>
            <p>Saving makes no external request. Tokens are encrypted and never displayed. Use API access tokens, not your account password.</p>
        </div>
    </aside>
</div>
</details>
@endunless
@unless($adding)
@if(!$editingAccount)<div class="section-heading ai-history-heading"><h2>Your saved accounts</h2><span class="muted small">{{ $accounts->count() }} saved</span></div>@endif
<div class="{{ $editingAccount ? 'account-settings-single' : 'cards' }}">
@forelse($accounts as $account)
    @php($accountSetup = $providers[$account->provider])
    <section class="panel provider-card" id="account-{{ $account->id }}">
        <div class="section-heading"><h2>{{ $account->display_name ?: ($account->page_name ?: $accountSetup['name']) }}</h2><span class="badge {{ $account->verified_at?'reviewed':'' }}">{{ $account->verified_at?'Identity verified':'Not tested' }}</span></div>
        <p class="muted small">{{ $account->brand->name }} · {{ $accountSetup['name'] }} · {{ $accountSetup['kind'] }}</p>
        <p><a href="{{ route('posts',['brand'=>$account->brand_id,'channel'=>$account->provider,'account'=>$account->id]) }}">View {{ $accountSetup['name'] }} posts</a></p><p class="small account-identifier">{{ $accountSetup['id_label'] }}: <strong>{{ $account->page_id }}</strong></p>
        <div class="tags"><span>{{ ($account->access_token || $account->oauth_credentials)?'Credentials saved':'Awaiting credentials' }}</span><span>Publishing available</span></div>
        @if($account->provider === 'x')
            @if($account->oauth_credentials)<p class="small">Automatic token renewal enabled. If access is revoked, reconnect here.</p>@endif
            <form method="post" action="{{ route('social.x.connect', $account) }}">@csrf<button class="button secondary" @disabled(! \App\Services\Social\XToken::configured())>{{ $account->oauth_credentials ? 'Reconnect X' : 'Connect X' }}</button></form>
            <p class="muted small">Allows reading account/post information, publishing posts and media, and renewing access until revoked. Reconnecting changes credentials; check existing publishing schedules afterward.</p>
        @endif
        @if($account->error_code)<div class="notice error">{{ \App\Services\Social\FacebookFailure::description($account->error_code) }}</div>@endif
        @if($account->access_token || $account->oauth_credentials)
            <form method="post" action="{{ route('social.verify',$account) }}">@csrf<button class="button secondary">Verify account</button></form>
        @endif
        <details class="usage-note" @if($editingAccount) open @endif>
            <summary>Edit saved setup</summary>
            <form method="post" action="{{ route('social.update',$account) }}">
                @csrf @method('PUT')
                <label>{{ $accountSetup['id_label'] }}<input name="page_id" required maxlength="50" value="{{ $account->page_id }}"></label>
                <p class="muted small">{{ $accountSetup['id_hint'] }} Changing the ID resets verification. A blank token field keeps the saved token; make sure it belongs to the corrected account.</p>
                @include('social-account-fields',['platform'=>$account->provider,'setup'=>$accountSetup,'editing'=>true,'account'=>$account])
                <button class="button secondary">Save changes</button>
            </form>
        </details>
        @if($account->access_token || $account->oauth_credentials)
            <details class="usage-note"><summary>Remove saved token</summary><p class="muted small">Clears the token from this workspace. The account details and publishing history remain. Any platform submission already started may still finish.</p><form method="post" action="{{ route('social.disconnect',$account) }}">@csrf @method('DELETE')<button class="button secondary">Remove saved token</button></form></details>
        @endif
    </section>
@empty
    <div class="panel empty wide"><h3>No accounts saved yet.</h3><p>Choose a platform above and add its details when you are ready.</p></div>
@endforelse
</div>
@endunless
@endsection
