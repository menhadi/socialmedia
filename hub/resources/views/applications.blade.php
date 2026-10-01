@extends('layouts.app')
@section('title','Applications')
@section('content')
<div class="page-heading"><div><div class="eyebrow">STEP 1 · CHOOSE AN APPLICATION</div><h1>Your applications</h1><p class="muted">Open an application, choose its social account, then manage its posts.</p></div><a class="button secondary" href="{{ route('applications.create') }}">＋ Add application</a></div>
<div class="cards application-cards">
@forelse($brands as $brand)
<article class="panel brand-card">
    <span class="app-icon">{{ mb_substr($brand->name,0,1) }}</span><h2><a href="{{ route('applications.show',$brand) }}">{{ $brand->name }}</a></h2>
    <p class="muted">{{ \Illuminate\Support\Str::limit($brand->description ?: $brand->website ?: 'Your content and social accounts, together.',110) }}</p>
    <div class="tags"><span>{{ $brand->socialAccounts->count() }} social accounts</span><span>{{ $brand->posts_count }} posts</span></div>
    <div class="card-footer"><a class="button" href="{{ route('applications.show',$brand) }}">Open application →</a><a href="{{ route('applications.edit',$brand) }}">Settings</a></div>
</article>
@empty
<div class="panel empty wide"><h2>Add your first application</h2><p>Keep each brand’s accounts and posts together.</p><a class="button" href="{{ route('applications.create') }}">Add application</a></div>
@endforelse
</div>
<div class="application-secondary"><a href="{{ route('social') }}">All social connections</a></div>
@endsection
