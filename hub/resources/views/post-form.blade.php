@extends('layouts.app')
@section('title',$post->exists?'Edit post':'Create post')
@section('content')
@php($locked = $post->exists && (!in_array($post->status,['draft','reviewed']) || $post->publications()->whereIn('status',['publishing','published','uncertain'])->exists() || $post->schedules()->whereIn('status',['running','uncertain'])->exists()))
<div class="page-heading"><div><a class="back" href="{{ route('posts') }}">← Content library</a><h1>Make something worth sharing.</h1><p class="muted">Save a draft, refine your message and review it when ready.</p></div></div>
@if($brands->isEmpty())
    <div class="panel empty"><h2>Add an application first.</h2><p>Every post belongs to an application, keeping its audience and voice clear.</p><a class="button" href="{{ route('applications.create') }}">Add application</a></div>
@else
    @if(!$locked)<p><a class="button secondary" href="{{ route('ai', $post->exists?['post'=>$post->id]:[]) }}">✧ Open AI assistant</a></p>@endif
    @if($locked)<div class="notice">This saved version has been submitted for publishing and is locked. <a href="{{ route('posts.publish',$post) }}">View publishing history →</a></div>@endif
    <div class="editor-grid">
        <form class="panel form-panel" method="post" action="{{ $post->exists?route('posts.update',$post):route('posts.store') }}">
            @csrf @if($post->exists) @method('PUT') @endif
            <fieldset class="post-fields" @disabled($locked)>
                <div class="form-grid">
                    <label>Application<select name="brand_id" required>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(old('brand_id',$post->brand_id)==$brand->id)>{{ $brand->name }}</option>@endforeach</select></label>
                    <label>Intended channel<select name="channel">@foreach(\App\Models\Post::CHANNELS as $value=>$label)<option value="{{ $value }}" @selected(old('channel',$post->channel)==$value)>{{ $label }}</option>@endforeach</select></label>
                </div>
                <label>Title (also sent as the YouTube video title)<input name="title" required maxlength="200" value="{{ old('title',$post->title) }}" placeholder="A name to find this post later"></label>
                <label>Post content<textarea name="body" rows="12" required maxlength="20000" placeholder="What would you like to share?">{{ old('body',$post->body) }}</textarea></label>
                <label>Source or destination link<input name="source_url" type="url" maxlength="2048" value="{{ old('source_url',$post->source_url) }}" placeholder="https://"></label>
                @if(!$locked)<p class="muted small">Editing a reviewed post returns it to draft for another review.</p><div class="actions"><button class="button">Save draft</button></div>@endif
            </fieldset>
        </form>
        <aside>
            <div class="panel preview">
                <div class="section-heading"><h3>Saved preview</h3><span class="badge {{ $post->status }}">{{ ucfirst($post->status??'draft') }}</span></div>
                @if($post->exists)
                    <div class="preview-brand"><span class="avatar">{{ mb_substr($post->brand->name,0,1) }}</span><div><strong>{{ $post->brand->name }}</strong><small>{{ \App\Models\Post::CHANNELS[$post->channel] }}</small></div></div>
                    <div class="post-body">{{ $post->body }}</div>
                    @if($post->video_path)<video controls preload="metadata" src="{{ route('posts.video',$post) }}" style="width:100%"></video><p><a href="{{ route('posts.video',$post) }}" download>Download video</a></p>@endif
                    @if(!$locked)<p><a class="button secondary" href="{{ route('media',$post) }}">Create AI image or video</a></p>@endif
                    @if($post->image_path)<img src="{{ route('posts.image',$post) }}" alt="Branded post image" style="width:100%;height:auto;border-radius:12px"><p><a href="{{ route('posts.image',$post) }}" download="post.png">Download image</a></p>@endif
                    @if(!$locked)<form method="post" action="{{ route('posts.image.create',$post) }}">@csrf<button class="button secondary">{{ $post->image_path?'Regenerate':'Create' }} branded image</button></form><p class="muted small">Saving edits clears attached media and cancels a queued schedule. Generate media after your final edits.</p>@endif
                    @if($post->source_url)<a class="source-link" href="{{ $post->source_url }}" target="_blank" rel="noopener noreferrer">{{ $post->source_url }}</a>@endif
                    <p class="muted small">Preview shows the last saved version.</p>
                    @if($post->status==='draft' && !$locked)<form method="post" action="{{ route('posts.review',$post) }}">@csrf<button class="button secondary full">Mark saved version as reviewed</button></form>@endif
                @else
                    <p class="muted">Save your draft to preview and review it here.</p>
                @endif
            </div>
            <div class="notice">
                <strong>Channel publishing</strong>
                @if($post->exists && \App\Services\Social\ChannelRules::supported($post->channel))
                    <p>Review the saved version, then choose a verified account for this application. You can choose whether to share the saved link.</p>
                    <p><a class="button secondary full" href="{{ route('posts.publish',$post) }}">{{ $locked?'View publishing history':'Open publishing preview' }}</a></p>
                @else
                    <p>Choose a channel, create your text and media, then review and publish or schedule it. YouTube needs a video; WhatsApp needs a recipient.</p>
                    <p><a href="{{ route('social') }}">Manage account connections →</a></p>
                @endif
            </div>
        </aside>
    </div>
@endif
@endsection
