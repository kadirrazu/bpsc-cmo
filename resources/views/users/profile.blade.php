@extends('layouts.app')
@section('title','My Profile')
@section('content')
<div class="mb-4"><span class="eyebrow">MY ACCOUNT</span><h1>My Profile</h1><p class="text-secondary mb-0">Update your name and sign-in email. Your designation, unit and role are managed by an Administrator.</p></div>
<form class="card card-body" method="post" action="{{ route('staff-profile.update') }}">
@csrf @method('PUT')
<div class="row g-4">
<div class="col-md-6"><label class="form-label required" for="name">Name</label><input class="form-control" id="name" name="name" value="{{ old('name',$user->name) }}" maxlength="255" required autocomplete="name"></div>
<div class="col-md-6"><label class="form-label required" for="email">Email (Sign In)</label><input class="form-control" type="email" id="email" name="email" value="{{ old('email',$user->email) }}" maxlength="255" required autocomplete="email"></div>
<div class="col-12"><hr class="my-0"></div>
<div class="col-md-4"><label class="form-label" for="profile-designation">Designation <i class="bi bi-lock small" aria-hidden="true"></i></label><input class="form-control profile-readonly" id="profile-designation" value="{{ $user->designation?->name ?? 'Not assigned' }}" readonly></div>
<div class="col-md-4"><label class="form-label" for="profile-unit">Unit <i class="bi bi-lock small" aria-hidden="true"></i></label><input class="form-control profile-readonly" id="profile-unit" value="{{ \App\Support\Ui::unit($user->unit) }}" readonly></div>
<div class="col-md-4"><label class="form-label" for="profile-role">Role <i class="bi bi-lock small" aria-hidden="true"></i></label><input class="form-control profile-readonly" id="profile-role" value="{{ $user->role->label() }}" readonly></div>
</div>
<div class="d-flex flex-wrap gap-2 mt-4"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>Save Profile</button><a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">Back</a></div>
</form>
@endsection
