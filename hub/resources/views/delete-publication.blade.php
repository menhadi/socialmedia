@extends('layouts.app')
@section('title','Platform deletion')
@section('content')
<div class="page-heading"><div><a href="{{ route('posts.publish',$publication->post) }}">← Publishing history</a><h1>Remove a published post</h1><p>{{ $publication->post->brand->name }} · {{ \App\Models\Post::CHANNELS[$publication->provider]??$publication->provider }} · {{ $publication->page_name }}</p></div></div>
<div class="panel form-panel"><h2>{{ $publication->title_snapshot ?: $publication->post->title }}</h2><p>Platform reference: <code>{{ $publication->remote_post_id }}</code></p><div class="post-body">{{ $publication->message }}</div>@if($publication->permalink_url)<p><a href="{{ $publication->permalink_url }}" target="_blank" rel="noopener noreferrer">Check this post on the platform</a></p>@endif
@if($publication->remote_deleted_at)
<div class="notice"><strong>Removal recorded {{ $publication->remote_deleted_at->utc()->format('d M Y H:i') }} UTC</strong><p>Confirmation source: {{ $publication->deletion_origin }}. Content and history remain in the Hub archive. Restoring the local record does not recreate the platform post.</p></div>
@else
@if(\App\Services\Social\DeletePublication::supported($publication->provider))
@if($attempts->whereIn('status',['pending','uncertain'])->isNotEmpty())<div class="notice error">A deletion is pending or uncertain. Check the platform before recording the outcome below. This request will not be repeated automatically.</div>
@else
<form method="post" action="{{ route('publications.delete',$publication) }}">@csrf
<input type="hidden" name="request_key" value="{{ $requestKey }}"><input type="hidden" name="fingerprint" value="{{ $fingerprint }}">
<p>This deletes the exact platform post shown above and may remove its comments and engagement. It cannot be restored from Content Hub. The local record will be archived with history retained.</p>
@if($publication->provider==='youtube')<p>Your Google token needs a deletion scope such as youtube.force-ssl; an upload-only token cannot delete.</p>@endif
<label>Type DELETE to confirm<input name="confirmation_text" required autocomplete="off" pattern="DELETE"></label><label class="checkbox"><input type="checkbox" name="confirm" value="1" required>I authorize removing this exact post from the selected platform.</label><button class="button">Delete from {{ \App\Models\Post::CHANNELS[$publication->provider] }}</button></form>
@endif
@else
<p>Remote deletion is not supported by this connector for {{ \App\Models\Post::CHANNELS[$publication->provider] }}. Remove the item in the platform's own interface where available, then record the outcome below. Archiving here alone does not remove it remotely.</p>
@endif
<details><summary>Already removed directly on the platform?</summary><p>Use this only after verifying removal yourself. An expired token, private post or failed lookup is not confirmation. This records your confirmation and archives the Hub record; it sends no deletion request.</p><form method="post" action="{{ route('publications.external-removal',$publication) }}">@csrf<label>Enter the exact platform reference shown above<input name="remote_id" required autocomplete="off"></label><label class="checkbox"><input type="checkbox" name="confirm_external" value="1" required>I have verified that this exact item was removed from the platform.</label><button class="button secondary">Record verified removal</button></form></details>
@endif
</div>
<div class="panel form-panel"><h2>Deletion history</h2>@forelse($attempts as $attempt)<div class="notice"><strong>{{ ucfirst($attempt->status) }}</strong> · {{ $attempt->origin }} · {{ $attempt->created_at->utc()->format('d M Y H:i') }} UTC<p>{{ $attempt->reason }}</p></div>@empty<p>No deletion requests.</p>@endforelse</div>
<p>Facebook removals can synchronize automatically after configuring the signed Page feed webhook. Other connectors currently require owner confirmation for deletions made outside Content Hub. Ordinary API lookup failures never erase records.</p>
@endsection
