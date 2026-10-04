@extends('layouts.app')
@section('title',$post->exists?'Edit post':'Create post')
@section('content')
@php($chartVideo = ($post->visual['type']??'') === 'chart_video')
@php($locked = $post->exists && ($post->archived_at || !in_array($post->status,['draft','reviewed']) || $post->publications()->whereIn('status',['publishing','published','uncertain'])->exists() || $post->schedules()->whereIn('status',['running','uncertain'])->exists()))
<div class="page-heading"><div><a class="back" href="{{ route('posts',['brand'=>$post->brand_id,'channel'=>$post->channel]) }}">← Content library</a><h1>Make something worth sharing.</h1><p class="muted">Save a draft, refine your message and review it when ready.</p></div></div>
@if($post->exists)
@if($chartVideo)<div class="notice"><strong>Daily historical chart video</strong><p>{{ $post->visual['heading'] }} · {{ min($post->visual['labels']) }}–{{ max($post->visual['labels']) }}. Source data is saved with this video. <a href="{{ route('trends',['brand'=>$post->brand_id]) }}">Manage or retry daily publishing</a>.</p></div>@endif
@if($post->content_type === 'trend')
<div class="notice"><strong>Trend-based draft</strong><p>{{ $post->trendRun?->reason }}</p><p><a href="{{ route('trends',['brand'=>$post->brand_id]) }}">View trend sources and captured website evidence</a></p></div>
@endif
<div class="notice"><strong>Automation & history</strong><p>{{ $post->automation_reason ?: 'No automatic schedule has been requested for this saved post.' }}</p>
@if($post->learning_note)<p>{{ $post->learning_note }}</p>@endif
@foreach($post->schedules()->latest()->limit(3)->get() as $entry)<p>{{ ucfirst($entry->status) }} · {{ $entry->scheduled_at->utc()->format('Y-m-d H:i') }} UTC · {{ $entry->reason }}</p>@endforeach
<p><a href="{{ route('automation',['brand'=>$post->brand_id,'channel'=>$post->channel]) }}">Automation rules</a> · <a href="{{ route('analytics',['post'=>$post->id]) }}">Post analytics</a></p>
@if(!$locked)<form method="post" action="{{ route('posts.assess',$post) }}">@csrf<label>Content category<select name="category"><option value="general">General</option><option value="question">Question / learning</option></select></label><label class="checkbox"><input type="checkbox" name="confirmed" value="1" required>I confirm the saved facts are accurate and authorize scheduling if checks pass.</label><button class="button secondary">Assess and schedule automatically</button></form>@endif
<form method="post" action="{{ route('posts.archive',$post) }}">@csrf<p><button class="button secondary">{{ $post->archived_at?'Restore in Content Hub':'Archive in Content Hub' }}</button></p></form><p>Archiving cancels queued publishing and hides this local record. It never deletes the platform post. Platform deletion does not delete Content Hub history.</p></div>
@endif
@if($brands->isEmpty())
    <div class="panel empty"><h2>Add an application first.</h2><p>Every post belongs to an application, keeping its audience and voice clear.</p><a class="button" href="{{ route('applications.create') }}">Add application</a></div>
