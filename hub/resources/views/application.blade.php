@extends('layouts.app')
@section('title', $application->name)
@section('content')
@include('application-nav')
<div class="page-heading"><div><div class="eyebrow">STEP 2 · CHOOSE A SOCIAL ACCOUNT</div><h1>{{ $application->name }}</h1><p class="muted">Select an account to see its posts and prepare your next update.</p></div><a class="button secondary" href="{{ route('applications.accounts.create',$application) }}">＋ Connect account</a></div>
<p><a class="button secondary" href="{{ route('trends',['brand'=>$application->id]) }}">Configure daily trend posts</a></p><div class="cards account-cards">
@forelse($accounts as $account)
@php($platform = \App\Models\Post::CHANNELS[$account->provider] ?? ucfirst($account->provider))
<article class="panel account-card">
    <div class="account-card-top"><span class="channel-icon channel-{{ $account->provider }}">{{ $account->provider === 'facebook' ? 'f' : ($account->provider === 'x' ? '𝕏' : mb_substr($platform,0,2)) }}</span><span class="badge {{ $account->verified_at ? 'reviewed' : '' }}">{{ $account->verified_at ? 'Verified' : 'Setup needed' }}</span></div>
    <h2>{{ $platform }}</h2><p class="account-name">{{ $account->display_name ?: $account->page_name ?: $application->name }}</p>
    <p class="muted small">Account ID: {{ $account->page_id }}</p>
    <div class="account-card-bottom"><strong>{{ $counts[$account->id] }} posts & drafts</strong><a class="button" href="{{ route('posts',['brand'=>$application->id,'channel'=>$account->provider,'account'=>$account->id]) }}">View posts →</a></div>
    <a class="account-settings" href="{{ route('applications.accounts.edit',[$application,$account]) }}">Connection settings</a>
</article>
@empty
<div class="panel empty wide"><h2>Connect {{ $application->name }}’s first account</h2><p>Your Facebook, X and other accounts will appear here.</p><a class="button" href="{{ route('applications.accounts.create',$application) }}">Connect a social account</a></div>
@endforelse
</div>
<div class="application-secondary"><a href="{{ route('posts',['brand'=>$application->id]) }}">All {{ $application->name }} posts</a><a href="{{ route('applications.edit',$application) }}">Application settings</a></div>
@endsection
