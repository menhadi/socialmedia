@extends('layouts.app')
@section('title','Facebook publishing')
@section('content')
<div class="page-heading"><div><a class="back" href="{{ route('posts.edit',$post) }}">← Saved post</a><h1>Check it. Then share it.</h1><p class="muted">{{ $post->title }} · {{ $post->brand->name }}</p></div><span class="badge {{ $post->status }}">{{ ucfirst($post->status) }}</span></div>
<div class="editor-grid">
    <section class="panel preview">
        <h2>Saved message</h2>
        <div class="post-body">{{ $post->body }}</div>
        @if($post->image_path)<img src="{{ route('posts.image',$post) }}" alt="Image included in this Facebook post" style="width:100%;height:auto;border-radius:12px"><p class="muted small">This image will be attached. A shared link is included in the photo caption.</p>@endif
        <p class="muted small">{{ $post->image_path ? 'The title appears on the image.' : 'The internal title is not sent.' }} Facebook controls how the published post is displayed.</p>
    </section>
    <section class="panel form-panel">
    @if($post->channel!=='facebook')
        <h2>Save account details now</h2><p class="muted">Posting for {{ \App\Models\Post::CHANNELS[$post->channel] }} will be added later. This content stays saved as a draft or reviewed post.</p><a class="button secondary" href="{{ route('social',['provider'=>array_key_exists($post->channel,\App\Services\Social\AccountSetup::PROVIDERS)?$post->channel:'facebook']) }}">Open account setup</a>
    @elseif($activeSchedule)
        <h2>Schedule {{ $activeSchedule->status }}</h2><p>This saved post is in the publishing queue. Cancel a queued schedule there before publishing manually.</p><a class="button secondary" href="{{ route('schedules') }}">Open publishing queue</a>
    @elseif($ready && $accounts->isNotEmpty())
        <h2>Publish to Facebook</h2>
        <form method="post" action="{{ route('posts.publish.store',$post) }}">
            @csrf
            <input type="hidden" name="request_key" value="{{ $requestKey }}">
            <input type="hidden" name="fingerprint" value="{{ $post->publishingFingerprint() }}">
            <label>Destination Page<select name="social_account_id" required><option value="">Choose a verified Page</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('social_account_id')==$account->id)>{{ $account->page_name }} · {{ $account->page_id }}</option>@endforeach</select></label>
            @if($post->source_url)
                <label class="checkbox"><input type="checkbox" name="include_link" value="1" @checked(old('include_link'))>Share the saved link with this message</label>
                <a class="source-link" href="{{ $post->source_url }}" target="_blank" rel="noopener noreferrer">{{ $post->source_url }}</a>
            @endif
            <label class="checkbox"><input type="checkbox" name="confirm" value="1" required>I have checked this message and the selected Page. Publish it now.</label>
            <p class="muted small">This creates a real Facebook Page post immediately. Each saved post can be published once. A rejected submission can be tried again from a new preview.</p>
            <button class="button full">Publish now</button>
        </form>
        <hr>
        <h2>Or schedule this post</h2>
        <form method="post" action="{{ route('schedules.store',$post) }}">
            @csrf
            <input type="hidden" name="fingerprint" value="{{ $post->publishingFingerprint() }}">
            <label>Destination Page<select name="social_account_id" required>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->page_name }} · {{ $account->page_id }}</option>@endforeach</select></label>
            <label>Date & time<input type="datetime-local" name="scheduled_at" required value="{{ old('scheduled_at') }}"></label>
            <label>Timezone<select name="timezone"><option value="Asia/Kolkata">India (Asia/Kolkata)</option><option value="UTC">UTC</option>@foreach(['Europe/London','America/New_York','America/Los_Angeles','Asia/Dubai'] as $zone)<option value="{{ $zone }}">{{ $zone }}</option>@endforeach</select></label>
            @if($post->source_url)<label class="checkbox"><input type="checkbox" name="include_link" value="1" checked>Include the saved link</label>@endif
            <label class="checkbox"><input type="checkbox" name="confirm" value="1" required>I approve this saved post and image for automatic publishing at the selected time.</label>
            <button class="button full">Schedule post</button>
        </form>
        <p><a href="{{ route('schedules') }}">View or cancel scheduled posts →</a></p>
    @elseif($ready)
        <h2>Connect a Page first</h2><p class="muted">Verify a Facebook Page for {{ $post->brand->name }} before publishing.</p><a class="button" href="{{ route('social') }}">Open Social accounts</a>
    @else
        <h2>{{ in_array($post->status,['draft','reviewed'])?'Review before publishing':'Submission recorded' }}</h2>
        <p class="muted">{{ in_array($post->status,['draft','reviewed'])?'Save this post with Facebook as its channel, then mark the saved version as reviewed.':'This post cannot be resubmitted or edited. Its publishing history is shown below.' }}</p>
        <a class="button secondary" href="{{ route('posts.edit',$post) }}">Back to saved post</a>
    @endif
    </section>
</div>
<div class="section-heading ai-history-heading"><h2>Publishing history</h2></div>
@forelse($publications as $publication)
    <section class="panel preview">
        <div class="section-heading"><h3>{{ $publication->page_name }} · {{ $publication->page_id }}</h3><span class="badge {{ $publication->status }}">{{ ucfirst($publication->status) }}</span></div>
        <p class="muted small">Submitted {{ $publication->created_at->format('d M Y, H:i') }} UTC</p>
        @if($publication->status==='published')
            <p>Facebook confirmed this post. Reference: <code>{{ $publication->remote_post_id }}</code></p>
            @if($publication->permalink_url)
                <a class="button secondary" href="{{ $publication->permalink_url }}" target="_blank" rel="noopener noreferrer">View on Facebook ↗</a>
            @else
                <p class="muted small">The public link could not be retrieved. Refreshing the link will not create another post.</p>
                <form method="post" action="{{ route('publications.link',$publication) }}">@csrf<button class="button secondary">Refresh public link</button></form>
            @endif
        @elseif($publication->status==='publishing')
            <div class="notice"><strong>Submission started</strong><p>Refresh this page to see the result. If it remains here for more than two minutes, check the Facebook Page directly and contact the workspace administrator. Do not recreate the post to retry it.</p></div>
        @else
            <div class="notice {{ $publication->status==='failed'?'error':'' }}"><strong>{{ $publication->status==='failed'?'Submission rejected':'Outcome needs checking' }}</strong><p>{{ \App\Services\Social\FacebookFailure::description($publication->error_code) }}</p>@if($publication->status==='uncertain')<p>Facebook may have created the post. This record stays locked to prevent a duplicate. Check the Page directly and contact the workspace administrator to reconcile the result.</p>@endif</div>
        @endif
        <details class="usage-note"><summary>View submitted content</summary><div class="post-body">{{ $publication->message }}</div>@if($publication->link)<a class="source-link" href="{{ $publication->link }}" target="_blank" rel="noopener noreferrer">{{ $publication->link }}</a>@else<p class="muted small">No separate link attached.</p>@endif</details>
    </section>
@empty
    <div class="panel empty"><p>No publishing attempts yet. This message is only saved in your workspace.</p></div>
@endforelse
@endsection
