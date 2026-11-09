@extends('layouts.auth')

@section('title', __('Login'))

@section('content')
    <div class="card card-md">
        <div class="card-body">
            <h2 class="h2 text-center mb-4">
                {{ __('Sign in to your account') }}
            </h2>

            @if (session('status'))
                <div class="alert alert-success" role="alert">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0 ps-3">
                        @foreach (array_unique($errors->all()) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form
                action="{{ route('login') }}"
                method="POST"
                autocomplete="off"
                novalidate
            >
                @csrf
                <div class="row g-3">

                <div class="col-12">
                    <label
                        for="email"
                        class="form-label required"
                    ><i class="bi bi-envelope" aria-hidden="true"></i> 
                        {{ __('Email address') }}
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="{{ old('email') }}"
                        class="form-control @error('email') is-invalid @enderror"
                        placeholder="{{ __('your-email@example.com') }}"
                        autocomplete="username"
                        autofocus
                        required
                    >

                </div>

                <div class="col-12">
                    <label
                        for="password"
                        class="form-label required"
                    ><i class="bi bi-lock" aria-hidden="true"></i> 
                        {{ __('Password') }}
                    </label>

                    <div class="password-control"><input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control @error('password') is-invalid @enderror"
                        placeholder="{{ __('Enter your password') }}"
                        autocomplete="current-password"
                        required
                    ><button type="button" class="password-toggle" data-password-toggle data-show-label="{{ __('Show password') }}" data-hide-label="{{ __('Hide password') }}" aria-label="{{ __('Show password') }}" title="{{ __('Show password') }}" aria-pressed="false" hidden><i class="bi bi-eye" aria-hidden="true"></i></button></div>

                </div>

                <div class="col-12 form-footer mt-3">
                    <button
                        type="submit"
                        class="btn btn-primary w-100"
                    ><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> {{ __('Sign in') }}
                    </button>
                </div>
                </div>
            </form>
        </div>
    </div>

@endsection