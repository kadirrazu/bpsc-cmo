@extends('layouts.app')
@section('title','My Viva Board')
@section('content')
<h1>My Viva Board</h1><p class="text-secondary">Your assigned board and candidate list. All dates use Bangladesh Standard Time.</p>
<form method="get" class="d-flex gap-2 align-items-end mb-4"><div><label class="form-label" for="my-viva-date">Viva Date</label><input class="form-control" id="my-viva-date" type="date" name="vdate" value="{{ $date }}" required></div><button class="btn btn-primary">View My Board</button></form>
@if($assignment)
@php($board=$assignment->board)
<div class="card card-body mb-3"><span class="eyebrow">{{ \Carbon\Carbon::parse($date)->format('d M Y') }} · BOARD {{ $board->board }}</span><h2 class="mt-2">{{ $board->post_code }} · {{ $board->post_name }}</h2>@if($board->unit !== null && $board->unit !== '')<p class="mb-1"><strong>Unit:</strong> {{ $board->unit }}</p>@endif @if($board->ministry !== null && $board->ministry !== '')<p><strong>Ministry:</strong> {{ $board->ministry }}</p>@endif<p>{{ $candidates->total() }} candidates assigned to you.</p><div class="d-flex gap-2 flex-wrap">@foreach(['xlsx'=>'Excel (.xlsx)','pdf'=>'PDF','print'=>'Print Candidate List'] as $format=>$label)<a class="btn btn-outline-primary" href="{{ route('viva.my-board.download',['vdate'=>$date,'format'=>$format]) }}" @if($format==='print') target="_blank" rel="noopener" @endif>{{ $label }}</a>@endforeach</div></div>
<div class="card"><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>#</th><th>Registration</th><th>Candidate Name</th></tr></thead><tbody>@foreach($candidates as $candidate)<tr><td>{{ $candidates->firstItem()+$loop->index }}</td><td><strong>{{ $candidate->reg }}</strong></td><td>{{ $candidate->name?:'—' }}</td></tr>@endforeach</tbody></table></div><div class="card-footer">{{ $candidates->links() }}</div></div>
@else<div class="card card-body text-center py-5"><h2>No board assigned</h2><p class="text-secondary mb-0">You have no viva board assignment for {{ \Carbon\Carbon::parse($date)->format('d M Y') }}. Check again after distribution or select another date.</p></div>@endif
@endsection
