@extends('layouts.app')
@section('title','Image & video providers')
@section('content')
<h1>Images and short videos.</h1>
<p>Keep your text provider. Choose separate image and video services for each application.</p>
<p><a href="{{ route('applications') }}">Choose providers in Applications</a> · <a href="{{ route('providers') }}">Text provider settings</a></p>
<p class="notice">These budgets are shared across applications for each media service and are separate from text generation. Enter a conservative cost estimate for one image or one 8-second video, including input charges. Reservations are retained after submission, including failures. Estimates are not guaranteed billing caps; also set limits with Google. Limits reset at midnight UTC.</p>
@foreach(['image'=>'Google Gemini images','video'=>'Google Veo video'] as $kind=>$label)
@php($connection = $connections->get($kind))
<form class="panel form-panel" method="post" action="{{ route('media.settings.save',$kind) }}">@csrf @method('PUT')
<h2>{{ $label }}</h2><p>{{ $connection?->enabled ? 'Generation enabled' : 'Generation disabled' }}</p>
<label>Model<input name="model" required maxlength="150" value="{{ $connection?->model ?? ($kind==='image'?'gemini-3.1-flash-image':'veo-3.1-fast-generate-preview') }}"></label>
<label>Google API key<input type="password" name="api_key" autocomplete="new-password" placeholder="{{ $connection?->api_key ? 'Key saved — leave blank to keep it' : 'Enter key privately here' }}"></label>
<label class="checkbox"><input type="checkbox" name="remove_key" value="1">Remove saved key</label>
<div class="form-grid"><label>Daily budget (USD)<input type="number" name="daily_budget" step="0.000001" min="0" max="1000" required value="{{ ($connection?->daily_budget_micros ?? 0)/1000000 }}"></label>
<label>Estimate per {{ $kind==='image'?'image':'8-second video' }} (USD)<input type="number" name="request_cost" step="0.000001" min="0" max="100" required value="{{ ($connection?->request_cost_micros ?? 0)/1000000 }}"></label>
<label>Requests per day<input type="number" name="daily_request_limit" min="1" max="100" required value="{{ $connection?->daily_request_limit ?? 5 }}"></label></div>
<label class="checkbox"><input type="checkbox" name="enabled" value="1" @checked($connection?->enabled)>Enable {{ $kind }} generation within these limits</label>
<p class="muted">Today: {{ $usage->get($kind)?->requests ?? 0 }} requests · ${{ number_format(($usage->get($kind)?->cost ?? 0)/1000000,6) }} reserved. Saving makes no API call. Changing a key affects new requests; submitted video jobs finish using their encrypted key snapshot.</p>
<button class="button">Save {{ $kind }} settings</button>
</form>
@endforeach
@endsection
