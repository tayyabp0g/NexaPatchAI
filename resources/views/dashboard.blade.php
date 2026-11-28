@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="np-hero mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1>Welcome back, {{ explode(' ', auth()->user()->name)[0] }}</h1>
            <p>Upload code, find security weaknesses, and get a clear report you can act on.</p>
        </div>
        <div class="d-flex gap-2 position-relative" style="z-index:1">
            @can('create', \App\Models\Project::class)
                <a href="{{ route('projects.create') }}" class="btn btn-light"><i class="bi bi-plus-lg me-1"></i> New project</a>
            @endcan
            <a href="{{ route('projects.index') }}" class="btn btn-outline-light"><i class="bi bi-folder2-open me-1"></i> Projects</a>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="np-card np-stat"><div class="icon ic-blue"><i class="bi bi-folder2-open"></i></div><div><div class="num">{{ $projectCount }}</div><div class="lbl">Projects</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="np-card np-stat"><div class="icon ic-green"><i class="bi bi-check2-circle"></i></div><div><div class="num">{{ $completedScans }}</div><div class="lbl">Completed scans</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="np-card np-stat"><div class="icon ic-amber"><i class="bi bi-hourglass-split"></i></div><div><div class="num">{{ $pendingScans }}</div><div class="lbl">Pending scans</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="np-card np-stat"><div class="icon ic-red"><i class="bi bi-bug"></i></div><div><div class="num">{{ $openFindings }}</div><div class="lbl">Open findings</div></div></div></div>
</div>

@if (!empty($pendingFixes) && $pendingFixes->isNotEmpty())
<div class="card border-primary shadow-sm mb-4">
    <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-shield-lock me-1"></i> Security Patches Awaiting Approval ({{ $pendingFixes->count() }})</span>
        <span class="badge bg-light text-primary">Reviewer Action Needed</span>
    </div>
    <div class="list-group list-group-flush">
        @foreach ($pendingFixes as $fix)
            <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                <div>
                    <strong>{{ $fix->scan->upload->project->name }}</strong> &middot;
                    <span class="text-muted small">Scan #{{ $fix->scan_id }} ({{ $fix->scan->upload->original_name }})</span> &middot;
                    <span class="badge bg-secondary-subtle text-secondary small">{{ $fix->patches()->count() }} patches proposed</span>
                </div>
                <a href="{{ route('fixes.show', $fix) }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-eye me-1"></i> Review & Approve
                </a>
            </div>
        @endforeach
    </div>
</div>
@endif

@if (! $hasScans)
<div class="np-card p-3 mb-4">
    <h2 class="mb-2">Getting started</h2>
    <div class="np-step {{ $projectCount ? 'done' : '' }}"><span class="n">{!! $projectCount ? '<i class="bi bi-check"></i>' : '1' !!}</span><span>Create a project</span></div>
    <div class="np-step {{ $hasUploads ? 'done' : '' }}"><span class="n">{!! $hasUploads ? '<i class="bi bi-check"></i>' : '2' !!}</span><span>Upload a PHP file or a project ZIP</span></div>
    <div class="np-step"><span class="n">3</span><span>Click "Scan" and read the findings</span></div>
    <div class="np-step"><span class="n">4</span><span>Download the PDF report</span></div>
