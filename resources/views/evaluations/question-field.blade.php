@php
 $fieldId='answer-'.$question->id;
 $default=match($question->type){'date'=>now()->toDateString(),'time'=>'10:00',default=>null};
 $value=old('answers.'.$question->id,$default);
 if($question->type==='multiple')$value=is_array($value)?array_filter($value,'is_scalar'):[];
 elseif(!is_scalar($value) && $value!==null)$value='';
@endphp
@if($question->type==='select')
<select id="{{ $fieldId }}" name="answers[{{ $question->id }}]" class="form-select" aria-label="{{ $question->prompt }}" @required($question->is_required)><option value="">Choose an option</option>@foreach($question->options as $option)<option value="{{ $option }}" @selected((string)$value===(string)$option)>{{ $option }}</option>@endforeach</select>
@elseif($question->type==='date' || $question->type==='time')
<input type="{{ $question->type }}" id="{{ $fieldId }}" name="answers[{{ $question->id }}]" class="form-control" aria-label="{{ $question->prompt }}" value="{{ $value }}" @required($question->is_required)>@if($question->type==='time')<div class="form-hint mt-2">Default: 10:00 AM</div>@endif
@elseif($question->type==='text')
<input type="text" id="{{ $fieldId }}" name="answers[{{ $question->id }}]" class="form-control" aria-label="{{ $question->prompt }}" value="{{ $value }}" placeholder="Type your answer" maxlength="255" @required($question->is_required)>
@elseif($question->type==='textarea')
<textarea id="{{ $fieldId }}" name="answers[{{ $question->id }}]" class="form-control" aria-label="{{ $question->prompt }}" rows="4" placeholder="Share your observations" maxlength="5000" @required($question->is_required)>{{ $value }}</textarea>
@elseif($question->type==='checkbox')
<input type="hidden" name="answers[{{ $question->id }}]" value="0"><label class="answer-choice answer-choice-checkbox"><input type="checkbox" id="{{ $fieldId }}" name="answers[{{ $question->id }}]" value="1" @checked((string)$value==='1') @required($question->is_required)><span>Yes / Checked</span></label>
@elseif($question->type==='multiple')
<div data-multiple-question @if($question->is_required)data-required="true"@endif><p class="answer-guidance">Select one or more options.</p><div class="answer-choice-list">@foreach($question->options as $option)<label class="answer-choice"><input type="checkbox" name="answers[{{ $question->id }}][]" value="{{ $option }}" @checked(in_array($option,$value,true))><span>{{ $option }}</span></label>@endforeach</div></div>
@else
@if($question->type==='rating')<div class="rating-scale-caption"><span>{{ $question->rating_min??1 }} = lowest</span><span>{{ $question->rating_max??5 }} = highest</span></div>@endif
<div class="answer-choice-list {{ $question->type==='rating'?'survey-rating':'survey-radio' }}">@foreach($question->type==='rating'?range($question->rating_min??1,$question->rating_max??5):$question->options as $option)<label class="answer-choice"><input type="radio" name="answers[{{ $question->id }}]" value="{{ $option }}" @checked((string)$value===(string)$option) @required($question->is_required)><span>{{ $option }}</span></label>@endforeach @unless($question->is_required)<label class="answer-choice answer-skip"><input type="radio" name="answers[{{ $question->id }}]" value="" @checked($value===null || $value==='')><span>Skip</span></label>@endunless</div>
@endif