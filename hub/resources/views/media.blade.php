@extends('layouts.app')
@section('title','AI images & video')
@section('content')
<a href="{{ route('posts.edit',$post) }}">← Saved post</a><h1>Create the visual.</h1>
<p>{{ $post->brand->name }} · {{ $post->title }}</p>
<p><a href="{{ route('media.settings') }}">Media providers & budgets</a> · <a href="{{ route('applications.edit',$post->brand) }}">Application preferences</a></p>
<form class="panel form-panel" method="post" action="{{ route('media.store',$post) }}">@csrf
<input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="fingerprint" value="{{ $post->publishingFingerprint() }}">
<div class="form-grid"><label>Create<select name="kind"><option value="image">AI image · Gemini</option><option value="video">8-second AI video · Veo · 720p</option></select></label>
<label>Format<select name="aspect_ratio"><option value="16:9">Landscape 16:9</option><option value="9:16">Portrait 9:16</option><option value="1:1">Square 1:1 (images only)</option></select></label></div>
<label>Visual direction<textarea name="prompt" maxlength="3000" rows="4" required>{{ old('prompt','Create a clean, professional visual that supports the saved post. Use illustrative scenes, leave space around the subject, and avoid invented facts or tiny text.') }}</textarea></label>
<label class="checkbox"><input type="checkbox" name="use_image" value="1" @disabled(!$post->image_path)>For video, animate the currently attached image</label>
<label class="checkbox"><input type="checkbox" name="confirm" value="1" required>Send this saved post and visual direction (plus the image if selected) to Google and reserve the configured media cost.</label>
<p class="muted">English follows your application preference; request Hindi explicitly when needed. Videos may include generated audio. Review every generated visual, especially dates and text. Nothing is automatically attached or published.</p>
<button class="button">Generate media</button>
</form>
<h2>Generation history</h2><p>Refresh to see progress. The existing server scheduler handles queued requests and video checks.</p>
@forelse($generations as $generation)
<section class="panel"><h3>{{ ucfirst($generation->kind) }} · {{ ucfirst($generation->status) }}</h3>
<p>{{ $generation->model }} · {{ $generation->aspect_ratio }} · {{ $generation->created_at }} UTC · ${{ number_format($generation->cost_micros/1000000,6) }} reserved</p>
@if($generation->error)<p class="notice">{{ $generation->error }} No automatic generation retry was made.</p>@endif
@if(in_array($generation->status,['completed','attached']))
@if($generation->kind==='image')<img src="{{ route('media.file',$generation) }}" alt="Generated image preview" style="max-width:100%;max-height:600px">@else<video controls preload="metadata" src="{{ route('media.file',$generation) }}" style="max-width:100%;max-height:600px"></video>@endif
<p><a href="{{ route('media.file',$generation) }}" download>Download {{ $generation->kind }}</a></p>
@if($generation->status==='completed')
<form method="post" action="{{ route('media.attach',$generation) }}">@csrf<label class="checkbox"><input type="checkbox" name="confirm" value="1" required>I reviewed this media. Replace the post’s current attachment and return it to draft.</label><button class="button">Use this {{ $generation->kind }}</button></form>
@endif
@endif
</section>
@empty<p>No media requests yet.</p>@endforelse
{{ $generations->links() }}
@endsection
