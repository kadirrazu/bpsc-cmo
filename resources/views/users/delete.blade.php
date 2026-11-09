@extends('layouts.app')
@section('title',__('Delete User'))
@section('content')
<div class="card card-body"><h1 class="h2">{{ __('Delete User') }}</h1><p><strong>{{ $user->name }}</strong> · {{ __($user->designation?->name ?? 'Not assigned') }}</p><div class="alert alert-danger">{{ __('This account will be removed from the user list and cannot sign in again. Audit history will remain.') }}</div>
<form method="post" action="{{ route('users.destroy',$user) }}">@csrf @method('DELETE')<label class="form-label" for="confirmation"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> {{ __('Type DELETE to confirm') }}</label><input class="form-control mb-3" style="max-width:320px" id="confirmation" name="confirmation" autocomplete="off" required><div class="d-flex gap-2"><a href="{{ route('users.index') }}" class="btn btn-outline-secondary">{{ __('Back to Users') }}</a><button class="btn btn-danger">{{ __('Delete User') }}</button></div></form></div>
@endsection
