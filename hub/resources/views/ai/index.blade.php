@extends('layouts.app')
@section('title','AI assistant')
@section('content')
<div class="page-heading">
 <div><div class="eyebrow">FROM A THOUGHT TO A DRAFT</div><h1>What would you like to create?</h1><p class="muted">Use your application’s voice, audience and language to shape the result.</p></div>
 <a class="button secondary" href="{{ route('providers') }}">Manage AI providers</a>
</div>
@if($brands->isEmpty())
 <div class="panel empty"><h2>Start with an application.</h2><p>Add its audience and voice, then return here to create content.</p><a class="button" href="{{ route('applications.create') }}">Add application</a></div>
@else
 @php($ready=$connections->contains(fn($connection)=>$connection->enabled && $connection->model && $connection->api_key))
 @unless($ready)<div class="notice"><strong>Add an AI provider when you’re ready.</strong><p>No provider is enabled yet. Save a key, model and limits in <a href="{{ route('providers') }}">AI providers</a> to generate content. You can continue writing drafts manually.</p></div>@endunless
 <div class="editor-grid">
 <form class="panel form-panel ai-request-form" method="post" action="{{ route('ai.generate') }}">
 @csrf
 <input type="hidden" name="request_key" value="{{ old('request_key',$requestKey) }}">
 <div class="form-grid">
  <label>Application<select name="brand_id" required>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(old('brand_id',$post?->brand_id)==$brand->id)>{{ $brand->name }} · {{ $brand->language }}</option>@endforeach</select></label>
  <label>AI provider<select name="ai_connection_id"><option value="">Use application preference</option>@foreach($connections as $connection)<option value="{{ $connection->id }}" @selected(old('ai_connection_id')==$connection->id)>{{ \App\Models\AiConnection::PROVIDERS[$connection->provider] }} · {{ $connection->model ?: 'No model' }}{{ $connection->enabled?'':' (disabled)' }}</option>@endforeach</select></label>
 </div>
 <div class="form-grid">
  <label>Help me with<select name="task">@foreach(\App\Models\AiGeneration::TASKS as $value=>$label)<option value="{{ $value }}" @selected(old('task',$post?'rewrite':'draft')===$value)>{{ $label }}</option>@endforeach</select></label>
  <label>Intended channel<select name="channel">@foreach(\App\Models\Post::CHANNELS as $value=>$label)<option value="{{ $value }}" @selected(old('channel',$post?->channel)===$value)>{{ $label }}</option>@endforeach</select></label>
 </div>
 <label>Topic / internal title<input name="title" required maxlength="200" value="{{ old('title',$post?->title) }}" placeholder="What should this content be about?"></label>
 <label>Language <span>optional override</span><input name="language" list="ai-languages" maxlength="100" value="{{ old('language') }}" placeholder="Use application language"></label>
 <datalist id="ai-languages"><option value="English"></option><option value="Hindi">हिन्दी</option></datalist>
 <label>Facts, source text or text to rewrite<textarea name="source_text" rows="7" maxlength="8000" placeholder="Paste verified details here. For rewriting or translation, provide the original text.">{{ old('source_text',$post?->body) }}</textarea></label>
 <label>Source or destination link<input name="source_url" type="url" maxlength="2048" value="{{ old('source_url',$post?->source_url) }}" placeholder="https://"></label>
 <p class="muted small">Links are included as references. Their pages are not fetched; paste the facts you want the AI to use.</p>
 <div class="notice"><strong>One request to your selected provider</strong><p>Generating sends this application’s profile, your topic and source text to that provider and may use paid credits. Nothing is published or overwritten.</p></div>
 <button class="button full" data-generate-button data-ready="{{ $ready?'true':'false' }}" @disabled(!$ready)>Generate with AI →</button>
 <p class="small muted generation-progress" role="status" hidden>Generating… please keep this page open. It may take up to 25 seconds.</p>
 </form>
 <aside>
  <div class="panel preview"><div class="eyebrow">A LITTLE DIRECTION GOES A LONG WAY</div><h2>Bring the facts.<br>Let AI shape the words.</h2><p class="muted">A clear topic and a few verified details help produce a useful first draft.</p><ol class="steps"><li>Choose your application and provider.</li><li>Pick a task and add your source material.</li><li>Review the result, then save a new draft.</li></ol><a href="{{ route('providers') }}">Set up a provider →</a></div>
  <div class="notice"><strong>Your limits stay in control</strong><p>Requests and estimated costs are tracked per provider. Repeated submissions reuse the existing result. Failed requests are never retried automatically.</p></div>
 </aside>
 </div>
@endif
<div class="section-heading ai-history-heading"><h2>Recent AI requests</h2><span class="muted small">Private to your workspace</span></div>
<div class="panel">
 @forelse($generations as $generation)
 <a class="post-row" href="{{ route('ai.show',$generation) }}"><span class="post-icon">✧</span><div class="grow"><strong>{{ $generation->title }}</strong><small>{{ $generation->brand->name }} · {{ \App\Models\AiConnection::PROVIDERS[$generation->provider] }} · {{ $generation->created_at->format('d M, H:i') }} UTC</small></div><span class="badge">{{ ucfirst($generation->status) }}</span><span class="row-arrow">↗</span></a>
 @empty<div class="empty"><h3>Your ideas will collect here.</h3><p>Each request keeps its result, status and usage estimate together.</p></div>@endforelse
</div>
@if($generations->hasPages())<div class="pagination">@if($generations->previousPageUrl())<a href="{{ $generations->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $generations->currentPage() }}</span>@if($generations->nextPageUrl())<a href="{{ $generations->nextPageUrl() }}">Next →</a>@endif</div>@endif
<script src="{{ asset('ai.js') }}" defer></script>
@endsection
