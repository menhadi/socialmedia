@extends('layouts.app')
@section('title','Publishing queue')
@section('content')
<div class="page-heading"><div><h1>Scheduled and accounted for.</h1><p class="muted">All times below are UTC. Choose your timezone when scheduling a reviewed post.</p></div><a class="button secondary" href="{{ route('research') }}">Research & automation</a></div>
@php($heartbeat = \Illuminate\Support\Facades\Cache::get('content-workflow-heartbeat'))
<div class="notice">Last workflow heartbeat: {{ $heartbeat ?? 'Not received. Configure the server’s Laravel scheduler before relying on automatic publishing.' }}
@if($heartbeat && \Carbon\Carbon::parse($heartbeat)->lt(now()->subMinutes(5)))<strong> The worker may be delayed or stopped. Check server scheduling.</strong>@endif</div>
@forelse($schedules as $schedule)
<section class="panel form-panel"><div class="section-heading"><div><h3>{{ $schedule->post->title }}</h3><p class="muted">{{ $schedule->post->brand->name }} · {{ $schedule->scheduled_at->format('d M Y, H:i') }} UTC</p></div><span class="badge">{{ ucfirst($schedule->status) }}</span></div>
<p>{{ $schedule->automatic ? 'Approved-source automation' : 'Owner-reviewed scheduled post' }}</p>
@if($schedule->reason)<p class="notice">{{ $schedule->reason }}</p>@endif
<div class="actions"><a class="button secondary" href="{{ route('posts.publish',$schedule->post) }}">Post & publishing history</a>
@if($schedule->status==='queued')<form method="post" action="{{ route('schedules.cancel',$schedule) }}">@csrf<button class="button secondary">Cancel schedule</button></form>@endif
</div></section>
@empty<div class="panel empty"><h2>No scheduled posts yet.</h2><p>Review a Facebook post, then open its publishing preview to choose a date and time.</p><a href="{{ route('posts') }}">Open posts →</a></div>@endforelse
{{ $schedules->links() }}
@endsection

