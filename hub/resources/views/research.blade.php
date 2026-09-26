@extends('layouts.app')
@section('title','Research & automation')
@section('content')
<div class="page-heading"><div><h1>From source to scheduled post.</h1><p class="muted">Connect websites, follow official notices and prepare content for each application.</p></div><a class="button secondary" href="{{ route('schedules') }}">Publishing queue</a></div>
<div class="notice">Sources are checked on the server schedule. Automatic publishing is available for Facebook. Other channels produce drafts. A website URL reads that page only; add the specific notice pages, feeds or PDFs you want to follow.</div>
<details class="panel form-panel"><summary><strong>Read an application’s website</strong></summary>
@forelse($brands as $brand)
<div class="section-heading"><div><h3>{{ $brand->name }}</h3><p class="muted small">{{ $brand->website ?: 'Set a website in Applications first.' }}</p></div>
<form method="post" action="{{ route('research.website',$brand) }}">@csrf<button class="button secondary" @disabled(!$brand->website)>Read website</button></form></div>
@if($brand->website_context)<details><summary>Captured website context · {{ $brand->website_read_at }} UTC</summary><p class="post-body">{{ $brand->website_context }}</p></details>@endif
@empty<p>Add an application first.</p>@endforelse
</details>
<section class="panel form-panel">
<h2>{{ $editing ? 'Edit source' : 'Add a source' }}</h2>
<p class="muted">For exam notifications and results, use the issuing authority’s exact notice page. Add a source for each exam or subject you want to follow.</p>
<form method="post" action="{{ $editing ? route('research.update',$editing) : route('research.store') }}">
@csrf @if($editing) @method('PUT') @endif
<div class="form-grid">
<label>Application<select name="brand_id" required>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(old('brand_id',$editing?->brand_id)==$brand->id)>{{ $brand->name }}</option>@endforeach</select></label>
<label>Source name<input name="name" maxlength="150" required value="{{ old('name',$editing?->name) }}" placeholder="Exam authority · notifications"></label>
</div>
<label>Topic / exam to follow<input name="topic" maxlength="200" required value="{{ old('topic',$editing?->topic) }}" placeholder="Exam name, notification or result updates"></label>
<label>Source URL<input name="url" type="url" maxlength="2048" required value="{{ old('url',$editing?->url) }}" placeholder="https://official-website.example/notices"></label>
<label>Comparison URL <span class="muted small">optional · your existing article or another authoritative page</span><input name="comparison_url" type="url" maxlength="2048" value="{{ old('comparison_url',$editing?->comparison_url) }}"></label>
<label>Content element ID <span class="muted small">optional · limits HTML reading to a specific section</span><input name="element_id" maxlength="150" value="{{ old('element_id',$editing?->element_id) }}" placeholder="notifications"></label>
<div class="form-grid">
<label>Channel<select name="channel">@foreach(\App\Models\Post::CHANNELS as $key=>$label)<option value="{{ $key }}" @selected(old('channel',$editing?->channel ?? 'facebook')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Check every<select name="interval_minutes">@foreach([60=>'Hour',180=>'3 hours',360=>'6 hours',1440=>'Day'] as $value=>$label)<option value="{{ $value }}" @selected(old('interval_minutes',$editing?->interval_minutes ?? 60)==$value)>{{ $label }}</option>@endforeach</select></label>
<label>Automatic publishing delay (minutes)<input name="delay_minutes" type="number" min="15" max="10080" required value="{{ old('delay_minutes',$editing?->delay_minutes ?? 60) }}"></label>
<label>Automatic destination<select name="social_account_id"><option value="">Choose a verified Facebook Page</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('social_account_id',$editing?->social_account_id)==$account->id)>{{ $account->brand->name }} · {{ $account->page_name }}</option>@endforeach</select></label>
</div>
<label class="checkbox"><input name="with_image" type="checkbox" value="1" @checked(old('with_image',$editing?->with_image ?? true))>Create a branded title image with each draft</label>
<label class="checkbox"><input name="enabled" type="checkbox" value="1" @checked(old('enabled',$editing?->enabled ?? false))>Enable recurring source checks and AI drafting</label>
<label class="checkbox"><input name="official" type="checkbox" value="1" @checked(old('official',(bool)$editing?->approved_at))>I have checked this URL and approve it as an official source for this topic</label>
<label class="checkbox"><input name="auto_publish" type="checkbox" value="1" @checked(old('auto_publish',$editing?->auto_publish ?? false))>Automatically publish eligible updates to the selected Page</label>
<p class="muted small">The first capture stays in review. Later changes can publish exact headline and excerpt quotations when the notice date is within seven days, the evidence matches, and no concerns are flagged. AI rewrites and translations require review. Comparison pages must contain the same quotes for automatic publishing. Sources are checked again before submission. Images are branded title cards, not AI illustrations.</p>
<p class="muted small">Choose an enabled AI provider with a budget in the application settings. Each changed source can use one AI request. Saving source settings cancels its queued automatic posts.</p>
<button class="button">{{ $editing ? 'Save source changes' : 'Add source' }}</button>
@if($editing)<a class="button secondary" href="{{ route('research') }}">Cancel editing</a>@endif
</form>
</section>
<h2>Your sources</h2>
@forelse($sources as $source)
<section class="panel form-panel"><div class="section-heading"><div><h3>{{ $source->name }}</h3><p class="muted">{{ $source->brand->name }} · {{ $source->topic }}</p></div><span class="badge">{{ !$source->enabled ? 'Paused' : ($source->auto_publish ? 'Automatic publishing' : 'Drafts only') }}</span></div>
<a class="source-link" href="{{ $source->url }}" target="_blank" rel="noopener noreferrer">{{ $source->url }}</a>
<p class="muted small">Last checked: {{ $source->checked_at?->format('d M Y H:i').' UTC' ?: 'Not checked' }} · {{ $source->approved_at ? 'Owner-approved official source' : 'Not approved for automatic publishing' }}</p>
@if($source->last_error)<p class="notice error">{{ $source->last_error }}</p>@endif
<div class="actions"><a class="button secondary" href="{{ route('research',['edit'=>$source->id]) }}">Edit settings</a><form method="post" action="{{ route('research.check',$source) }}">@csrf<button class="button secondary">Check now & prepare draft</button></form></div>
</section>
@empty<div class="panel empty">No sources connected yet.</div>@endforelse
<h2>Research history</h2>
@forelse($snapshots as $snapshot)
<section class="panel form-panel"><div class="section-heading"><h3>{{ $snapshot->source->name }}</h3><span class="badge">{{ ucfirst($snapshot->status) }}</span></div>
<p class="muted small">Captured {{ $snapshot->checked_at->format('d M Y H:i') }} UTC @if($snapshot->rechecked_at) · Rechecked before publishing {{ $snapshot->rechecked_at->format('d M Y H:i') }} UTC @endif</p>
<p>{{ $snapshot->reason }}</p>
@if($snapshot->post)<a class="button secondary" href="{{ route('posts.edit',$snapshot->post) }}">Open post & image</a>@endif
@if($snapshot->package)
<details><summary>AI suggestion and evidence selections</summary>
<h4>Suggested caption</h4><p class="post-body">{{ $snapshot->package['caption'] ?? '' }}</p>
<h4>Source quotes</h4><blockquote>{{ $snapshot->package['headline_quote'] ?? '' }}<br>{{ $snapshot->package['excerpt_quote'] ?? '' }}</blockquote>
<p>Notice date: {{ $snapshot->package['date_text'] ?? 'Not found' }}</p>
@foreach(($snapshot->package['concerns'] ?? []) as $concern) @if(is_string($concern))<p class="notice">{{ $concern }}</p>@endif @endforeach
</details>
@endif
<details><summary>Captured source evidence</summary><a href="{{ $snapshot->url }}" target="_blank" rel="noopener noreferrer">Open source</a><p class="post-body">{{ $snapshot->text }}</p>
@if($snapshot->comparison_text)<h4>Comparison evidence</h4><a href="{{ $snapshot->comparison_url }}" target="_blank" rel="noopener noreferrer">Open comparison</a><p class="post-body">{{ $snapshot->comparison_text }}</p>@endif
</details>
</section>
@empty<p class="muted">Source evidence and generated drafts will appear here.</p>@endforelse
{{ $snapshots->links() }}
@endsection
