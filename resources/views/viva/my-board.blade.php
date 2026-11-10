@extends('layouts.app')
@section('title','My Viva Board')
@section('content')
<h1>My Viva Board</h1><p class="text-secondary">Your assigned board and candidate list. All dates use Bangladesh Standard Time.</p>
<form method="get" class="d-flex gap-2 align-items-end mb-4"><div><label class="form-label" for="my-viva-date">Viva Date</label><input class="form-control" id="my-viva-date" type="date" name="vdate" value="{{ $date }}" required></div><button class="btn btn-primary">View My Board</button></form>
@if($assignment)
@php($board=$assignment->board)
<section class="card mb-3"><div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><h2 class="card-title mb-0">Assigned Viva Board</h2><a class="btn btn-outline-primary" href="{{ route('viva.my-board.download',['vdate'=>$date,'format'=>'pdf']) }}"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Download Candidate List (PDF)</a></div>
<div class="table-responsive"><table class="table table-vcenter mb-0" aria-label="Assigned board information"><colgroup><col style="width:17%"><col style="width:33%"><col style="width:17%"><col style="width:33%"></colgroup><tbody>
<tr><th class="text-secondary fw-medium">Viva Date</th><td><span class="badge bg-blue-lt">{{ \Carbon\Carbon::parse($date)->format('d M Y') }}</span></td><th class="text-secondary fw-medium">Board</th><td><span class="badge bg-teal-lt">{{ $board->board }}</span></td></tr>
<tr><th class="text-secondary fw-medium">Post Code</th><td><strong>{{ $board->post_code }}</strong></td><th class="text-secondary fw-medium">Candidates</th><td><span class="badge bg-secondary-lt">{{ $candidates->total() }}</span></td></tr>
<tr><th class="text-secondary fw-medium">Post Name</th><td colspan="3"><strong>{{ $board->post_name }}</strong></td></tr>
@if($board->unit !== null && $board->unit !== '')<tr><th class="text-secondary fw-medium">Unit</th><td colspan="3"><span class="badge bg-purple-lt text-wrap text-start">{{ $board->unit }}</span></td></tr>@endif
@if($board->ministry !== null && $board->ministry !== '')<tr><th class="text-secondary fw-medium">Ministry</th><td colspan="3" class="text-wrap">{{ $board->ministry }}</td></tr>@endif
</tbody></table></div></section>
<div class="card"><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>#</th><th>Registration</th><th>Candidate Name</th></tr></thead><tbody>@foreach($candidates as $candidate)<tr><td>{{ $candidates->firstItem()+$loop->index }}</td><td><strong>{{ $candidate->reg }}</strong></td><td>{{ $candidate->name?:'—' }}</td></tr>@endforeach</tbody></table></div><div class="card-footer">{{ $candidates->links() }}</div></div>
@else<div class="card card-body text-center py-5"><h2>No board assigned</h2><p class="text-secondary mb-0">You have no viva board assignment for {{ \Carbon\Carbon::parse($date)->format('d M Y') }}. Check again after distribution or select another date.</p></div>@endif
@endsection
