@extends('layouts.app')
@section('title', 'Scan #'.$scan->id)
@section('content')
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="{{ route('projects.index') }}">Projects</a></li>
        <li class="breadcrumb-item"><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></li>
        <li class="breadcrumb-item active" aria-current="page">Scan #{{ $scan->id }}</li>
    </ol>
</nav>

<div class="np-card p-3 mb-3">
    <div class="row g-3 align-items-center">
        <div class="col-md-8">
            <h1 class="h5 mb-1">Security scan of {{ $scan->upload->original_name }}</h1>
            <div class="small text-secondary mb-2">
                Started {{ $scan->created_at->format('d M Y H:i') }} by {{ $scan->starter?->name ?? 'unknown' }}
                @if ($scan->duration_ms) &middot; {{ number_format($scan->duration_ms / 1000, 1) }} s @endif
                @if ($scan->status === 'completed') &middot; {{ $scan->files_scanned }} files scanned @if ($scan->files_skipped) ({{ $scan->files_skipped }} skipped) @endif @endif
                @if ($scan->engines_used) &middot; engines: {{ implode(', ', $scan->engines_used) }} @endif
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if ($scan->status === 'completed')
                    <a href="{{ route('scans.report', $scan) }}" class="btn btn-primary btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i> Download PDF report</a>
                @endif
                @if ($canManage && ! $scan->isActive())
                    <form method="POST" action="{{ route('projects.uploads.scans.store', [$project, $scan->upload]) }}">
                        @csrf
                        <button class="btn btn-outline-secondary btn-sm" type="submit" data-loading="Scanning..."><i class="bi bi-arrow-repeat me-1"></i> Scan again</button>
                    </form>
                @endif
                @if ($canManage && $scan->status === 'completed' && ($summary['total_open'] ?? 0) > 0)
                    <form method="POST" action="{{ route('scans.fixes.store', $scan) }}" class="d-inline-flex flex-wrap align-items-center gap-2">
                        @csrf
                        @if (\App\Services\Fixer\LlmFactory::isConfigured())
                            <div class="form-check form-check-inline m-0 text-muted small" title="Only a few masked lines around the vulnerability are sent to the AI model">
                                <input class="form-check-input" type="checkbox" name="use_ai" id="use_ai" value="1">
                                <label class="form-check-label" for="use_ai">
                                    Use AI helper
                                    <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.7rem;">Privacy: masked code only</span>
                                </label>
                            </div>
                        @endif
                        <button class="btn btn-success btn-sm" type="submit" data-loading="Generating patches...">
                            <i class="bi bi-magic me-1"></i> Fix issues and download ZIP
                        </button>
                    </form>
                @elseif ($scan->fixRuns()->where('status', 'completed')->exists())
                    @php($latestFix = $scan->fixRuns()->where('status', 'completed')->latest()->first())
                    <a href="{{ route('fixes.show', $latestFix) }}" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-check2-circle me-1"></i> View fixed ZIP
                    </a>
                @elseif ($scan->fixRuns()->where('status', 'ready')->exists())
                    @php($readyFix = $scan->fixRuns()->where('status', 'ready')->latest()->first())
                    <a href="{{ route('fixes.show', $readyFix) }}" class="btn btn-warning btn-sm">
                        <i class="bi bi-hourglass-split me-1"></i> Review proposed patches
                    </a>
                @endif
            </div>
        </div>
        <div class="col-md-4 text-center">
            @if ($scan->status === 'completed' && $scan->score !== null)
                @php($circ = 2 * pi() * 54)
                @php($color = $scan->score >= 75 ? '#16a34a' : ($scan->score >= 60 ? '#d97706' : ($scan->score >= 40 ? '#ea580c' : '#dc2626')))
                <div class="np-ring" style="width:120px;height:120px">
                    <svg width="120" height="120" viewBox="0 0 120 120">
                        <circle class="bg" cx="60" cy="60" r="54" fill="none" stroke-width="12"></circle>
                        <circle class="val" cx="60" cy="60" r="54" fill="none" stroke-width="12" stroke="{{ $color }}" stroke-dasharray="{{ round($circ * $scan->score / 100, 1) }} {{ round($circ, 1) }}"></circle>
                    </svg>
                    <div class="center"><div class="score grade-{{ $scan->grade }}" style="font-size:1.7rem">{{ $scan->score }}</div><div class="grade">Grade {{ $scan->grade }}</div></div>
                </div>
            @endif
        </div>
    </div>
