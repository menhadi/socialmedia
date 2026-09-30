@extends('layouts.app')
@section('title','Posts')
@section('content')
@if($application)@include('application-nav')@endif
<div class="page-heading"><div><div class="eyebrow">YOUR CONTENT LIBRARY</div><h1>From idea to a good post.</h1><p class="muted">Write, refine and review content for every application.</p></div><a class="button" href="{{ route('posts.create', request()->only(['brand','channel'])) }}">＋ Create a post</a></div>
<form class="filter" method="get"><label for="brand-filter">Application</label><select id="brand-filter" name="brand"><option value="">All applications</option>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(request('brand')==$brand->id)>{{ $brand->name }}</option>@endforeach</select><label for="channel-filter">Platform</label><select id="channel-filter" name="channel"><option value="">All platforms</option>@foreach(\App\Models\Post::CHANNELS as $key=>$label)<option value="{{ $key }}" @selected(request('channel')===$key)>{{ $label }}</option>@endforeach</select><label><input type="checkbox" name="archived" value="1" @checked(request()->boolean('archived'))>Archived</label><button class="button secondary">Filter</button></form>
<form method="post" action="{{ route('posts.bulk-archive') }}" id="bulk-archive">@csrf
<input type="hidden" name="brand" value="{{ request('brand') }}"><input type="hidden" name="channel" value="{{ request('channel') }}">
@if(!request()->boolean('archived') && $posts->isNotEmpty())
<div class="bulk-actions"><label class="checkbox"><input type="checkbox" id="select-page"> Select all on this page</label><label>Archive scope<select name="scope" id="archive-scope"><option value="selected">Selected posts</option><option value="filtered">All {{ $posts->total() }} matching posts (every page)</option></select></label><span id="selection-count" role="status">0 selected</span><button class="button secondary" id="archive-button" disabled>Archive selected</button><p class="muted small">Archive hides posts here and cancels queued publishing. Published posts stay on their platforms.</p></div>
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
  document.getElementById('selection-count').textContent = `${count} selected on this page`;
  button.disabled = scope.value === 'selected' && !count;
  button.textContent = scope.value === 'filtered' ? 'Archive all matching posts' : 'Archive selected';
 };
 all.addEventListener('change', () => { boxes.forEach(box => box.checked = all.checked); update(); });
 boxes.forEach(box => box.addEventListener('change', update)); scope.addEventListener('change', update); update();
})();
</script>
@endsection

