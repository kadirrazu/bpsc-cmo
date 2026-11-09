<!doctype html><html><head><meta charset="utf-8"><style>@page{header:html_reportheader;footer:html_reportfooter;}body{color:#233d32;font-family:dejavusans;font-size:10pt}h1{font-size:22pt;color:#176d62}h2{font-size:17pt;color:#176d62}h3{font-size:13pt}table{border-collapse:collapse;width:100%;margin:10px 0 16px;table-layout:fixed}th,td{padding:7px;border:1px solid #d8e4dd;vertical-align:top;word-wrap:break-word}th{background:#eaf4ef;text-align:left}thead{display:table-header-group}.muted{color:#677d70}.center{text-align:center}.answer-item{border-left:2px solid #d7e8df;padding-left:10px;margin:10px 0 14px}.answer-item h4{font-size:10pt;margin:0 0 5px}.answer-item p{margin:0 0 6px;line-height:1.5}.expert-heading{border-bottom:2px solid #176d62;padding-bottom:10px}.note{font-size:9pt;color:#677d70}</style></head><body>
<h1>Expert Evaluation Report</h1><p class="muted">Expert Evaluation System | Generated {{ $generated }}</p>
<table><tbody>@foreach($filters as $label=>$value)<tr><th width="25%">{{ $label }}</th><td>{{ $value }}</td></tr>@endforeach</tbody></table>
<h2>Report Overview</h2><table><thead><tr><th>Evaluated Experts</th><th>Participants</th><th>Submissions</th><th>Surveys with Responses</th></tr></thead><tbody><tr><td class="center">{{ $statistics['experts'] }}</td><td class="center">{{ $statistics['participants'] }}</td><td class="center">{{ $statistics['submissions'] }}</td><td class="center">{{ $statistics['surveys'] }}</td></tr></tbody></table><p class="note">Counts above follow report filters. Current workspace: {{ $statistics['total_surveys'] }} surveys; {{ $statistics['active_surveys'] }} active, {{ $statistics['draft_surveys'] }} draft, {{ $statistics['closed_surveys'] }} closed. Participants are unique evaluators.</p>
<h2>Survey Activity</h2><table><thead><tr><th>Survey / Current Status</th><th>Submissions</th><th>Experts</th><th>Participants</th></tr></thead><tbody>@forelse($activity as $survey)<tr><td>{{ $survey->title }}<br><span class="muted">{{ $survey->trashed() ? 'Removed' : ucfirst($survey->status) }}</span></td><td class="center">{{ $survey->submissions ?? 0 }}</td><td class="center">{{ $survey->evaluated_experts ?? 0 }}</td><td class="center">{{ $survey->participants ?? 0 }}</td></tr>@empty<tr><td colspan="4">No surveys found.</td></tr>@endforelse</tbody></table>
@forelse($experts as $expert)
<pagebreak />
<div class="expert-heading"><h1>{{ $expert->name }}</h1><p>{{ $expert->type==='departmental' ? 'Departmental Expert' : 'Expert' }}{{ $expert->designation ? ' | '.$expert->designation : '' }}{{ $expert->organization ? ' | '.$expert->organization : '' }}{{ $expert->trashed() ? ' | Removed from pool' : '' }}</p></div>
<h2>Question Summary & Rating Averages</h2><table><thead><tr><th width="38%">Question / Type / Scale</th><th>Answered</th><th>Skipped</th><th>Average</th><th width="26%">Distribution</th></tr></thead><tbody>
@forelse(collect($summary['rows'])->where('expert_id',$expert->id) as $row)<tr><td>{{ $row['prompt'] }}<br><span class="muted">{{ \App\Models\Question::TYPES[$row['type']] ?? $row['type'] }}{{ $row['type']==='rating' ? ' | '.$row['min'].'-'.$row['max'] : '' }}</span></td><td class="center">{{ $row['answered'] }}</td><td class="center">{{ $row['skipped'] }}</td><td class="center">{{ $row['average']===null?'-':number_format($row['average'],2) }}</td><td>@if(mb_strlen(json_encode($row['distribution'],JSON_UNESCAPED_UNICODE))>400)See distribution below.@else @foreach($row['distribution'] as $label=>$count){{ $label }}: {{ $count }}<br>@endforeach @endif</td></tr>@empty<tr><td colspan="5">No matching evaluation data.</td></tr>@endforelse
</tbody></table><p class="note">Skipped answers are excluded from averages. Different wording, options and rating scales remain separate. Multiple-select distributions count selections.</p>
@foreach(collect($summary['rows'])->where('expert_id',$expert->id) as $row)
@if(mb_strlen(json_encode($row['distribution'],JSON_UNESCAPED_UNICODE))>400)
<h3>Distribution: {{ $row['prompt'] }}</h3>@foreach($row['distribution'] as $label=>$count)<p>{{ $label }}: <strong>{{ $count }}</strong></p>@endforeach
@endif
@endforeach
<h2>Submissions & Answers</h2>
@forelse($evaluations->where('expert_id',$expert->id) as $submission)
<h3>#{{ $submission->id }} | {{ $submission->survey_snapshot['title'] ?? 'Initial Expert Evaluation' }}</h3><p>{{ $submission->evaluator_snapshot['name'] }} | {{ $submission->evaluator_snapshot['role'] }}<br>Viva: {{ $submission->viva_date->format('d M Y') }} | Submitted: {{ $submission->submitted_at->format('d M Y, h:i A') }}@if($submission->reference)<br>Reference: {{ $submission->reference }}@endif<br><span class="muted">Expert at submission: {{ $submission->expert_snapshot['name'] }}</span></p>
@foreach($submission->answers as $answer)
<div class="answer-item"><h4>{{ $answer['prompt'] }}</h4>@if(!empty($answer['hints']))<p class="note">{{ $answer['hints'] }}</p>@endif<p>{!! nl2br(e(app(\App\Services\Evaluation\ReportDownload::class)->answer($answer))) !!}</p></div>
@endforeach
@empty<p class="muted">No matching submissions for this expert.</p>
@endforelse
@empty<p>No experts match the selected filters.</p>
@endforelse
</body></html>
