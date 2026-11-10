<div class="report-title">Viva Board Candidate List</div>
<table class="report-meta"><colgroup><col style="width:17%"><col style="width:33%"><col style="width:17%"><col style="width:33%"></colgroup><tbody>
@if(!$pdf)<tr><td colspan="4" class="commission">Bangladesh Public Service Commission (BPSC)</td></tr>@endif
@if($board->unit !== null && $board->unit !== '')<tr><th width="17%">Unit</th><td colspan="3" width="83%">{{ $board->unit }}</td></tr>@endif
@if($board->ministry !== null && $board->ministry !== '')<tr><th width="17%">Ministry</th><td colspan="3" width="83%">{{ $board->ministry }}</td></tr>@endif
<tr><th width="17%">Post Name</th><td colspan="3" width="83%" class="post-name">{{ $board->post_name }}</td></tr>
<tr><th width="17%">Post Code</th><td width="33%">{{ $board->post_code }}</td><th width="17%">Board</th><td width="33%">{{ $board->board }}</td></tr>
<tr><th width="17%">Viva Date</th><td width="33%">{{ $board->vdate->format('d M Y') }}</td><th width="17%">Candidates</th><td width="33%">{{ $board->candidates->count() }}</td></tr>
@if(isset($member))<tr><th width="17%">Member Name</th><td colspan="3" width="83%">{{ $member['name'] }}</td></tr>
@foreach(\App\Services\Viva\Workspace::DETAILS as $key=>$label)@if(isset($member[$key]) && $member[$key]!=='')<tr><th width="17%">{{ $label }}</th><td colspan="3" width="83%">{{ $member[$key] }}</td></tr>@endif @endforeach
@endif
</tbody></table>
