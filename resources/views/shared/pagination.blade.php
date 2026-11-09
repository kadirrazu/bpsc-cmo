<div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
<p class="text-secondary mb-0 small">{{ __(($groupedDates ?? false) ? 'Showing :first–:last of :total matching dates' : 'Showing :first–:last of :total matching records',['first'=>\App\Support\Ui::digits($paginator->firstItem() ?? 0),'last'=>\App\Support\Ui::digits($paginator->lastItem() ?? 0),'total'=>\App\Support\Ui::number($paginator->total())]) }}</p>
@if($paginator->hasPages())
@php($start=max(1,$paginator->currentPage()-2))
@php($end=min($paginator->lastPage(),$paginator->currentPage()+2))
<nav aria-label="{{ __('Result pages') }}"><ul class="pagination pagination-sm mb-0 flex-wrap">
<li class="page-item {{ $paginator->onFirstPage() ? 'disabled' : '' }}">@if($paginator->onFirstPage())<span class="page-link" aria-disabled="true">{{ __('Previous') }}</span>@else<a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>@endif</li>
@if($start>1)<li class="page-item"><a class="page-link" href="{{ $paginator->url(1) }}">{{ \App\Support\Ui::digits(1) }}</a></li>@if($start>2)<li class="page-item disabled"><span class="page-link">…</span></li>@endif @endif
@foreach($paginator->getUrlRange($start,$end) as $page=>$url)<li class="page-item {{ $page===$paginator->currentPage() ? 'active' : '' }}">@if($page===$paginator->currentPage())<span class="page-link" aria-current="page">{{ \App\Support\Ui::digits($page) }}</span>@else<a class="page-link" href="{{ $url }}">{{ \App\Support\Ui::digits($page) }}</a>@endif</li>@endforeach
@if($end<$paginator->lastPage())@if($end<$paginator->lastPage()-1)<li class="page-item disabled"><span class="page-link">…</span></li>@endif<li class="page-item"><a class="page-link" href="{{ $paginator->url($paginator->lastPage()) }}">{{ \App\Support\Ui::digits($paginator->lastPage()) }}</a></li>@endif
<li class="page-item {{ $paginator->hasMorePages() ? '' : 'disabled' }}">@if($paginator->hasMorePages())<a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>@else<span class="page-link" aria-disabled="true">{{ __('Next') }}</span>@endif</li>
</ul></nav>@endif
</div>
