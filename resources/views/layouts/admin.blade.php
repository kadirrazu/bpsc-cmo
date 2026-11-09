<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title',__('Dashboard')) | {{ config('app.name') }}</title>
@include('shared.favicon')
@vite(['resources/css/app.css','resources/js/app.js'])@stack('styles')
</head>
<body><div class="page library-shell">
<header class="library-header d-print-none">
    <div class="container-xl library-masthead">
        <a href="{{ route('dashboard') }}" class="library-product text-decoration-none"><span class="brand-emblem"><i class="bi bi-person-check" aria-hidden="true"></i></span><span class="product-name">{{ config('app.name') }}</span></a>
        <div class="library-commission" lang="en"><strong>Bangladesh Public Service Commission (BPSC)</strong></div>
    </div>
    <nav class="navbar navbar-expand-md library-navigation" aria-label="{{ __('Toggle navigation') }}">
        <div class="container-xl">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu" aria-controls="navbar-menu" aria-expanded="false" aria-label="{{ __('Toggle navigation') }}"><span class="navbar-toggler-icon"></span></button>
            <div class="navbar-nav flex-row order-md-last align-items-center gap-2 library-account">
                
                <div class="nav-item dropdown">
                    <button type="button" class="nav-link dropdown-toggle border-0 bg-transparent" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('User menu') }}"><span class="avatar avatar-sm">{{ mb_substr(auth()->user()->name,0,1) }}</span><span class="account-identity"><strong class="account-name" title="{{ auth()->user()->name }}">{{ auth()->user()->name }}</strong><span class="account-designation" title="{{ auth()->user()->designation?->name ?? __('Not assigned') }}">{{ auth()->user()->designation?->name ?? __('Not assigned') }}</span></span></button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <div class="px-3 py-2"><strong>{{ auth()->user()->name }}</strong><div class="small text-secondary">{{ auth()->user()->designation?->name ?? __('Not assigned') }}</div><div class="small text-secondary">{{ \App\Support\Ui::unit(auth()->user()->unit) }}</div></div><div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="{{ route('staff-profile.edit') }}"><i class="bi bi-person-circle" aria-hidden="true"></i>{{ __('My Profile') }}</a>
                        <a class="dropdown-item" href="{{ route('staff-profile.password') }}"><i class="bi bi-key" aria-hidden="true"></i>{{ __('Change Password') }}</a>
                        <form action="{{ route('logout') }}" method="post">@csrf<button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>{{ __('Sign out') }}</button></form>
                    </div>
                </div>
            </div>
            <div class="collapse navbar-collapse" id="navbar-menu"><ul class="navbar-nav gap-md-2">
<li class="nav-item"><a class="nav-link {{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}"><i class="bi bi-grid-1x2"></i>Dashboard</a></li>
@can('manage-system')
<li class="nav-item dropdown"><button class="nav-link dropdown-toggle border-0 bg-transparent" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-sliders2"></i>Administration</button><div class="dropdown-menu administration-menu">
<h6 class="dropdown-header">People & Organization</h6>
<a class="dropdown-item" href="{{ route('users.index') }}">Users</a><a class="dropdown-item" href="{{ route('designations.index') }}">Designations</a><a class="dropdown-item" href="{{ route('units.index') }}">Units</a><div class="dropdown-divider"></div><h6 class="dropdown-header">Access & Accountability</h6><a class="dropdown-item" href="{{ route('roles.index') }}">Roles & Permissions</a><a class="dropdown-item" href="{{ route('audit-logs.index') }}">Audit Logs</a><div class="dropdown-divider"></div><h6 class="dropdown-header">Data Management</h6><a class="dropdown-item" href="{{ route('transfers.index') }}"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Excel Import &amp; Export</a></div></li>
@endcan
</ul></div>
        </div>
    </nav>
</header>
@if(request()->is('modules/evaluation*'))@include('modules.evaluation.navigation')@endif
@if(request()->is('modules/viva*'))@include('viva.navigation')@endif
<div class="page-wrapper"><main class="page-body"><div class="container-xl">
@if(session('success'))<div class="alert alert-success" role="alert">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</div></main>
@include('shared.footer')
</div></div>@stack('scripts')</body></html>
