@extends('layouts.app')
@section('title','Monitoring history')
@section('content')
<div class="page-heading"><div><a class="back" href="{{ route('monitoring') }}">← All monitors</a><h1>{{ $monitor->brand->name }}</h1><p class="muted source-link">{{ $monitor->url }}</p></div><span class="badge {{ $monitor->displayStatus() }}">{{ ucfirst($monitor->displayStatus()) }}</span></div>
<div class="stats"><div class="stat"><span>Last observed result</span><strong class="text-value">{{ ucfirst($monitor->last_status) }}</strong><small>{{ $monitor->last_checked_at?->format('d M Y H:i:s').' UTC' }}</small></div><div class="stat"><span>Last response / check time</span><strong>{{ $monitor->last_response_ms ?? '—' }} <small>ms</small></strong><small>Includes DNS and connection time</small></div><div class="stat"><span>Schedule</span><strong class="text-value">{{ $monitor->enabled?'Every '.$monitor->interval_minutes.' minutes':'Paused' }}</strong><small>Expected HTTP {{ $monitor->expected_status }}</small></div></div>
<form method="post" action="{{ route('monitoring.check',$monitor) }}">@csrf<button class="button">Check now</button></form>
<div class="section-heading ai-history-heading"><h2>Observed downtime</h2></div>
<p class="muted small">Measured from the first failed check to the recovery check. Unrecovered observations end at the last check shown; gaps, pauses and changed settings are not counted as continuous downtime.</p>
<div class="panel">
@forelse($incidents as $incident)
    <div class="post-row"><div class="grow"><strong>{{ $incident->started_at->format('d M Y H:i:s') }} UTC</strong><small>{{ $incident->ended_at ? 'Observation ended '.$incident->ended_at->format('d M Y H:i:s').' UTC' : 'Not recovered at the last check' }} · {{ $incident->end_reason ? str_replace('_',' ',$incident->end_reason) : 'open observation' }}</small><small class="source-link">{{ $incident->url }}</small></div><span class="badge">{{ max(0,(int)$incident->started_at->diffInMinutes($incident->ended_at ?? $monitor->last_checked_at ?? $incident->started_at)) }} min observed</span></div>
@empty
    <div class="empty"><p>No failed health checks have been recorded.</p></div>
@endforelse
</div>
@if($incidents->hasPages())<div class="pagination">@if($incidents->previousPageUrl())<a href="{{ $incidents->previousPageUrl() }}">← Newer outages</a>@endif<span>Page {{ $incidents->currentPage() }}</span>@if($incidents->nextPageUrl())<a href="{{ $incidents->nextPageUrl() }}">Older outages →</a>@endif</div>@endif
<div class="section-heading ai-history-heading"><h2>Check history</h2><span class="muted small">Last 30 days · all times UTC</span></div>
<div class="panel">
@forelse($checks as $check)
    <div class="post-row"><span class="badge {{ $check->status }}">{{ ucfirst($check->status) }}</span><div class="grow"><strong>{{ $check->checked_at->format('d M Y H:i:s') }} UTC</strong><small>{{ $check->http_status?'HTTP '.$check->http_status:'No HTTP status' }} · {{ $check->response_ms===null?'Time unavailable':$check->response_ms.' ms' }}</small>@if($check->error_code)<small>{{ \App\Services\Monitoring\PublicEndpoint::description($check->error_code) }}</small>@elseif($check->status==='running')<small>Awaiting result. A check older than two minutes may have been interrupted.</small>@endif<small class="source-link">{{ $check->url }}</small></div></div>
@empty
    <div class="empty"><p>No checks yet. Use Check now to make the first request.</p></div>
@endforelse
</div>
@if($checks->hasPages())<div class="pagination">@if($checks->previousPageUrl())<a href="{{ $checks->previousPageUrl() }}">← Newer checks</a>@endif<span>Page {{ $checks->currentPage() }}</span>@if($checks->nextPageUrl())<a href="{{ $checks->nextPageUrl() }}">Older checks →</a>@endif</div>@endif
@endsection