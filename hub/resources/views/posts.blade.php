@extends('layouts.app')
@section('title','Posts')
@section('content')
<div class="page-heading"><div><div class="eyebrow">YOUR CONTENT LIBRARY</div><h1>From idea to a good post.</h1><p class="muted">Write, refine and review content for every application.</p></div><a class="button" href="{{ route('posts.create') }}">＋ Create a post</a></div>
<form class="filter" method="get"><label for="brand-filter">Application</label><select id="brand-filter" name="brand"><option value="">All applications</option>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(request('brand')==$brand->id)>{{ $brand->name }}</option>@endforeach</select><button class="button secondary">Filter</button></form>
<div class="panel">@forelse($posts as $post)<a class="post-row" href="{{ route('posts.edit',$post) }}"><span class="post-icon">▤</span><div class="grow"><strong>{{ $post->title }}</strong><small>{{ $post->brand->name }} · {{ \App\Models\Post::CHANNELS[$post->channel] }} · {{ $post->updated_at->format('d M Y') }}</small></div><span class="badge {{ $post->status }}">{{ ucfirst($post->status) }}</span><span class="row-arrow">↗</span></a>@empty<div class="empty"><span class="empty-icon">✎</span><h2>A blank page, plenty of possibilities.</h2><p>Your saved posts will appear here.</p></div>@endforelse</div>
@if($posts->hasPages())<div class="pagination">@if($posts->previousPageUrl())<a class="button secondary" href="{{ $posts->previousPageUrl() }}">← Previous</a>@endif<span>Page {{ $posts->currentPage() }} of {{ $posts->lastPage() }}</span>@if($posts->nextPageUrl())<a class="button secondary" href="{{ $posts->nextPageUrl() }}">Next →</a>@endif</div>@endif
@endsection

