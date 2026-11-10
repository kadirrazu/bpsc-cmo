@extends('layouts.app')
@section('title','Delete Member Pool')
@section('content')
<div class="mb-4"><a class="text-secondary" href="{{ route('viva.pools.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Member Pools</a><h1 class="mt-3">Delete Member Pool</h1><p class="text-secondary mb-0">Review the selected pool before confirming its removal.</p></div>
<div class="card"><div class="card-body">
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>This pool will be removed from the available member pools. Existing board assignments, candidates and user accounts will be retained.</div>
<dl class="row mb-3"><dt class="col-sm-4">Pool name</dt><dd class="col-sm-8">{{ $pool->name }}</dd><dt class="col-sm-4">Status</dt><dd class="col-sm-8">{{ $pool->is_active?'Active':'Inactive' }}</dd><dt class="col-sm-4">Enrolled members</dt><dd class="col-sm-8">{{ $pool->users_count }}</dd><dt class="col-sm-4">Existing assignments retained</dt><dd class="col-sm-8">{{ $assignmentCount }}</dd>@if($pool->description)<dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $pool->description }}</dd>@endif</dl>
<form method="post" action="{{ route('viva.pools.destroy',$pool) }}">@csrf @method('DELETE')<div class="row"><div class="col-sm-6 col-lg-4">@include('viva.shared.delete-confirmation',['id'=>'confirm-pool-removal'])</div></div><div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-danger"><i class="bi bi-trash" aria-hidden="true"></i> Confirm & Delete Pool</button><a class="btn btn-outline-secondary" href="{{ route('viva.pools.index') }}">Cancel</a></div></form>
</div></div>
@endsection
