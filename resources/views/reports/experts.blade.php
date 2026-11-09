@extends('layouts.app')
@section('title','Expert Evaluation Reports')
@section('content')
<h1>Expert Evaluation Reports</h1><p class="text-secondary">Filter reports, then browse one expert at a time. The submissions below belong to the displayed expert.</p>
<a class="report-center-link mb-4" href="{{ route('evaluation.report-center',array_diff_key($filters,array_flip(['export','expert_page','submissions_page','activity_page','per_page']))) }}"><span class="report-center-link-icon" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></span><span class="flex-fill"><strong>Report Center · Statistics &amp; Downloads</strong><span>View survey activity and download reports for one or more experts.</span></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
@include('reports.filters')
<div class="d-flex justify-content-end mb-3"><button class="btn btn-outline-secondary btn-sm" type="button" data-print-report>Print Report</button></div>
@include('reports.summary')
@include('reports.submissions')
@endsection
