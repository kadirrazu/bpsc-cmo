<nav class="module-navigation d-print-none" aria-label="Viva Board Distribution navigation">
<div class="container-xl py-3"><div class="d-flex flex-wrap gap-2 align-items-center">
<span class="badge bg-teal-lt me-2">VIVA BOARD DISTRIBUTION</span>
@can('view-own-viva-board')<a class="btn btn-sm btn-primary" href="{{ route('viva.my-board.index') }}" aria-current="page">My Viva Board</a>@endcan
@can('manage-viva-pools')
<a class="btn btn-sm {{ request()->routeIs('viva.pools.*')?'btn-primary':'btn-outline-secondary' }}" href="{{ route('viva.pools.index') }}" @if(request()->routeIs('viva.pools.*')) aria-current="page" @endif>1. Member Pools</a>
<a class="btn btn-sm {{ request()->routeIs('viva.boards.*')?'btn-primary':'btn-outline-secondary' }}" href="{{ route('viva.boards.index') }}" @if(request()->routeIs('viva.boards.*')) aria-current="page" @endif>2. Board Distribution</a>
@endcan
@can('manage-system')
<a class="btn btn-sm {{ request()->routeIs('viva.imports.*')?'btn-primary':'btn-outline-secondary' }}" href="{{ route('viva.imports.index') }}" @if(request()->routeIs('viva.imports.*')) aria-current="page" @endif>Candidate Import</a>
<a class="btn btn-sm {{ request()->routeIs('viva.boards.*')?'btn-primary':'btn-outline-secondary' }}" href="{{ route('viva.boards.index') }}" @if(request()->routeIs('viva.boards.*')) aria-current="page" @endif>Board Overview</a>
<a class="btn btn-sm {{ request()->routeIs('viva.settings.*')?'btn-primary':'btn-outline-secondary' }}" href="{{ route('viva.settings.edit') }}" @if(request()->routeIs('viva.settings.*')) aria-current="page" @endif>Settings</a>
@endcan
<a class="ms-md-auto small" href="{{ route('dashboard') }}">All Modules</a>
</div></div></nav>
