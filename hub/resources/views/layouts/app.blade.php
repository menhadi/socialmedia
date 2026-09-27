<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title','Overview') · Content Hub</title><link rel="stylesheet" href="{{ asset('hub.css') }}"></head>
<body><div class="shell">
<aside class="sidebar"><a class="brandmark" href="{{ route('dashboard') }}"><span class="logo">c</span> content<span class="brand-light">hub</span></a><div class="nav-label">WORKSPACE</div>
<nav aria-label="Main navigation">@foreach(['dashboard'=>['◈','Overview'],'applications'=>['▦','Applications'],'posts'=>['▤','Posts'],'ai'=>['✧','AI assistant'],'providers'=>['⚙','AI providers'],'social'=>['↗','Social accounts'],'monitoring'=>['◎','Monitoring'],'research'=>['◉','Research & automation'],'schedules'=>['◷','Publishing queue'],'automation'=>['↻','Content automation'],'analytics'=>['▥','Analytics']] as $route=>$item)<a class="{{ request()->routeIs($route,$route.'.*')?'active':'' }}" href="{{ route($route) }}"><span aria-hidden="true">{{ $item[0] }}</span>{{ $item[1] }}</a>@endforeach</nav>
<div class="sidebar-note"><span class="small-label">YOUR CONTENT, CONNECTED</span><p>One workspace.<br>Every application.</p><small>Build your content library now. Connect publishing when you’re ready.</small></div>
<div class="account"><span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>Workspace owner</small></div><form method="post" action="{{ route('logout') }}">@csrf<button class="signout" aria-label="Sign out" title="Sign out">↪</button></form></div></aside>
<main><header class="topbar"><span>Workspace <span class="slash">/</span> @yield('title','Overview')</span><span class="pill">Foundation preview</span></header><div class="content">
@if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><strong>Please check these details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</div></main></div></body></html>
