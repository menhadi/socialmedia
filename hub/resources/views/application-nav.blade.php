<nav class="application-nav" aria-label="Breadcrumb">
<a href="{{ route('applications') }}">Applications</a><span aria-hidden="true">/</span>
@if(request()->routeIs('applications.show'))<strong aria-current="page">{{ $application->name }}</strong>@else<a href="{{ route('applications.show',$application) }}">{{ $application->name }}</a><span aria-hidden="true">/</span>
@if(request()->routeIs('posts'))<strong aria-current="page">{{ \App\Models\Post::CHANNELS[request('channel')] ?? 'All' }} posts</strong>@elseif(request()->routeIs('social'))<strong aria-current="page">Connection settings</strong>@else<strong aria-current="page">Application settings</strong>@endif
@endif
</nav>
