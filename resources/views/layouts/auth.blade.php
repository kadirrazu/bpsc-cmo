<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover"
    >

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>
        @yield('title', __('Authentication')) | {{ config('app.name') }}
    </title>

    @include('shared.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
</head>

<body class="d-flex flex-column library-auth">
    <main class="auth-main">
        <div class="container @yield('auth-container-class', 'container-tight') py-4">
            <div class="auth-brand product-name mb-2"><i class="bi bi-person-check" aria-hidden="true"></i> 
                {{ config('app.name') }}
            </div>

            <div class="auth-subtitle mb-4">
                <strong lang="en">Bangladesh Public Service Commission (BPSC)</strong>
            </div>

            @yield('content')
        </div>
    </main>

    @include('shared.footer')
    @stack('scripts')
</body>
</html>