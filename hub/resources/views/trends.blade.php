@extends('layouts.app')
@section('title','Trend posts')
@section('content')
<div class="page-heading"><div><div class="eyebrow">DAILY DISCOVERY</div><h1>Relevant trends. Useful posts.</h1><p class="muted">Discover on each platform, match your website, and publish inside your audience's posting window.</p></div></div>
<form method="get" class="posts-filters"><label>Application<select name="brand" onchange="this.form.submit()">@foreach($brands as $brand)<option value="{{ $brand->id }}" @selected($application?->id === $brand->id)>{{ $brand->name }}</option>@endforeach</select></label><button class="button secondary">Open</button></form>
@if($application)
<div class="panel form-panel"><h2>How automatic trend posts work</h2><p>Each enabled platform is checked once per local day. Topics must match your keywords and a useful website page. AI selects a relevant angle and an exact website excerpt. Automatic posts use that excerpt and your website link, avoiding unverified news claims. No suitable topic means no post.</p><p>Posts use your preferred time within the audience window, with at least one hour between queued posts. Enough comparable account analytics can guide the time within that window. At delivery, the topic, website evidence, credentials and settings are checked again. Expired topics and closed posting windows are held, never silently posted later.</p><p>Instagram gets an original branded image when needed. Video uses your configured Media provider and budget; unavailable media holds the post. Source-platform images and videos are not copied.</p><p class="muted">Daily worker heartbeat: {{ \Illuminate\Support\Facades\Cache::get('trend-workflow-heartbeat') ?: 'Not seen yet' }}. The server scheduler must be running.</p></div>
<h2>Platform settings</h2>
@forelse($accounts as $account)
@php
$rule = $rules->firstWhere('channel',$account->provider);
$ownedRule = $rule?->social_account_id === $account->id ? $rule : null;
$platformSettings = $ownedRule?->options['trend'] ?? [];
$supported = in_array($account->provider,['facebook','instagram','x','youtube']);
@endphp
<div class="panel form-panel"><h3>{{ \App\Models\Post::CHANNELS[$account->provider] ?? $account->provider }} · {{ $account->page_name ?: $account->page_id }}</h3>
<p>{{ $account->verified_at ? 'Account verified' : 'Account needs verification' }}@if($rule && !$ownedRule) · This platform's rule currently uses another account; saving here replaces that destination.@endif</p>
<p>@switch($account->provider)
@case('x')Current X location trends. API trend access and credits are required.@break
@case('youtube')Recent relevant YouTube videos ranked by reported views; this is a niche signal, not a global viral chart. Original video generation is required to publish.@break
@case('instagram')Recent media for your relevant hashtags, ranked by reported engagement, subject to approved public-content access with Facebook Login. This is not a global Instagram trend chart.@break
@case('facebook')Recent engagement on your own Facebook Page. Facebook-wide trend discovery is not available in this connector.@break
@default Platform trend discovery is not supported by this connector; this account cannot be enabled here.
@endswitch</p>
@if($supported)
<form method="post" action="{{ route('trends.accounts.save',$account) }}">@csrf @method('PUT')
<label class="checkbox"><input type="checkbox" name="enabled" value="1" @checked($ownedRule?->enabled)>Enable daily platform checks</label>
<div class="form-grid"><label>Workflow<select name="workflow"><option value="review" @selected(($ownedRule?->options['workflow'] ?? '')!=='automatic')>Draft for review</option><option value="automatic" @selected(($ownedRule?->options['workflow'] ?? '')==='automatic')>Automatically schedule and publish</option></select></label><label>Timezone<input name="timezone" value="{{ $platformSettings['timezone'] ?? 'Asia/Kolkata' }}" required></label><label>Daily discovery time<input type="time" name="daily_time" value="{{ $platformSettings['daily_time'] ?? '09:00' }}" required></label></div>
<div class="form-grid"><label>Audience window starts<input type="time" name="window_start" value="{{ $platformSettings['window_start'] ?? '09:00' }}" required></label><label>Audience window ends<input type="time" name="window_end" value="{{ $platformSettings['window_end'] ?? '21:00' }}" required></label><label>Preferred posting time<input type="time" name="preferred_time" value="{{ $platformSettings['preferred_time'] ?? '18:00' }}" required></label></div>
<label>Relevant keywords<input name="keywords" maxlength="500" value="{{ $platformSettings['keywords'] ?? '' }}" required placeholder="Your website's subjects, comma-separated"></label>
<div class="form-grid"><label>Country code<input name="region" maxlength="2" value="{{ $platformSettings['region'] ?? 'IN' }}" required></label>
@if($account->provider==='x')<label>X location WOEID<input type="number" name="woeid" min="1" value="{{ $platformSettings['woeid'] ?? 23424848 }}"><small>23424848 is India; use your audience's location.</small></label>@endif
@if($account->provider==='youtube')<label>Minimum reported views<input type="number" name="minimum_views" min="1" value="{{ $platformSettings['minimum_views'] ?? 100 }}"></label>@endif
@if($account->provider==='instagram')<label>Relevant hashtags<input name="hashtags" maxlength="200" value="{{ $platformSettings['hashtags'] ?? '' }}" placeholder="gate, aerospace, exam" required><small>Up to three, comma-separated; public-content permissions required.</small></label>@endif
</div>
<label>Media<select name="media_kind">@foreach(['none'=>'Text and website link','image'=>'Original branded image','video'=>'Original generated video'] as $kind=>$label)<option value="{{ $kind }}" @selected(($ownedRule?->options['media_kind'] ?? ($account->provider==='youtube'?'video':($account->provider==='instagram'?'image':'none')))===$kind)>{{ $label }}</option>@endforeach</select><small>YouTube requires video. Instagram requires an image or video.</small></label>
@if($account->provider==='youtube')<label class="checkbox"><input type="checkbox" name="made_for_kids" value="1" @checked($ownedRule?->options['made_for_kids'] ?? false)>YouTube content is made for kids</label><p>Automatic YouTube videos are published publicly. Confirm this audience classification for your content.</p>@endif
<label>Relevant website pages<textarea name="landing_pages" rows="3" required>{{ implode("\n",$platformSettings['landing_pages'] ?? array_filter([$application->website])) }}</textarea><small>1–3 final public URLs on the configured website hostname.</small></label>
<p>One attempt per platform rule per local day; maximum one automatic trend post per account per local day. Repeated topics are skipped for 30 days. Saving increments the rule version and holds older schedules.</p><button class="button">Save platform settings</button>
</form>
@if($ownedRule?->enabled)<form method="post" action="{{ route('trends.accounts.generate',$ownedRule) }}">@csrf<p><button class="button secondary">Check this platform now</button></p></form>@endif
@endif</div>
@empty<div class="panel empty"><p>Connect and verify social accounts to configure platform discovery.</p><a href="{{ url('/social-accounts') }}">Open social accounts</a></div>@endforelse
@php($settings = $application->trend_settings ?? [])
<details><summary>Optional search/feed drafts for manual review</summary>
<div class="panel form-panel">
<h2>{{ $application->name }} · Daily trend settings</h2>
<p>Google Trends supplies current search signals. An optional niche RSS/Atom feed can supply recent topics; those are labeled as unverified popularity. Only dated items from the last 48 hours qualify. No suitable topic means no post.</p>
<form method="post" action="{{ route('trends.save',$application) }}">@csrf @method('PUT')
<label class="checkbox"><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$settings['enabled'] ?? false))>Enable daily trend drafts</label>
<div class="form-grid">
<label>Country code<input name="region" value="{{ old('region',$settings['region'] ?? 'IN') }}" maxlength="2" required><small>Two-letter Google Trends region, e.g. IN or US.</small></label>
<label>Platform<select name="channel">@foreach(\App\Models\Post::CHANNELS as $key=>$label)<option value="{{ $key }}" @selected(old('channel',$settings['channel'] ?? 'facebook')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Daily check time<input type="time" name="daily_time" value="{{ old('daily_time',$settings['daily_time'] ?? '08:00') }}" required></label>
<label>Timezone<input name="timezone" value="{{ old('timezone',$settings['timezone'] ?? 'Asia/Kolkata') }}" required></label>
</div>
<label>Relevant topic keywords<input name="keywords" value="{{ old('keywords',$settings['keywords'] ?? '') }}" placeholder="GATE, aerospace, mock test, exam preparation" maxlength="500" required><small>Comma-separated. A candidate must match at least one keyword; AI then checks the website and audience fit.</small></label>
<label>Optional niche feed URL<input type="url" name="feed_url" value="{{ old('feed_url',$settings['feed_url'] ?? '') }}" placeholder="https://example.com/feed.xml"></label>
<label>Website pages to link<textarea name="landing_pages" rows="3" required>{{ old('landing_pages',implode("\n",$settings['landing_pages'] ?? array_filter([$application->website]))) }}</textarea><small>1–3 final public URLs, one per line, on {{ parse_url($application->website ?? '',PHP_URL_HOST) ?: 'your configured website' }}. Each page is fetched to confirm its content.</small></label>
<p>One attempt per website per local day, including manual checks. Topics already drafted in the last 30 days are skipped. AI uses the application's language, voice and configured provider budget. Drafts need review; Instagram and YouTube also need suitable media attached before publishing.</p>
<button class="button">Save settings</button>
</form>
@if($settings['enabled'] ?? false)<form method="post" action="{{ route('trends.generate',$application) }}">@csrf<p><button class="button secondary">Check today's trends now</button></p></form>@endif
<p class="muted">Daily checks run through the existing Laravel scheduler. Last trend-worker heartbeat: {{ \Illuminate\Support\Facades\Cache::get('trend-workflow-heartbeat') ?: 'Not seen yet' }}.</p>
</div>
</details>
<h2>Discovery history</h2>
@forelse($runs as $run)
<div class="panel form-panel">
<h3>{{ $run->run_date->format('d M Y') }} · {{ ucfirst($run->status) }} · {{ $run->evidence['platform'] ?? 'Search / feed' }}</h3><p>{{ $run->reason }}</p>
@if($run->post)<p><a class="button secondary" href="{{ route('posts.edit',$run->post) }}">Review {{ $run->post->title }}</a></p>@endif
@if($run->ai_generation_id)<p><a href="{{ route('ai.show',$run->ai_generation_id) }}">AI request history</a></p>@endif
@if($run->package['topic'] ?? null)<p><strong>{{ $run->package['topic'] }}</strong> · {{ $run->package['signal'] ?? '' }}</p><p>Website fit: {{ $run->package['reason'] ?? '' }}</p>@endif
<details><summary>Sources and evidence</summary><p>Retrieved: {{ $run->evidence['retrieved_at'] ?? 'Not captured' }}</p>
@foreach($run->evidence['errors'] ?? [] as $error)<p>{{ $error }}</p>@endforeach
@foreach($run->evidence['candidates'] ?? [] as $candidate)<p><a href="{{ $candidate['url'] }}" target="_blank" rel="noopener noreferrer">{{ $candidate['topic'] }}</a> · {{ $candidate['signal'] }} · {{ $candidate['published_at'] }} @if($candidate['traffic'])· Reported traffic: {{ $candidate['traffic'] }}@endif</p><p>{{ $candidate['summary'] }}</p>@endforeach
@foreach($run->evidence['pages'] ?? [] as $page)<p><a href="{{ $page['url'] }}" target="_blank" rel="noopener noreferrer">{{ $page['url'] }}</a></p><p>{{ $page['text'] }}</p>@endforeach
@if($run->package)<pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{ json_encode($run->package,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>@endif
</details>
</div>
@empty<div class="panel empty"><p>No trend checks yet. Save your settings to get started.</p></div>@endforelse
{{ $runs->links() }}
@else
<div class="panel empty"><p>Add an application with a website before configuring trend posts.</p><a class="button" href="{{ route('applications.create') }}">Add application</a></div>
@endif
@endsection
