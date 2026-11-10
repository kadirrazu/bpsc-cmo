@extends('viva.display.layout')
@section('title','Live Viva Boards')
@section('body-class','viva-projector')
@section('content')
<div data-viva-display data-url="{{ route('viva.display.feed') }}" data-date="{{ $date }}" data-follow-today="{{ request()->filled('vdate')?'false':'true' }}" data-login="{{ route('viva.display.login') }}">
<div class="viva-display-toolbar">
<form method="get" class="d-flex gap-2 align-items-end"><div><label class="form-label" for="screen-date">Viva Date</label><input class="form-control" id="screen-date" type="date" name="vdate" value="{{ $date }}" required></div><button class="btn btn-primary">View</button></form>
<div class="text-center"><div class="viva-live-label justify-content-center"><span></span> Live Board Updates</div><div class="small text-secondary mt-1" data-display-status>Updated {{ $payload['generated_at'] }}</div></div>
<div class="d-flex align-items-center gap-2"><button type="button" class="btn btn-outline-primary btn-sm" data-display-fullscreen>Full Screen</button><form method="post" action="{{ route('viva.display.logout') }}">@csrf<button class="btn btn-outline-secondary btn-sm">Sign Out</button></form></div>
</div>
<div class="viva-slide-stage" data-display-boards aria-label="Automatically rotating viva boards"></div>
<div class="viva-slide-controls" data-display-controls hidden><button type="button" class="btn btn-sm btn-outline-secondary" data-display-prev aria-label="Previous slide"><i class="bi bi-chevron-left" aria-hidden="true"></i><span>Previous</span></button><span data-display-position>Preparing boards…</span><button type="button" class="btn btn-sm btn-outline-secondary" data-display-pause aria-pressed="false"><i class="bi bi-pause-fill" aria-hidden="true"></i><span data-display-pause-label>Pause</span></button><button type="button" class="btn btn-sm btn-outline-secondary" data-display-next aria-label="Next slide"><span>Next</span><i class="bi bi-chevron-right" aria-hidden="true"></i></button></div>
<script type="application/json" data-display-initial>{!! \Illuminate\Support\Js::encode($payload['boards']) !!}</script>
<noscript><p class="alert alert-warning">JavaScript is required for the automatic hall display.</p></noscript>
</div>
@endsection
