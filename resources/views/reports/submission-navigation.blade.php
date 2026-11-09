@php
 $start=max(1,$submissions->currentPage()-2);
 $end=min($submissions->lastPage(),$submissions->currentPage()+2);
@endphp
<nav class="report-submission-navigation" aria-label="Submission pages {{ $location }}"><div class="small text-secondary">Showing {{ $submissions->firstItem()??0 }}–{{ $submissions->lastItem()??0 }} of {{ $submissions->total() }} submissions <span class="d-block">Page {{ $submissions->currentPage() }} of {{ $submissions->lastPage() }} · 20 per page</span></div><ul class="pagination pagination-sm mb-0 flex-wrap">
<li class="page-item {{ $submissions->onFirstPage()?'disabled':'' }}">@if($submissions->onFirstPage())<span class="page-link" aria-disabled="true">Previous</span>@else<a class="page-link" href="{{ $submissions->previousPageUrl() }}" rel="prev">Previous</a>@endif</li>
@if($start>1)<li class="page-item"><a class="page-link" href="{{ $submissions->url(1) }}">1</a></li>@if($start>2)<li class="page-item disabled"><span class="page-link">…</span></li>@endif @endif
@foreach($submissions->getUrlRange($start,$end) as $page=>$url)<li class="page-item {{ $page===$submissions->currentPage()?'active':'' }}">@if($page===$submissions->currentPage())<span class="page-link" aria-current="page">{{ $page }}</span>@else<a class="page-link" href="{{ $url }}">{{ $page }}</a>@endif</li>@endforeach
@if($end<$submissions->lastPage())@if($end<$submissions->lastPage()-1)<li class="page-item disabled"><span class="page-link">…</span></li>@endif <li class="page-item"><a class="page-link" href="{{ $submissions->url($submissions->lastPage()) }}">{{ $submissions->lastPage() }}</a></li>@endif
<li class="page-item {{ $submissions->hasMorePages()?'':'disabled' }}">@if($submissions->hasMorePages())<a class="page-link" href="{{ $submissions->nextPageUrl() }}" rel="next">Next</a>@else<span class="page-link" aria-disabled="true">Next</span>@endif</li>
</ul></nav>
