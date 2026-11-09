@extends('layouts.app')
@section('title',__('Create User'))
@section('content')
<div class="d-flex justify-content-between mb-3"><h1>{{ __('Create User') }}</h1><a class="btn btn-outline-secondary align-self-start" href="{{ route('users.index') }}">{{ __('Back to Users') }}</a></div>
<form class="card" method="post" action="{{ route('users.store') }}">@csrf<div class="card-body">@include('users._form')</div><div class="card-footer text-end"><button class="btn btn-primary">{{ __('Create User') }}</button></div></form>
@endsection