</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="np-card p-3 h-100 text-center">
            <h2 class="text-start mb-3">Security score</h2>
            @if ($avgScore !== null)
                @php($circ = 2 * pi() * 54)
                @php($color = $avgScore >= 75 ? '#16a34a' : ($avgScore >= 60 ? '#d97706' : ($avgScore >= 40 ? '#ea580c' : '#dc2626')))
                <div class="np-ring">
                    <svg width="150" height="150" viewBox="0 0 120 120">
                        <circle class="bg" cx="60" cy="60" r="54" fill="none" stroke-width="12"></circle>
                        <circle class="val" cx="60" cy="60" r="54" fill="none" stroke-width="12" stroke="{{ $color }}" stroke-dasharray="{{ round($circ * $avgScore / 100, 1) }} {{ round($circ, 1) }}"></circle>
                    </svg>
                    <div class="center"><div class="score grade-{{ $avgGrade }}">{{ $avgScore }}</div><div class="grade">Grade {{ $avgGrade }}</div></div>
                </div>
                <p class="small text-secondary mt-3 mb-0">Average of the latest scan of each project.</p>
            @else
                <div class="np-empty"><i class="bi bi-speedometer2"></i><p class="mt-2 mb-0">Run your first scan to see a score.</p></div>
            @endif
        </div>
    </div>
    <div class="col-lg-4">
        <div class="np-card p-3 h-100">
            <h2 class="mb-3">Open findings by severity</h2>
            @if ($openFindings > 0)
                <canvas id="severityChart" height="210"></canvas>
            @else
                <div class="np-empty"><i class="bi bi-shield-check"></i><p class="mt-2 mb-0">No open findings.</p></div>
            @endif
            <div class="d-flex flex-wrap gap-2 mt-3">
                @foreach ($severityCounts as $level => $total)
                    <span class="sev sev-{{ $level }}">{{ $level }}: {{ $total }}</span>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="np-card p-3 h-100">
            <h2 class="mb-3">Top weakness types</h2>
            @if ($categories->count())
                <canvas id="categoryChart" height="210"></canvas>
            @else
                <div class="np-empty"><i class="bi bi-bar-chart"></i><p class="mt-2 mb-0">Nothing to show yet.</p></div>
            @endif
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="np-card p-3 h-100">
            <h2 class="mb-2">Needs attention</h2>
            @forelse ($attention as $f)
                <a href="{{ route('findings.show', $f) }}" class="np-list-row">
                    <span><span class="t d-block">{{ $f->title }}</span><span class="s np-loc">{{ $f->file_path }}{{ $f->line_start ? ':'.$f->line_start : '' }} &middot; {{ $f->scan->upload->project->name }}</span></span>
                    <span class="sev sev-{{ $f->severity }}">{{ $f->severity }}</span>
                </a>
            @empty
                <div class="np-empty"><i class="bi bi-emoji-smile"></i><p class="mt-2 mb-0">No critical or high findings.</p></div>
            @endforelse
        </div>
    </div>
    <div class="col-lg-6">
        <div class="np-card p-3 h-100">
            <h2 class="mb-2">Recent scans</h2>
            @forelse ($recentScans as $s)
                <a href="{{ route('scans.show', $s) }}" class="np-list-row">
                    <span><span class="t d-block">{{ $s->upload->project->name }}</span><span class="s">{{ $s->upload->original_name }} &middot; {{ $s->created_at->diffForHumans() }}</span></span>
                    @if ($s->status === 'completed')
                        <span class="fw-bold grade-{{ $s->grade }}">{{ $s->score }} <small>({{ $s->grade }})</small></span>
                    @else
                        <span class="badge text-bg-{{ $s->status === 'failed' ? 'danger' : 'warning' }}">{{ $s->status }}</span>
                    @endif
                </a>
            @empty
                <div class="np-empty"><i class="bi bi-search"></i><p class="mt-2 mb-0">No scans yet.</p></div>
            @endforelse
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="np-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="mb-0">Recent projects</h2><a href="{{ route('projects.index') }}" class="small">View all</a></div>
            @forelse ($recentProjects as $project)
                <a href="{{ route('projects.show', $project) }}" class="np-list-row">
                    <span class="t"><i class="bi bi-folder2 me-2 text-primary"></i>{{ $project->name }}</span>
                    <span class="s">{{ $project->created_at->diffForHumans() }}</span>
                </a>
            @empty
                <div class="np-empty"><i class="bi bi-folder-plus"></i><p class="mt-2 mb-0">No projects yet.</p></div>
            @endforelse
        </div>
    </div>
    <div class="col-lg-6">
        <div class="np-card p-3 h-100">
            <h2 class="mb-3">Recent activity</h2>
            <ul class="np-timeline">
                @forelse ($activity as $a)
                    <li><span class="fw-semibold">{{ $a->label() }}</span> <span class="text-secondary">&middot; {{ $a->created_at->diffForHumans() }}</span></li>
                @empty
                    <li class="text-secondary">No activity yet.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection

@if ($openFindings > 0 || $categories->count())
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    var sev = @json($severityCounts);
    var cat = @json($categories);
    var sevEl = document.getElementById('severityChart');
    if (sevEl) {
        new Chart(sevEl, { type: 'doughnut',
            data: { labels: Object.keys(sev), datasets: [{ data: Object.values(sev), backgroundColor: ['#7f1d1d', '#dc3545', '#ffc107', '#0dcaf0', '#6c757d'] }] },
            options: { plugins: { legend: { position: 'bottom' } } } });
    }
    var catEl = document.getElementById('categoryChart');
    if (catEl) {
        new Chart(catEl, { type: 'bar',
            data: { labels: Object.keys(cat).map(function (k) { return k.replace(/_/g, ' '); }), datasets: [{ data: Object.values(cat), backgroundColor: '#2b7fff' }] },
            options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } } });
    }
</script>
@endpush
@endif
