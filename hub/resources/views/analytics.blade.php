@extends('layouts.app')
@section('title','Analytics')
@section('content')
<div class="page-heading"><div><h1>Post and application performance</h1><p class="muted">Latest lifetime metrics per post, refreshed in the background. Missing metrics mean unavailable, not zero. Refreshing does not add cumulative counts together.</p></div></div>
<form class="filter" method="get"><label>Application<select name="brand"><option value="">All</option>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(request('brand')==$brand->id)>{{ $brand->name }}</option>@endforeach</select></label><label>Platform<select name="channel"><option value="">All</option>@foreach(\App\Models\Post::CHANNELS as $key=>$label)<option value="{{ $key }}" @selected(request('channel')===$key)>{{ $label }}</option>@endforeach</select></label><label>Post ID<input type="number" name="post" value="{{ request('post') }}"></label><button class="button secondary">Filter</button></form>
<div class="panel form-panel"><h2>Application events · last 30 days</h2><p>Reported by your application's backend integration. These are separate from social impressions and clicks.</p>@forelse($totals as $name=>$total)<p><strong>{{ ucfirst($name) }}:</strong> {{ $total }}</p>@empty<p>No events received for this filter. Connect your application under <a href="{{ route('automation') }}">Content automation</a>.</p>@endforelse</div>
@forelse($publications as $publication)
<div class="panel form-panel"><h2><a href="{{ route('posts.edit',$publication->post) }}">{{ $publication->post->title }}</a></h2><p>{{ $publication->post->brand->name }} · {{ \App\Models\Post::CHANNELS[$publication->provider]??$publication->provider }} · Post #{{ $publication->post_id }} · Publication #{{ $publication->id }}</p>
@php($snapshot=$publication->latestAnalytics)
<p><strong>{{ $snapshot?->status ?? 'Awaiting first collection' }}</strong>@if($snapshot) · Checked {{ $snapshot->created_at->utc()->format('Y-m-d H:i') }} UTC @endif</p>
@if($snapshot?->metrics)<div class="form-grid">@foreach($snapshot->metrics as $name=>$count)<div><strong>{{ number_format($count) }}</strong> {{ ucfirst($name) }}</div>@endforeach</div>@endif
<p>{{ $snapshot?->reason }}</p>
@if($publication->permalink_url)<p><a href="{{ $publication->permalink_url }}" target="_blank" rel="noopener noreferrer">Open platform post</a></p>@endif
<form method="post" action="{{ route('analytics.refresh',$publication) }}">@csrf<button class="button secondary">Refresh metrics</button></form>
<p class="muted small">Unavailable does not prove deletion: the post may be private or API access may have changed. Content Hub keeps its history and does not automatically republish.</p></div>
@empty<div class="panel form-panel">No published posts match this filter.</div>@endforelse
@if($publications->previousPageUrl())<a href="{{ $publications->previousPageUrl() }}">Previous</a>@endif @if($publications->nextPageUrl())<a href="{{ $publications->nextPageUrl() }}">Next</a>@endif
@endsection
