@extends('layouts.app')
@section('title',$title)
@section('content')
<div class="mb-4"><a class="text-secondary" href="{{ $backUrl }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Candidate Import</a><h1 class="mt-3">{{ $title }}</h1><p class="text-secondary mb-0">Review the details below. No data is removed until you submit the final confirmation.</p></div>
<div class="card"><div class="card-body">
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i><strong>This action permanently removes data.</strong> Keep a backup before continuing.</div>
<dl class="row mb-3">
<dt class="col-sm-4">Removal scope</dt><dd class="col-sm-8">{{ $mode==='all'?'All dates and all post codes':($mode==='scope'?'Selected date and post code':'Single unassigned board') }}</dd>
@if($mode!=='all')<dt class="col-sm-4">Viva date</dt><dd class="col-sm-8">{{ \Carbon\Carbon::parse($date)->format('d M Y') }}</dd><dt class="col-sm-4">Post code</dt><dd class="col-sm-8">{{ $post }}</dd>@endif
@if($board)<dt class="col-sm-4">Post name</dt><dd class="col-sm-8">{{ $board->post_name }}</dd><dt class="col-sm-4">Board</dt><dd class="col-sm-8">{{ $board->board }}</dd>@endif
<dt class="col-sm-4">Boards to remove</dt><dd class="col-sm-8">{{ $counts['boards'] }}</dd><dt class="col-sm-4">Candidates to remove</dt><dd class="col-sm-8">{{ $counts['candidates'] }}</dd><dt class="col-sm-4">Assignments to remove</dt><dd class="col-sm-8">{{ $counts['assignments'] }}</dd>
@if($mode==='all')<dt class="col-sm-4">Import previews / batches to remove</dt><dd class="col-sm-8">{{ $counts['imports'] }}</dd>@endif
</dl>
@if($mode==='scope')<p>Only this date/post combination is affected, including all matching boards beyond the current listing page. Other dates and posts remain. Pending previews containing this selection will be cancelled; re-upload those workbooks if needed.</p>
@elseif($mode==='all')<p><strong>Every imported board, candidate, assignment and import preview across all dates/posts will be removed.</strong> Listing filters do not limit this action.</p>
@else<p>Only this unassigned board and its candidates will be removed. Assigned boards are protected from individual removal.</p>@endif
<p class="text-secondary">Member pools, users, module settings, other modules and existing audit history are retained. Counts are current estimates; the server rechecks the removal conditions when you confirm.</p>
@if($canRemove)
<form method="post" action="{{ $action }}">@csrf @method('DELETE')
@if($mode==='scope')<input type="hidden" name="vdate" value="{{ $date }}"><input type="hidden" name="post_code" value="{{ $post }}">@endif
<div class="row"><div class="col-sm-6 col-lg-4">@include('viva.shared.delete-confirmation',['id'=>'confirm-candidate-removal'])</div></div>
<div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-danger"><i class="bi bi-trash" aria-hidden="true"></i> Confirm & Delete</button><a class="btn btn-outline-secondary" href="{{ $backUrl }}">Cancel</a></div>
</form>
@else<div class="alert alert-info">No imported boards currently match this removal scope.</div><a class="btn btn-outline-secondary" href="{{ $backUrl }}">Back to Candidate Import</a>@endif
</div></div>
@endsection