@else
    @if(!$locked)<p><a class="button secondary" href="{{ route('ai', $post->exists?['post'=>$post->id]:[]) }}">✧ Open AI assistant</a></p>@endif
    @if($locked)<div class="notice">This saved version has been submitted for publishing and is locked. <a href="{{ route('posts.publish',$post) }}">View publishing history →</a></div>@endif
    <div class="editor-grid">
        <form class="panel form-panel" method="post" action="{{ $post->exists?route('posts.update',$post):route('posts.store') }}">
            @csrf @if($post->exists) @method('PUT') @endif
            <fieldset class="post-fields" @disabled($locked || $chartVideo)>
                <div class="form-grid">
                    <label>Application<select name="brand_id" required>@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected(old('brand_id',$post->brand_id)==$brand->id)>{{ $brand->name }}</option>@endforeach</select></label>
                    <label>Intended channel<select name="channel">@foreach(\App\Models\Post::CHANNELS as $value=>$label)<option value="{{ $value }}" @selected(old('channel',$post->channel)==$value)>{{ $label }}</option>@endforeach</select></label>
                </div>
                <label>Title (also sent as the YouTube video title)<input name="title" required maxlength="200" value="{{ old('title',$post->title) }}" placeholder="A name to find this post later"></label>
                <label>Post content<textarea name="body" rows="12" required maxlength="20000" placeholder="What would you like to share?">{{ old('body',$post->body) }}</textarea></label>
                <label>Source or destination link<input name="source_url" type="url" maxlength="2048" value="{{ old('source_url',$post->source_url) }}" placeholder="https://"></label>
                @if(!$chartVideo)<details @if($post->visual || old('visual')) open @endif><summary>Graph or question card</summary>
                    <p class="muted small">Keep the caption short. Supply source data below, save, then create the image. Cards use a high-resolution 2048 × 2048 PNG square with the complete content fitted inside for feed-image posts. Platform thumbnails may crop differently; Stories and Reels need a separate vertical format. Use the exact question or data page as the source link. Plain text and Unicode formulas are supported; questions needing diagrams or complex notation need a separately prepared visual.</p>
                    <label>Card format<select name="visual[type]">@foreach(['none'=>'Headline only','question'=>'Question with options','chart'=>'Data chart','collection'=>'Multiple source cards','source_report'=>'Original website report pages'] as $value=>$label)<option value="{{ $value }}" @selected(old('visual.type',$post->visual['type']??'none')===$value)>{{ $label }}</option>@endforeach</select></label>
                    <label>Original printable report URL<input type="url" name="visual[report_url]" maxlength="2048" value="{{ old('visual.report_url',$post->visual['report_url']??'') }}"><small>For original report pages, use the website’s printable report URL on the same hostname as the source link.</small></label>
                    <details><summary>Source cards and selected plan</summary>
                        <p>AI chooses relevant cards and their order from the source material supplied by your website connector. It can select one card or several, without a fixed subject or sequence. Each card keeps its original data and source.</p>
                        @if($post->card_plan_note)<p><strong>AI selection:</strong> {{ $post->card_plan_note }}</p>@endif
                        <ol>@foreach($post->visual['cards']??[] as $card)<li>{{ $card['visual']['heading']??$card['visual']['question'] }} · <a href="{{ $card['source_url'] }}" target="_blank" rel="noopener noreferrer">Source</a></li>@endforeach</ol>
                        <details><summary>Advanced: import or adjust source cards</summary>
                        <p>Usually populated by the website connector. Each entry contains source_url and a visual (chart, question or facts). Up to ten source cards; facts cards contain a heading, up to six rows and a source note. Editing these facts requires a fresh review.</p>
                        <label>Available source cards (JSON)<textarea name="source_cards_json" rows="8" maxlength="60000">{{ old('source_cards_json',$post->card_sources?json_encode($post->card_sources,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):'') }}</textarea></label>
                        <label>Selected cards in publishing order (JSON)<textarea name="cards_json" rows="8" maxlength="60000">{{ old('cards_json',isset($post->visual['cards'])?json_encode($post->visual['cards'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):'') }}</textarea></label>
                        </details>
                    </details>
                    <details><summary>Question details</summary>
                        <label>Question origin<select name="visual[question_kind]"><option value="practice" @selected(old('visual.question_kind',$post->visual['question_kind']??'practice')==='practice')>Practice / unverified</option><option value="pyp" @selected(old('visual.question_kind',$post->visual['question_kind']??'')==='pyp')>Previous-year paper question</option></select></label>
                        <label>Source paper URL<input type="url" name="visual[paper_url]" maxlength="2048" value="{{ old('visual.paper_url',$post->visual['paper_url']??'') }}"></label>
                        <input type="hidden" name="visual[provenance_verified]" value="0"><label class="checkbox"><input type="checkbox" name="visual[provenance_verified]" value="1" @checked(old('visual.provenance_verified',$post->visual['provenance_verified']??false))>I checked the source paper: this exact question, options, exam and year match.</label>
                        <label>Full question<textarea name="visual[question]" maxlength="650" rows="5">{{ old('visual.question',$post->visual['question']??'') }}</textarea></label>
                        @for($i=0;$i<6;$i++)<label>Option {{ chr(65+$i) }}<input name="visual[options][]" maxlength="150" value="{{ old('visual.options.'.$i,$post->visual['options'][$i]??'') }}"></label>@endfor
                        <label>Correct option number (kept off the image)<input type="number" min="1" max="6" name="visual[answer]" value="{{ old('visual.answer',$post->visual['answer']??'') }}"></label>
                        <p class="muted small">Provide 2–6 options in order, with no gaps. Add only known metadata. These fields generate up to six relevant hashtags when saving.</p>
                        <div class="form-grid">@foreach(['group'=>'Exam group','category'=>'Category','exam'=>'Exam name','year'=>'Exam year','topic'=>'Topic','subtopic'=>'Subtopic'] as $key=>$label)<label>{{ $label }}<input name="visual[{{ $key }}]" maxlength="45" value="{{ old('visual.'.$key,$post->visual[$key]??'') }}"></label>@endforeach</div>
                        <label>Source difficulty<select name="visual[difficulty]">@foreach([''=>'Not supplied','easy'=>'Easy','medium'=>'Medium','hard'=>'Hard'] as $value=>$label)<option value="{{ $value }}" @selected(old('visual.difficulty',$post->visual['difficulty']??'')===$value)>{{ $label }}</option>@endforeach</select></label>
                    </details>
                    <details><summary>Chart data</summary>
                        <label>Chart style<select name="visual[chart_style]"><option value="bar" @selected(old('visual.chart_style',$post->visual['chart_style']??'bar')==='bar')>Bar comparison</option><option value="line" @selected(old('visual.chart_style',$post->visual['chart_style']??'')==='line')>Historical line graph (year on horizontal axis)</option></select></label>
                        <label>Historical series: one year,value per line<textarea name="visual[history]" rows="12" maxlength="12000" placeholder="2014,67.5&#10;2019,68.81&#10;2024,70.90">{{ old('visual.history',($post->visual['chart_style']??'')==='line'?collect($post->visual['labels']??[])->map(fn($year,$i)=>$year.','.($post->visual['values'][$i]??''))->implode("\n"):'') }}</textarea></label><p class="muted small">For line graphs, paste the full available history (up to 200 years) in one unit. Years are sorted and spaced chronologically; all points are retained. A blank value marks missing data, not zero. A solid line connects surrounding recorded points; × on the year axis marks unavailable data. The connection does not estimate missing values. Selected recorded values are labelled automatically. This series takes precedence over the short comparison fields below. Include coverage limitations in the source note.</p>
                        <label>Chart heading<input name="visual[heading]" maxlength="100" value="{{ old('visual.heading',$post->visual['heading']??'') }}"></label>
                        <label>Unit (for example %, votes, students)<input name="visual[unit]" maxlength="20" value="{{ old('visual.unit',$post->visual['unit']??'') }}"></label>
                        <p class="muted small">Use 2–6 comparable values in the same unit. Bars start at zero; percentages use a 0–100 scale.</p>
                        @for($i=0;$i<6;$i++)<div class="form-grid"><label>Label {{ $i+1 }}<input name="visual[labels][]" maxlength="35" value="{{ old('visual.labels.'.$i,$post->visual['labels'][$i]??'') }}"></label><label>Value {{ $i+1 }}<input type="number" min="0" step="any" name="visual[values][]" value="{{ old('visual.values.'.$i,$post->visual['values'][$i]??'') }}"></label></div>@endfor
                        <label>Coverage / source note<textarea name="visual[note]" maxlength="180" rows="3">{{ old('visual.note',$post->visual['note']??'') }}</textarea></label>
                    </details>
                </details>
                @endif
                @if(!$locked && !$chartVideo)<p class="muted small">Editing a reviewed post returns it to draft for another review.</p><div class="actions"><button class="button">Save draft</button></div>@endif
            </fieldset>
        </form>
        <aside>
            <div class="panel preview">
                <div class="section-heading"><h3>Saved preview</h3><span class="badge {{ $post->status }}">{{ ucfirst($post->status??'draft') }}</span></div>
                @if($post->exists)
                    <div class="preview-brand"><span class="avatar">{{ mb_substr($post->brand->name,0,1) }}</span><div><strong>{{ $post->brand->name }}</strong><small>{{ \App\Models\Post::CHANNELS[$post->channel] }}</small></div></div>
                    <div class="post-body">{{ $post->body }}</div>
                    @php($mediaRemoved = $post->status === 'published' && ($post->video_path || $post->image_path) && !\Illuminate\Support\Facades\Storage::disk('local')->exists($post->video_path ?: $post->image_path))
                    @if($mediaRemoved)<p class="muted">Local media was removed after publishing to save storage. Open the published post below to view it on the platform.</p>@else
                    @if($post->video_path)<video controls preload="metadata" src="{{ route('posts.video',$post) }}" style="width:100%"></video><p><a href="{{ route('posts.video',$post) }}" download>Download video</a></p>@endif
                    @if(!$locked && !in_array($post->visual['type']??'',['collection','source_report','chart_video']))<p><a class="button secondary" href="{{ route('media',$post) }}">Create AI image or video</a></p>@endif
                    @if($post->card_images)
                        @foreach($post->card_images as $i=>$card)<p>Card {{ $i+1 }} of {{ count($post->card_images) }}</p><img src="{{ route('posts.image',['post'=>$post,'card'=>$i]) }}" alt="Card {{ $i+1 }}" style="width:100%;height:auto;border-radius:12px"><p><a href="{{ route('posts.image',['post'=>$post,'card'=>$i]) }}" download>Download card {{ $i+1 }}</a></p>@endforeach
                    @elseif($post->image_path)<img src="{{ route('posts.image',$post) }}" alt="Branded post image" style="width:100%;height:auto;border-radius:12px"><p><a href="{{ route('posts.image',$post) }}" download="post.png">Download image</a></p>@endif
                    @endif
                    @if(!$locked && $post->card_sources)<form method="post" action="{{ route('posts.cards.plan',$post) }}">@csrf<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><input type="hidden" name="fingerprint" value="{{ $post->publishingFingerprint() }}"><button class="button secondary">Let AI choose cards and caption</button></form><p class="muted small">Uses the application's AI provider and budget. Changes return this post to draft.</p>@endif
                    @if(!$locked && !$chartVideo)<form method="post" action="{{ route('posts.image.create',$post) }}">@csrf<button class="button secondary">{{ $post->image_path?'Regenerate':'Create' }} {{ ($post->visual['type']??'')==='collection' ? 'all cards' : ($post->visual ? 'content card' : 'branded image') }}</button></form><p class="muted small">Saving edits clears attached media and cancels a queued schedule. Generate media after your final edits.</p>@endif
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
