@extends('layouts.app')
@section('title','Expert Reports · Report Center')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><span class="eyebrow">REPORT CENTER</span><h1 class="mb-1">Expert Evaluation Reports</h1><p class="text-secondary mb-0">Statistics, survey activity and report downloads for Administrator and Chairman.</p></div><a class="btn btn-primary" href="{{ route('evaluation.reports',array_diff_key($filters,array_flip(['export','expert_page','submissions_page','activity_page','per_page']))) }}"><i class="bi bi-journal-text"></i> View Detailed Reports</a></div>
@include('reports.filters')
@include('reports.statistics')
@include('reports.download')
<div class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-outline-primary btn-sm" href="{{ route('evaluation.reports',array_merge(array_diff_key($filters,array_flip(['expert_page','submissions_page','activity_page'])),['export'=>'summary'])) }}">Export Summary CSV</a><a class="btn btn-outline-primary btn-sm" href="{{ route('evaluation.reports',array_merge(array_diff_key($filters,array_flip(['expert_page','submissions_page','activity_page'])),['export'=>'submissions'])) }}">Export Submissions CSV</a></div>
<p class="small text-secondary mb-4">CSV exports include all records matching the selected filters, across matching experts.</p>
@include('reports.activity')
@endsection
