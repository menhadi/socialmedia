@extends('layouts.app')
@section('title','Publishing preview')
@section('content')
<div class="page-heading"><div><a class="back" href="{{ route('posts.edit',$post) }}">← Saved post</a><h1>Check it. Then share it.</h1><p class="muted">{{ $post->title }} · {{ $post->brand->name }}</p></div><span class="badge {{ $post->status }}">{{ ucfirst($post->status) }}</span></div>
<div class="editor-grid">
    <section class="panel preview">
        <h2>Saved message</h2>
        <div class="post-body">{{ $post->body }}</div>
        @if($post->video_path)<video controls preload="metadata" src="{{ route('posts.video',$post) }}" style="width:100%"></video><p>The video will be uploaded with the saved message as its description. The platform may need time to process it.</p>@endif
        @if($post->image_path)<img src="{{ route('posts.image',$post) }}" alt="Image included in this post" style="width:100%;height:auto;border-radius:12px"><p class="muted small">This image will be attached. A shared link is included in the photo caption.</p>@endif
        <p class="muted small">{{ $post->channel==='youtube' ? 'The saved title becomes the video title.' : 'The message and attached media are sent.' }} The platform controls how the published post is displayed.</p>
    </section>
    <section class="panel form-panel">
    @if(!\App\Services\Social\ChannelRules::supported($post->channel))
        <h2>Save account details now</h2><p class="muted">Posting for {{ \App\Models\Post::CHANNELS[$post->channel] }} will be added later. This content stays saved as a draft or reviewed post.</p><a class="button secondary" href="{{ route('social',['provider'=>array_key_exists($post->channel,\App\Services\Social\AccountSetup::PROVIDERS)?$post->channel:'facebook']) }}">Open account setup</a>
    @elseif($activeSchedule)
        <h2>Schedule {{ $activeSchedule->status }}</h2><p>This saved post is in the publishing queue. Cancel a queued schedule there before publishing manually.</p><a class="button secondary" href="{{ route('schedules') }}">Open publishing queue</a>
    @elseif($ready && $accounts->isNotEmpty())
        <h2>Publish to {{ \App\Models\Post::CHANNELS[$post->channel] }}</h2>
        <form method="post" action="{{ route('posts.publish.store',$post) }}">
            @csrf
            <input type="hidden" name="request_key" value="{{ $requestKey }}">
            <input type="hidden" name="fingerprint" value="{{ $post->publishingFingerprint() }}">
            <label>Destination account<select name="social_account_id" required><option value="">Choose a verified account</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('social_account_id')==$account->id)>{{ $account->page_name }} · {{ $account->page_id }}</option>@endforeach</select></label>
            @if($post->source_url)
                <label class="checkbox"><input type="checkbox" name="include_link" value="1" @checked(old('include_link'))>Share the saved link with this message</label>
                <a class="source-link" href="{{ $post->source_url }}" target="_blank" rel="noopener noreferrer">{{ $post->source_url }}</a>
            @endif
            @include('publishing-options')
            <label class="checkbox"><input type="checkbox" name="confirm" value="1" required>I have checked this message and the selected account. Publish it now.</label>
            <p class="muted small">This submits real content to the selected account. Each saved post can be published once. A rejected submission can be tried again from a new preview.</p>
            <button class="button full">Publish now</button>
        </form>
        <hr>
        <h2>Or schedule this post</h2>
        <form method="post" action="{{ route('schedules.store',$post) }}">
            @csrf
            <input type="hidden" name="fingerprint" value="{{ $post->publishingFingerprint() }}">
            <label>Destination account<select name="social_account_id" required>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->page_name }} · {{ $account->page_id }}</option>@endforeach</select></label>
            <label>Date & time<input type="datetime-local" name="scheduled_at" required value="{{ old('scheduled_at') }}"></label>
            <label>Timezone<select name="timezone"><option value="Asia/Kolkata">India (Asia/Kolkata)</option><option value="UTC">UTC</option>@foreach(['Europe/London','America/New_York','America/Los_Angeles','Asia/Dubai'] as $zone)<option value="{{ $zone }}">{{ $zone }}</option>@endforeach</select></label>
            @if($post->source_url)<label class="checkbox"><input type="checkbox" name="include_link" value="1" checked>Include the saved link</label>@endif
            @include('publishing-options')
            <label class="checkbox"><input type="checkbox" name="confirm" value="1" required>I approve this saved post and image for automatic publishing at the selected time.</label>
            <button class="button full">Schedule post</button>
        </form>
        <p><a href="{{ route('schedules') }}">View or cancel scheduled posts →</a></p>
    @elseif($ready)
        <h2>Connect an account first</h2><p class="muted">Verify an account for {{ $post->brand->name }} before publishing.</p><a class="button" href="{{ route('social') }}">Open Social accounts</a>
    @else
        <h2>{{ in_array($post->status,['draft','reviewed'])?'Review before publishing':'Submission recorded' }}</h2>
        <p class="muted">{{ in_array($post->status,['draft','reviewed'])?'Save this post, then mark the saved version as reviewed.':'This post cannot be resubmitted or edited. Its publishing history is shown below.' }}</p>
        <a class="button secondary" href="{{ route('posts.edit',$post) }}">Back to saved post</a>
    @endif
    </section>
