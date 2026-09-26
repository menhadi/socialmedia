@extends('layouts.app')
@section('title','Social accounts')
@section('content')
<div class="page-heading"><div><div class="eyebrow">CONNECT YOUR CHANNELS</div><h1>Every account. One workspace.</h1><p class="muted">Save social account details for each application. Add credentials and test when you are ready.</p></div></div>
<div class="platform-grid" aria-label="Choose a platform">
    @foreach($providers as $key=>$provider)
        <a class="platform-card {{ $selected===$key?'selected':'' }}" href="{{ route('social',['provider'=>$key]) }}" @if($selected===$key) aria-current="page" @endif><strong>{{ $provider['name'] }}</strong><small>{{ $provider['kind'] }}</small><span>{{ $key==='facebook'?'Setup + publishing':'Account setup' }}</span></a>
    @endforeach
</div>
@php($setup = $providers[$selected])
<div class="editor-grid" id="account-setup">
    <section class="panel form-panel">
        <h2>Add {{ $setup['name'] }} account</h2>
        <p class="muted small">{{ $setup['description'] }}</p>
        @if($brands->isEmpty())
            <p>Add an application before saving an account.</p><a class="button" href="{{ route('applications.create') }}">Add application</a>
        @else
            <form method="post" action="{{ route('social.store') }}">
                @csrf
                <input type="hidden" name="provider" value="{{ $selected }}">
                <label>Application<select name="brand_id" required><option value="">Choose an application</option>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(old('brand_id')==$brand->id)>{{ $brand->name }}</option>@endforeach</select></label>
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
            <strong>{{ $selected==='facebook'?'Verify when you are ready':'Setup now, test later' }}</strong>
            @if($selected==='facebook')
                <p>After saving a Page token, choose Verify Page below. This checks the Page identity without posting. Publishing permissions still need to be available.</p>
            @else
                <p>These details are saved locally. Connection checks and posting for {{ $setup['name'] }} are not enabled yet.</p>
            @endif
            <p>Saving makes no external request. Tokens are encrypted and never displayed. Use API access tokens, not your account password.</p>
        </div>
    </aside>
</div>
<div class="section-heading ai-history-heading"><h2>Your saved accounts</h2><span class="muted small">{{ $accounts->count() }} saved</span></div>
<div class="cards">
@forelse($accounts as $account)
    @php($accountSetup = $providers[$account->provider])
    <section class="panel provider-card">
        <div class="section-heading"><h2>{{ $account->display_name ?: ($account->page_name ?: $accountSetup['name']) }}</h2><span class="badge {{ $account->provider==='facebook' && $account->verified_at?'reviewed':'' }}">{{ $account->provider==='facebook' && $account->verified_at?'Identity verified':'Not tested' }}</span></div>
        <p class="muted small">{{ $account->brand->name }} · {{ $accountSetup['name'] }} · {{ $accountSetup['kind'] }}</p>
        <p class="small account-identifier">{{ $accountSetup['id_label'] }}: <strong>{{ $account->page_id }}</strong></p>
        <div class="tags"><span>{{ $account->access_token?'Credentials saved':'Awaiting credentials' }}</span><span>{{ $account->provider==='facebook'?'Facebook publishing available':'Posting not enabled' }}</span></div>
        @if($account->provider==='facebook' && $account->error_code)<div class="notice error">{{ \App\Services\Social\FacebookFailure::description($account->error_code) }}</div>@endif
        @if($account->provider==='facebook' && $account->access_token)
            <form method="post" action="{{ route('social.verify',$account) }}">@csrf<button class="button secondary">Verify Page</button></form>
        @endif
        <details class="usage-note">
            <summary>Edit saved setup</summary>
            <form method="post" action="{{ route('social.update',$account) }}">
                @csrf @method('PUT')
                <label>{{ $accountSetup['id_label'] }}<input name="page_id" required maxlength="50" value="{{ $account->page_id }}"></label>
                <p class="muted small">{{ $accountSetup['id_hint'] }} Changing the ID resets verification. A blank token field keeps the saved token; make sure it belongs to the corrected account.</p>
                @include('social-account-fields',['platform'=>$account->provider,'setup'=>$accountSetup,'editing'=>true,'account'=>$account])
                <button class="button secondary">Save changes</button>
            </form>
        </details>
        @if($account->access_token)
            <details class="usage-note"><summary>Remove saved token</summary><p class="muted small">Clears the token from this workspace. The account details and publishing history remain. Any Facebook submission already started may still finish.</p><form method="post" action="{{ route('social.disconnect',$account) }}">@csrf @method('DELETE')<button class="button secondary">Remove saved token</button></form></details>
        @endif
    </section>
@empty
    <div class="panel empty wide"><h3>No accounts saved yet.</h3><p>Choose a platform above and add its details when you are ready.</p></div>
@endforelse
</div>
@endsection
