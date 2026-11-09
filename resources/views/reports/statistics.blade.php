<div class="row g-3 mb-4 report-statistics">
@foreach([
 ['Total Surveys',$statistics['total_surveys'],'Workspace · '.$statistics['draft_surveys'].' draft / '.$statistics['closed_surveys'].' closed','bi-clipboard-data'],
 ['Active Surveys',$statistics['active_surveys'],'Workspace · currently open','bi-broadcast'],
 ['Evaluated Experts',$statistics['experts'],'Matching report filters','bi-person-check'],
 ['Participants',$statistics['participants'],'Unique evaluators · matching filters','bi-people'],
 ['Submissions',$statistics['submissions'],'Matching report filters','bi-send-check'],
 ['Surveys with Responses',$statistics['surveys'],'Matching report filters','bi-ui-checks']
] as [$label,$value,$hint,$icon])
<div class="col-sm-6 col-xl-4"><div class="card card-body h-100"><div class="d-flex align-items-start justify-content-between gap-3"><div><span class="text-secondary">{{ $label }}</span><strong class="d-block fs-1 my-1">{{ number_format($value) }}</strong><span class="small text-secondary">{{ $hint }}</span></div><span class="survey-section-icon" aria-hidden="true"><i class="bi {{ $icon }}"></i></span></div></div></div>
@endforeach
</div>
