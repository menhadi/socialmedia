@extends('layouts.app')
@section('title','Posts')
@section('content')
@if($application)@include('application-nav')@endif
<div class="page-heading"><div><div class="eyebrow">{{ $application ? 'STEP 3 · MANAGE POSTS' : 'WORKSPACE LIBRARY' }}</div><h1>{{ $application ? $application->name.' · '.(\App\Models\Post::CHANNELS[request('channel')] ?? 'All').' posts' : 'All posts' }}</h1><p class="muted">{{ $account ? ($account->display_name ?: $account->page_name ?: 'Selected account').' · Includes unassigned drafts for this platform.' : 'Browse, create and organize your content.' }}</p></div><a class="button" href="{{ route('posts.create', ['brand'=>$application?->id,'channel'=>request('channel')]) }}">＋ Create post</a></div>
<form class="posts-filters" method="get">
@if($account)
<input type="hidden" name="brand" value="{{ $application->id }}"><input type="hidden" name="channel" value="{{ $account->provider }}"><input type="hidden" name="account" value="{{ $account->id }}">
<a class="button secondary" href="{{ route('applications.show',$application) }}">← Change account</a>
@else
<label for="brand-filter">Application<select id="brand-filter" name="brand"><option value="">All applications</option>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(request('brand')==$brand->id)>{{ $brand->name }}</option>@endforeach</select></label>
<label for="channel-filter">Platform<select id="channel-filter" name="channel"><option value="">All platforms</option>@foreach(\App\Models\Post::CHANNELS as $key=>$label)<option value="{{ $key }}" @selected(request('channel')===$key)>{{ $label }}</option>@endforeach</select></label>
@endif
<label>Library<select name="archived"><option value="0">Active posts</option><option value="1" @selected(request()->boolean('archived'))>Archived posts</option></select></label><button class="button secondary">Apply filters</button>
<span class="results-count">{{ $posts->total() }} posts</span>
</form>
<form method="post" action="{{ route('posts.bulk-archive') }}" id="bulk-archive">@csrf
<input type="hidden" name="account" value="{{ $account?->id }}"><input type="hidden" name="brand" value="{{ request('brand') }}"><input type="hidden" name="channel" value="{{ request('channel') }}">
@if(!request()->boolean('archived') && $posts->isNotEmpty())
<div class="bulk-actions">
<label class="select-page-label"><input type="checkbox" id="select-page"> Select page</label>
<span id="selection-count" role="status">0 selected</span>
<select name="scope" id="archive-scope" aria-label="Archive scope"><option value="selected">Selected posts</option><option value="filtered">All {{ $posts->total() }} matching posts</option></select>
<button class="button secondary" id="archive-button" disabled>Archive selected</button>
</div>
<p class="archive-help">Archiving removes posts from this library and cancels queued publishing. Live platform posts remain.</p>
@endif
<div class="panel">@forelse($posts as $post)<div class="post-selection-row">@unless(request()->boolean('archived'))<input class="post-select" type="checkbox" name="post_ids[]" value="{{ $post->id }}" aria-label="Select {{ $post->title }}">@endunless<a class="post-row" href="{{ route('posts.edit',$post) }}"><span class="post-icon">▤</span><div class="grow"><strong>{{ $post->title }}</strong><small>{{ $post->brand->name }} · {{ \App\Models\Post::CHANNELS[$post->channel] }} · {{ $post->updated_at->format('d M Y') }}</small>@if($post->automation_reason)<small>{{ $post->automation_reason }}</small>@endif @if($post->schedules->where('status','queued')->isNotEmpty())<small>Scheduled · {{ $post->schedules->where('status','queued')->first()->scheduled_at->utc()->format('d M H:i') }} UTC</small>@endif</div><span class="badge {{ $post->status }}">{{ ucfirst($post->status) }}</span><span class="row-arrow">↗</span></a></div>@empty<div class="empty"><span class="empty-icon">✎</span><h2>A blank page, plenty of possibilities.</h2><p>Your saved posts will appear here.</p></div>@endforelse</div>
</form>
@if($posts->hasPages())<div class="pagination">@if($posts->previousPageUrl())<a class="button secondary" href="{{ $posts->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $posts->currentPage() }} of {{ $posts->lastPage() }}</span>@if($posts->nextPageUrl())<a class="button secondary" href="{{ $posts->nextPageUrl() }}">Next →</a>@endif</div>@endif
<script>
(() => {
 const all = document.getElementById('select-page'); if (!all) return;
 const boxes = [...document.querySelectorAll('.post-select')];
 const scope = document.getElementById('archive-scope'), button = document.getElementById('archive-button');
 const update = () => {
  const count = boxes.filter(box => box.checked).length;
  all.checked = count === boxes.length; all.indeterminate = count > 0 && count < boxes.length;
  document.getElementById('selection-count').textContent = `${count} selected`;
  button.disabled = scope.value === 'selected' && !count;
  button.textContent = scope.value === 'filtered' ? 'Archive all matching posts' : 'Archive selected';
 };
 all.addEventListener('change', () => { boxes.forEach(box => box.checked = all.checked); update(); });
 boxes.forEach(box => box.addEventListener('change', update)); scope.addEventListener('change', update); update();
})();
</script>
@endsection

