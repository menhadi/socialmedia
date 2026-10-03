@extends('layouts.app')
@section('title','AI result')
@section('content')
<div class="page-heading"><div><a class="back" href="{{ route('ai') }}">← AI assistant</a><h1>Your content, ready to refine.</h1><p class="muted">{{ $generation->brand->name }} · {{ \App\Models\AiConnection::PROVIDERS[$generation->provider] }} · {{ $generation->model }} · {{ $generation->language }}</p></div><span class="badge">{{ ucfirst($generation->status) }}</span></div>
@if($generation->status==='pending')
 <div class="notice"><strong>Awaiting a confirmed result</strong><p>This request is still processing or was interrupted. Refresh to check its status. It will not be resent automatically. If it stays pending, check the provider’s usage before starting a new request.</p><a href="{{ route('ai.show',$generation) }}">Refresh status →</a></div>
@elseif(in_array($generation->status,['failed','uncertain']))
 <div class="notice error"><strong>We couldn’t complete this request.</strong><p>{{ \App\Services\Ai\AiFailure::explanation($generation->error_code) }}</p><p>The budget reservation is retained because final usage is unconfirmed. It counts toward today’s limit.</p></div>
@else
 @if($generation->status==='partial')<div class="notice"><strong>This result may be incomplete.</strong><p>The provider did not report a normal completion, or the text reached a limit. Check it carefully before saving.</p></div>@endif
 @if($generation->post_id)
  <div class="notice success"><strong>Saved to your content library.</strong><p><a href="{{ route('posts.edit',$generation->post_id) }}">Open the saved draft →</a></p></div>
  <div class="panel preview"><div class="post-body">{{ $generation->result }}</div></div>
 @elseif($generation->task === 'trend')
  <div class="notice"><p>This trend package was skipped or held. Check its sources and outcome in <a href="{{ route('trends',['brand'=>$generation->brand_id]) }}">Trend posts</a>.</p></div>
  <div class="panel preview"><div class="post-body">{{ $generation->result }}</div></div>
 @else
  <form class="panel form-panel" method="post" action="{{ route('ai.save',$generation) }}">@csrf
   <label>Internal title<input name="title" required maxlength="200" value="{{ old('title',$generation->title) }}"></label>
   <label>Generated content<textarea name="body" rows="15" required maxlength="20000">{{ old('body',$generation->result) }}</textarea></label>
   <p class="muted small">Check facts, links and wording before saving. This creates a new draft; existing posts stay unchanged.</p>
   <div class="actions"><a class="button secondary" href="{{ route('ai') }}">New request</a><button class="button">Save as a draft</button></div>
  </form>
 @endif
@endif
<div class="notice usage-note"><strong>Usage for this request</strong><p>Input: {{ $generation->input_tokens===null?'not reported':number_format($generation->input_tokens).' tokens' }} · Output: {{ $generation->output_tokens===null?'not reported':number_format($generation->output_tokens).' tokens' }} · {{ $generation->usage_reported?'Estimated cost':'Reserved estimate' }}: ${{ number_format($generation->cost_micros/1000000,6) }}</p><p>Estimates use your configured model prices. They are not the provider’s invoice; uncertain requests retain their reservation. Daily limits reset at midnight UTC.</p></div>
@endsection