</div>

@if ($scan->isActive())
    <div class="alert alert-info d-flex align-items-center gap-2" id="scan-progress">
        <div class="spinner-border spinner-border-sm" role="status"></div>
        <span>Scan is <strong id="scan-state">{{ $scan->status }}</strong>. This page refreshes automatically.</span>
    </div>
@elseif ($scan->status === 'failed')
    <div class="alert alert-danger"><i class="bi bi-x-octagon me-1"></i> The scan failed: {{ $scan->error_message ?? 'unknown error' }}</div>
@endif

@if ($scan->status === 'completed')
    <div class="row g-3 mb-3">
        @foreach (\App\Models\Finding::SEVERITIES as $level)
            <div class="col-6 col-md">
                <a class="np-sevcard {{ $level }}" href="{{ route('scans.show', ['scan' => $scan, 'severity' => [$level]]) }}">
                    <div class="num">{{ $summary['severity'][$level] ?? 0 }}</div>
                    <div class="lbl">{{ $level }}</div>
                </a>
            </div>
        @endforeach
    </div>

    <form method="GET" class="np-card p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1" for="q">Search</label>
                <input type="search" id="q" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="file, title or rule id">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="category">Weakness type</label>
                <select id="category" name="category" class="form-select form-select-sm">
                    <option value="">All types</option>
                    @foreach (($summary['categories'] ?? []) as $cat => $n)
                        <option value="{{ $cat }}" @selected($filters['category'] === $cat)>{{ \App\Models\Finding::labelFor($cat) }} ({{ $n }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1" for="status">Status</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    @foreach (['open' => 'Open', 'ignored' => 'Ignored', 'false_positive' => 'False positive', 'all' => 'All'] as $v => $l)
                        <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                @foreach ($filters['severity'] as $sv) <input type="hidden" name="severity[]" value="{{ $sv }}"> @endforeach
                <button class="btn btn-primary btn-sm" type="submit">Filter</button>
                <a href="{{ route('scans.show', $scan) }}" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </div>
        @if ($filters['severity'])
            <div class="small mt-2 text-secondary">Showing severity: <strong>{{ implode(', ', $filters['severity']) }}</strong></div>
        @endif
    </form>

    <div class="np-card">
        @if ($findings->count())
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Severity</th><th>Finding</th><th>Location</th><th>Confidence</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($findings as $f)
                        <tr>
                            <td><span class="sev sev-{{ $f->severity }}">{{ $f->severity }}</span></td>
                            <td>
                                <a href="{{ route('findings.show', $f) }}" class="fw-semibold text-decoration-none">{{ $f->title }}</a>
                                <div class="small text-secondary">
                                    {{ $f->rule_id }} &middot; {{ $f->categoryLabel() }}
                                    @if ($f->status === 'fixed')
                                        <span class="badge bg-success-subtle text-success border border-success-subtle ms-1"><i class="bi bi-check-lg"></i> Fixed</span>
                                    @elseif ($f->status !== 'open')
                                        &middot; <em>{{ str_replace('_', ' ', $f->status) }}</em>
                                    @endif
                                </div>
                            </td>
                            <td class="np-loc">{{ $f->file_path }}{{ $f->line_start ? ':'.$f->line_start : '' }}</td>
                            <td><span class="badge text-bg-{{ $f->confidence === 'confirmed' ? 'dark' : 'secondary' }}">{{ $f->confidence }}</span></td>
                            <td class="text-end"><a href="{{ route('findings.show', $f) }}" class="btn btn-sm btn-outline-primary">Details</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $findings->links() }}</div>
        @else
            <div class="np-empty"><i class="bi bi-shield-check"></i><p class="mt-2 mb-0">No findings match these filters.</p></div>
        @endif
    </div>
@endif
@endsection

@if ($scan->isActive())
@push('scripts')
<script>
    (function poll() {
        setTimeout(function () {
            fetch(@json(route('scans.status', $scan)), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.finished) { window.location.reload(); return; }
                    document.getElementById('scan-state').textContent = d.status;
                    poll();
                }).catch(function () { poll(); });
        }, 2000);
    })();
</script>
@endpush
@endif
