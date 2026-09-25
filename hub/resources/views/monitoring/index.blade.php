@extends('layouts.app')
@section('title','Monitoring')
@section('content')
<div class="page-heading"><div><div class="eyebrow">APPLICATION HEALTH</div><h1>Know when something needs attention.</h1><p class="muted">Check each application's health endpoint and keep a record of outages and recovery.</p></div></div>
<div class="notice"><strong>Scheduled checks need the scheduler running</strong><p>{{ $lastSweep ? 'Last scheduler sweep: '.\Illuminate\Support\Carbon::parse($lastSweep)->diffForHumans().'.' : 'No scheduler sweep has been recorded yet. You can use Check now after saving a monitor.' }} Automatic checks will run on the server after deployment. This page does not start them.</p></div>
<p class="muted small">Uses HTTP HEAD with a 10-second timeout. Choose a public health URL that supports HEAD and returns your expected status, usually 200. Redirects are not followed. Public IPv4 hostnames on ports 80/443 are supported. Only status and timing are stored.</p>
<div class="cards">
@forelse($brands as $brand)
    @php($monitor=$brand->healthMonitor)
    @php($restore=(string)old('form_brand_id')===(string)$brand->id)
    <section class="panel provider-card">
        <div class="section-heading"><h2>{{ $brand->name }}</h2><span class="badge {{ $monitor?->displayStatus() }}">{{ ucfirst($monitor?->displayStatus() ?? 'Not configured') }}</span></div>
        @if($monitor?->last_checked_at)
            <p class="muted small">Last check: {{ $monitor->last_checked_at->format('d M Y, H:i:s') }} UTC · {{ ucfirst($monitor->last_status) }} · {{ $monitor->last_response_ms }} ms @if($monitor->last_http_status) · HTTP {{ $monitor->last_http_status }} @endif</p>
            @if($monitor->last_error)<p class="muted small">{{ \App\Services\Monitoring\PublicEndpoint::description($monitor->last_error) }}</p>@endif
        @else
            <p class="muted small">No completed check for this endpoint yet.</p>
        @endif
        <form method="post" action="{{ route('monitoring.save',$brand) }}">
            @csrf @method('PUT')
            <input type="hidden" name="form_brand_id" value="{{ $brand->id }}">
            <label>Health-check URL<input name="url" type="url" required maxlength="2048" value="{{ $restore?old('url'):($monitor?->url ?? '') }}" placeholder="https://your-application.com/up"></label>
            <div class="form-grid">
                <label>Expected HTTP status<input name="expected_status" type="number" min="200" max="299" required value="{{ $restore?old('expected_status'):($monitor?->expected_status ?? 200) }}"></label>
                <label>Check every<select name="interval_minutes">@foreach([5,15,60] as $minutes)<option value="{{ $minutes }}" @selected(($restore?old('interval_minutes'):($monitor?->interval_minutes ?? 5))==$minutes)>{{ $minutes }} minutes</option>@endforeach</select></label>
            </div>
            <input type="hidden" name="enabled" value="0">
            <label class="checkbox"><input type="checkbox" name="enabled" value="1" @checked($restore?old('enabled'):$monitor?->enabled)>Enable scheduled checks</label>
            <button class="button secondary">Save monitor</button>
        </form>
        @if($monitor)
            <div class="monitor-actions"><form method="post" action="{{ route('monitoring.check',$monitor) }}">@csrf<button class="button">Check now</button></form><a href="{{ route('monitoring.show',$monitor) }}">History and downtime →</a></div>
        @endif
    </section>
@empty
    <div class="panel empty wide"><h2>Add an application first.</h2><p>Each application can have its own health endpoint.</p><a class="button" href="{{ route('applications.create') }}">Add application</a></div>
@endforelse
</div>
<div class="notice usage-note"><strong>What these checks tell you</strong><p>Online means the endpoint returned the expected status at the last check. A health endpoint should check the components you care about. Stale means checks are overdue; it does not prove the application is offline. Outage times are observations between checks, not exact failure times.</p><p>If this workspace and the monitored apps share a server, an external monitor is still needed to detect a complete server outage. Email and messaging alerts are not enabled in this stage.</p></div>
@endsection