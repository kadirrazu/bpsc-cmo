<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Viva Board Candidate Lists</title><style>@include('viva.reports.styles')</style></head><body>
@if(!$pdf)<div class="controls"><button type="button" data-print-report>Print Board Candidate Lists</button><span> {{ $boards->count() }} boards · A4 portrait · Each board starts on a new page.</span></div><script src="{{ Vite::asset('resources/js/app.js') }}" type="module"></script>@endif
@foreach($boards as $board)
@if($pdf && !$loop->first)<pagebreak />@endif
<section class="board-section"><table class="candidate-table"><thead>@unless($pdfBodyOnly??false)<tr><td colspan="4" class="board-heading">@include('viva.reports.board-heading')</td></tr>@endunless<tr><th width="7%" class="center">SL</th><th width="25%" class="center">Registration Number</th><th width="38%" class="name">Name</th><th width="30%" class="center">Remarks / Notes</th></tr></thead><tbody>
@forelse($board->candidates as $candidate)<tr><td class="center">{{ $loop->iteration }}</td><td class="center">{{ $candidate->reg }}</td><td class="name">{{ $candidate->name }}</td><td class="notes center"></td></tr>@empty<tr><td colspan="4" class="center">No candidates in this board.</td></tr>@endforelse
</tbody></table></section>
@endforeach
</body></html>
