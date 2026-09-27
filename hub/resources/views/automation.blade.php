@extends('layouts.app')
@section('title','Content automation')
@section('content')
<div class="page-heading"><div><h1>Application content → scheduled posts</h1><p class="muted">Each rule belongs to one application, platform and category. English is the automation default. Only flagged content waits for review.</p></div></div>
@php
$selectedBrand = $brands->firstWhere('id',(int)request('brand')) ?? $brands->first();
$channel = array_key_exists(request('channel','facebook'),\App\Models\Post::CHANNELS) && request('channel')!=='other' ? request('channel','facebook') : 'facebook';
$category = in_array(request('category'),['general','question','official']) ? request('category') : 'general';
$rule = $rules->first(fn($r)=>$r->brand_id===$selectedBrand?->id && $r->channel===$channel && $r->category===$category);
@endphp
<form method="get" class="filter"><label>Application<select name="brand">@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected($brand->id===$selectedBrand?->id)>{{ $brand->name }}</option>@endforeach</select></label><label>Platform<select name="channel">@foreach(\App\Models\Post::CHANNELS as $key=>$label)@if($key!=='other')<option value="{{ $key }}" @selected($channel===$key)>{{ $label }}</option>@endif @endforeach</select></label><label>Category<select name="category">@foreach(['general'=>'General content','question'=>'Questions / learning','official'=>'Verified official notices'] as $key=>$label)<option value="{{ $key }}" @selected($category===$key)>{{ $label }}</option>@endforeach</select></label><button class="button secondary">Open rule</button></form>
@if($selectedBrand)
<form class="panel form-panel" method="post" action="{{ route('automation.save') }}">@csrf
<h2>{{ $selectedBrand->name }} · {{ \App\Models\Post::CHANNELS[$channel] }} · {{ $category }}</h2>
<input type="hidden" name="brand_id" value="{{ $selectedBrand->id }}"><input type="hidden" name="channel" value="{{ $channel }}"><input type="hidden" name="category" value="{{ $category }}">
<label>Publishing account<select name="social_account_id" required><option value="">Choose a verified account</option>@foreach($accounts->where('brand_id',$selectedBrand->id)->where('provider',$channel) as $account)<option value="{{ $account->id }}" @selected(old('social_account_id',$rule?->social_account_id)==$account->id)>{{ $account->page_name ?: $account->page_id }}{{ $account->verified_at?'':' (not verified)' }}</option>@endforeach</select></label>
<div class="form-grid"><label>Minimum delay / spacing (minutes)<input type="number" min="5" max="10080" name="delay_minutes" value="{{ old('delay_minutes',$rule?->delay_minutes??30) }}" required></label><label>Maximum posts per UTC day for this rule<input type="number" min="1" max="20" name="daily_limit" value="{{ old('daily_limit',$rule?->daily_limit??3) }}" required></label></div>
<label class="checkbox"><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$rule?->enabled))>Enable automatic scheduling when checks pass</label>
<label class="checkbox"><input type="checkbox" name="trust_intake" value="1" @checked(old('trust_intake',$rule?->trust_intake))>Trust content sent with this application's connector token</label>
<label class="checkbox"><input type="checkbox" name="learn" value="1" @checked(old('learn',$rule?->learn??true))>Use this application's recent platform analytics to guide future content selection</label>
<label class="checkbox"><input type="checkbox" name="with_image" value="1" @checked(old('with_image',$rule?->with_image))>Create a branded image for incoming general/question content</label>
<p class="muted small">Existing drafts stay unchanged. You can assess a saved draft from its edit page. Official notices use Research source settings and evidence checks; this rule allows a verified first capture and controls its schedule. AI media that has not been reviewed remains held. Budget and channel validation still apply.</p>
@php($post = new \App\Models\Post(['channel'=>$channel]))
@if($rule?->options)<p class="notice">This rule has saved channel options. Re-enter the intended YouTube/WhatsApp options when updating it; saved recipient details are not printed here.</p>@endif
@include('publishing-options')
<button class="button">Save rule</button>
</form>
<div class="panel form-panel"><h2>Application connector</h2><p>Send content from your website's backend, CMS or question bank. The connector does not automatically gain access to your database. A website integration must call these endpoints. Keep the token on the server.</p>
@if(session('intake_token'))<div class="notice"><strong>Copy this token now (shown once):</strong><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{ session('intake_token') }}</pre></div>@endif
<form method="post" action="{{ route('automation.token',$selectedBrand) }}">@csrf<button class="button secondary">{{ $selectedBrand->intake_token_hash?'Rotate':'Create' }} application token</button></form>
<p>POST <code>{{ url('/api/v1/content') }}</code> with <code>Authorization: Bearer YOUR_TOKEN</code> and JSON:</p>
<pre style="white-space:pre-wrap">{"external_id":"question-123-v1", "channel":"{{ $channel }}", "category":"question", "title":"Question of the day", "body":"Approved question, options, correct answer and explanation...", "source_url":"https://your-website.example/question/123"}</pre>
<p>Use a unique revision ID and one request per platform. Repeating the same ID and content is safe. A changed item needs a new revision ID. Exam announcements/results need approved official evidence in <a href="{{ route('research') }}">Research</a>.</p>
<p>For analytics, POST <code>{{ url('/api/v1/events') }}</code> with the same authorization and <code>{"external_id":"unique-event-id", "publication_id":123, "name":"visit"}</code>. Names: visit, registration, conversion. Send no personal information. Your backend must deduplicate genuine events and associate the visit with the correct Content Hub publication.</p></div>
<form class="panel form-panel" method="post" action="{{ route('automation.content') }}">@csrf<h2>Add application content now</h2>
<input type="hidden" name="brand_id" value="{{ $selectedBrand->id }}"><input type="hidden" name="channel" value="{{ $channel }}"><input type="hidden" name="external_id" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Category<select name="category"><option value="general">General</option><option value="question">Question / learning</option></select></label>
<label>Title<input name="title" required maxlength="200"></label><label>Approved facts / question, answer and explanation<textarea name="body" required minlength="20" maxlength="12000" rows="6"></textarea></label><label>Destination URL<input name="source_url" type="url"></label>
<label class="checkbox"><input type="checkbox" name="approved" value="1">I approve these facts for this application</label><button class="button">Add to intake</button></form>
@endif
<div class="panel form-panel"><h2>Recent intake — all applications</h2>@forelse($items as $item)<div class="notice"><strong>{{ $brands->firstWhere('id',$item->brand_id)?->name }} · {{ $item->title }}</strong><p>{{ $item->channel }} · {{ $item->category }} · {{ $item->status }}</p><p>{{ $item->reason }}</p>@if($item->post_id)<a href="{{ route('posts.edit',$item->post_id) }}">Open draft / schedule</a>@elseif(in_array($item->status,['pending','held']))<form method="post" action="{{ route('automation.approve',$item) }}">@csrf<button class="button secondary">Approve facts and assess again</button></form>@endif</div>@empty<p>No content received yet.</p>@endforelse
@if($items->previousPageUrl())<a href="{{ $items->previousPageUrl() }}">Previous</a>@endif @if($items->nextPageUrl())<a href="{{ $items->nextPageUrl() }}">Next</a>@endif</div>
@endsection