</div>
<div class="section-heading ai-history-heading"><h2>Publishing history</h2></div>
@forelse($publications as $publication)
    <section class="panel preview">
        <div class="section-heading"><h3>{{ $publication->page_name }} · {{ $publication->page_id }}</h3><span class="badge {{ $publication->status }}">{{ ucfirst($publication->status) }}</span></div>
        <p class="muted small">Submitted {{ $publication->created_at->format('d M Y, H:i') }} UTC</p>
        @if($publication->remote_deleted_at)
            <div class="notice"><strong>Removed from platform</strong><p>Recorded {{ $publication->remote_deleted_at->utc()->format('d M Y H:i') }} UTC via {{ $publication->deletion_origin }}. Local history is retained.</p></div>
        @elseif($publication->status==='published')
            <p>The platform accepted this submission. Video processing or WhatsApp delivery may still be pending. Reference: <code>{{ $publication->remote_post_id }}</code></p>
            @if($publication->permalink_url)
                <a class="button secondary" href="{{ $publication->permalink_url }}" target="_blank" rel="noopener noreferrer">View on platform ↗</a>
            @elseif($publication->provider!=='whatsapp')
                <p class="muted small">The public link could not be retrieved. Refreshing the link will not create another post.</p>
                <form method="post" action="{{ route('publications.link',$publication) }}">@csrf<button class="button secondary">Refresh public link</button></form>
            @endif
        @elseif($publication->status==='publishing')
            <div class="notice"><strong>Submission started</strong><p>Refresh this page to see the result. Media processing is checked automatically. If it remains here for over an hour, check the destination account directly and contact the workspace administrator. Do not recreate the post to retry it.</p></div>
        @else
            <div class="notice {{ $publication->status==='failed'?'error':'' }}"><strong>{{ $publication->status==='failed'?'Submission rejected':'Outcome needs checking' }}</strong><p>{{ \App\Services\Social\FacebookFailure::description($publication->error_code) }}</p>@if($publication->status==='uncertain')<p>The platform may have created the post. This record stays locked to prevent a duplicate. Check the destination directly and contact the workspace administrator to reconcile the result.</p>@endif</div>
        @endif
        @if($publication->status==='published')<p><a class="button secondary" href="{{ route('publications.delete.preview',$publication) }}">{{ $publication->remote_deleted_at?'Deletion history':'Delete from platform / record removal' }}</a></p>@endif
        <details class="usage-note"><summary>View submitted content</summary>@if($publication->provider==='whatsapp')<p>Recipient: {{ $publication->options['recipient'] ?? '' }} · {{ $publication->options['mode'] ?? '' }}</p>@if(($publication->options['mode'] ?? '')==='template')<p>Template: {{ $publication->options['template_name'] }} ({{ $publication->options['template_language'] }})</p><pre>{{ $publication->options['template_values'] ?? 'No body parameters' }}</pre><p>Draft reference below; the approved template above was sent instead.</p>@endif @endif<div class="post-body">{{ $publication->message }}</div>@if($publication->link)<a class="source-link" href="{{ $publication->link }}" target="_blank" rel="noopener noreferrer">{{ $publication->link }}</a>@else<p class="muted small">No separate link attached.</p>@endif</details>
    </section>
@empty
    <div class="panel empty"><p>No publishing attempts yet. This message is only saved in your workspace.</p></div>
@endforelse
@endsection
