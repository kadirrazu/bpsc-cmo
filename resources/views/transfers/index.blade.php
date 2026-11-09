@extends('layouts.app')
@section('title','Excel Import & Export')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4"><span class="survey-section-icon"><i class="bi bi-file-earmark-spreadsheet"></i></span><div><h1 class="mb-1">Excel Import & Export</h1><p class="text-secondary mb-0">Move your workspace data or add records in bulk. Administrator access only.</p></div></div>
<div class="row g-4">
<div class="col-lg-6"><div class="card h-100"><div class="card-body"><span class="badge bg-teal-lt mb-3">1 · Prepare</span><h2>Export data or get a template</h2><p class="text-secondary">Survey exports include the Question Bank and preserve assigned questions and their order. User exports include Designations and Units.</p>
<form method="post" action="{{ route('transfers.download') }}">@csrf
<label class="form-label" for="scope">Dataset</label><select class="form-select mb-3" id="scope" name="scope">@foreach($scopes as $scope)<option value="{{ $scope }}">{{ ['all'=>'All setup data','evaluation'=>'Expert Evaluation System — all setup data','users'=>'Users + Designations + Units','designations'=>'Designations','units'=>'Units','experts'=>'Expert Pool','questions'=>'Question Bank','surveys'=>'Surveys + Question Bank'][$scope] }}</option>@endforeach</select>
<label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="credentials" value="1"><span class="form-check-label">Include password hashes for account migration</span></label><p class="small text-secondary">Optional, for User exports only. Keep files with password hashes private. Without hashes, new imported users need Administrator password setup.</p>
<div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-download"></i> Export .xlsx</button><button class="btn btn-outline-primary" type="submit" name="template" value="1">Download blank template</button><button class="btn btn-outline-primary" type="submit" name="sample" value="1">Download Sample .xlsx</button></div></form>
</div></div></div>
<div class="col-lg-6"><div class="card h-100"><div class="card-body"><span class="badge bg-teal-lt mb-3">2 · Review & import</span><h2>Upload an Excel workbook</h2><p class="text-secondary">Review every row and any validation errors before confirming. Nothing is added or updated until you confirm.</p>
<form method="post" action="{{ route('transfers.stage') }}" enctype="multipart/form-data">@csrf<label class="form-label" for="file">Excel file (.xlsx)</label><input class="form-control mb-2" type="file" id="file" name="file" accept=".xlsx" required><p class="small text-secondary mb-3">Up to 10 MB and 5,000 rows per workbook. Preview expires after one hour.</p><button class="btn btn-primary"><i class="bi bi-eye"></i> Upload & Preview</button></form>
</div></div></div>
</div>
<div class="card mt-4"><div class="card-body"><h3>How matching works</h3><p class="text-secondary mb-2">Keep each exported key to update the same record. Leave it blank for a new record. Designations also match by name, Units by code and Users by email. Import never deletes records.</p><p class="text-secondary mb-0">This transfers setup data, accounts, expert profiles and survey definitions. Evaluation submissions, audit history, sessions and tokens are not included. Use a database backup for a complete system restore.</p></div></div>
@endsection
