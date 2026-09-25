@extends('layouts.app')
@section('title','AI providers')
@section('content')
<div class="page-heading"><div><div class="eyebrow">YOUR CHOICE OF AI</div><h1>A little help. On your terms.</h1><p class="muted">Choose your model, set limits and enable generation when you’re ready.</p></div><a class="button secondary" href="{{ route('ai') }}">Open AI assistant →</a></div>
<div class="notice"><strong>Start small</strong><p>Keys are stored encrypted. Saving settings makes no API call. To enable generation, enter the model’s current input/output prices and your daily limits. Budget checks are estimates, not a guaranteed billing cap; set billing limits with your provider too.</p></div>
<div class="cards provider-grid">
@foreach(\App\Models\AiConnection::PROVIDERS as $value=>$label)
@php
 $connection=$connections->get($value);
 $today=$connection?$usage->get($connection->id):null;
 $editing=old('provider')===$value;
 $field=fn($name,$fallback)=>$editing?old($name,$fallback):$fallback;
@endphp
<form class="panel provider-card" method="post" action="{{ route('providers.update',$value) }}">
 @csrf @method('PUT')<input type="hidden" name="provider" value="{{ $value }}">
 <div class="provider-heading"><span class="provider-icon">{{ mb_substr($label,0,1) }}</span><div><h2>{{ $label }}</h2><small class="muted">{{ $connection?->enabled?'Generation enabled':($connection?->api_key?'Key saved · generation disabled':'Not configured') }}</small></div></div>
 <label>Model identifier<input name="model" maxlength="150" value="{{ $field('model',$connection?->model) }}" placeholder="Exact text model ID from your provider"></label>
 <label>API key<input type="password" name="api_key" maxlength="2000" autocomplete="new-password" placeholder="{{ $connection?->api_key?'Leave blank to keep the saved key':'Add when ready' }}"></label>
 @if($connection?->api_key)<label class="checkbox"><input type="checkbox" name="remove_key" value="1"> Remove saved key (disable generation first)</label>@endif
 <div class="form-grid">
  <label>Requests per day<input type="number" name="daily_request_limit" min="1" max="100" value="{{ $field('daily_request_limit',$connection?->daily_request_limit??10) }}"></label>
  <label>Output token limit<input type="number" name="max_output_tokens" min="256" max="4096" value="{{ $field('max_output_tokens',$connection?->max_output_tokens??1200) }}"></label>
 </div>
 <label>Daily estimated budget (USD)<input type="number" name="daily_budget" min="0.01" max="1000" step="0.01" value="{{ $field('daily_budget',$connection?->daily_budget_micros?$connection->daily_budget_micros/1000000:'') }}" placeholder="For example, 0.25"></label>
 <div class="form-grid">
  <label>Input USD / 1M tokens<input type="number" name="input_rate" min="0" max="10000" step="0.0001" value="{{ $field('input_rate',$connection?->input_rate) }}" placeholder="Current model price"></label>
  <label>Output USD / 1M tokens<input type="number" name="output_rate" min="0" max="10000" step="0.0001" value="{{ $field('output_rate',$connection?->output_rate) }}" placeholder="Current model price"></label>
 </div>
 <p class="muted small">Update prices when changing models. Use the applicable standard input/output rates. Model support and account access are checked on your first generation request.</p>
 <input type="hidden" name="enabled" value="0"><label class="checkbox"><input type="checkbox" name="enabled" value="1" @checked($field('enabled',$connection?->enabled??false))> Enable generation with these limits</label>
 <p class="muted small">Today (UTC): {{ $today?->requests??0 }} requests · ${{ number_format(($today?->cost??0)/1000000,6) }} estimated / reserved</p>
 <div class="card-footer"><span class="muted small">No automatic retries</span><button class="button secondary">Save settings</button></div>
</form>
@endforeach
</div>
@endsection
