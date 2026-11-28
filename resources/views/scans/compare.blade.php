@extends('layouts.app')

@section('title', 'Scan Comparison: #' . $scan->id . ' vs #' . $otherScan->id . ' · NexaPatch AI')

@section('content')
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></li>
            <li class="breadcrumb-item"><a href="{{ route('scans.show', $scan) }}">Baseline Scan #{{ $scan->id }}</a></li>
            <li class="breadcrumb-item active">Comparison with Scan #{{ $otherScan->id }}</li>
        </ol>
    </nav>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-7">
                <h3 class="h4 mb-1">Security Scan Comparison</h3>
                <p class="text-muted small mb-0">Comparing <strong>{{ $scan->upload->original_name }}</strong> (Scan #{{ $scan->id }}) with repaired <strong>{{ $otherScan->upload->original_name }}</strong> (Scan #{{ $otherScan->id }})</p>
            </div>
            <div class="col-md-5 text-md-end mt-3 mt-md-0">
                <div class="d-inline-flex align-items-center gap-3 bg-light p-2 rounded border">
                    <div class="text-center">
                        <div class="small text-muted">Baseline</div>
                        <span class="fs-5 fw-bold grade-{{ $scan->grade }}">{{ $scan->score }} ({{ $scan->grade }})</span>
                    </div>
                    <i class="bi bi-arrow-right text-muted fs-4"></i>
                    <div class="text-center">
                        <div class="small text-muted">Patched</div>
                        <span class="fs-5 fw-bold grade-{{ $otherScan->grade }}">{{ $otherScan->score }} ({{ $otherScan->grade }})</span>
                    </div>
                    <span class="badge {{ $scoreDiff >= 0 ? 'bg-success' : 'bg-danger' }} fs-6">
                        {{ $scoreDiff >= 0 ? '+' : '' }}{{ $scoreDiff }} pts
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-success shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-subtitle text-success fw-bold text-uppercase small">Resolved Vulnerabilities</h6>
                    <span class="badge bg-success rounded-pill fs-6">{{ $resolved->count() }}</span>
                </div>
                <p class="text-muted small mb-0">Issues identified in the baseline scan that are completely gone in the patched version.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-secondary shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-subtitle text-secondary fw-bold text-uppercase small">Persistent Findings</h6>
                    <span class="badge bg-secondary rounded-pill fs-6">{{ $persisting->count() }}</span>
                </div>
                <p class="text-muted small mb-0">Issues still remaining that may need manual code refactoring or package upgrades.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card {{ $introduced->isEmpty() ? 'border-light' : 'border-danger' }} shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="card-subtitle {{ $introduced->isEmpty() ? 'text-muted' : 'text-danger' }} fw-bold text-uppercase small">Regressions / New</h6>
                    <span class="badge {{ $introduced->isEmpty() ? 'bg-light text-dark' : 'bg-danger' }} rounded-pill fs-6">{{ $introduced->count() }}</span>
                </div>
                <p class="text-muted small mb-0">New findings detected in the patched scan (zero regressions is the goal).</p>
            </div>
        </div>
    </div>
</div>

{{-- Resolved Findings Table --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-success text-white py-3">
        <h5 class="card-title mb-0"><i class="bi bi-shield-check me-2"></i> Resolved Weaknesses ({{ $resolved->count() }})</h5>
    </div>
    @if ($resolved->isEmpty())
        <div class="card-body text-muted py-4 text-center">
            No resolved weaknesses detected between these two scans.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Severity</th><th>Rule & Title</th><th>File & Line</th><th>Impact</th></tr>
                </thead>
                <tbody>
                    @foreach ($resolved as $f)
                        <tr>
                            <td><span class="sev sev-{{ $f->severity }}">{{ $f->severity }}</span></td>
                            <td>
                                <div class="fw-semibold">{{ $f->title }}</div>
                                <div class="small text-muted">{{ $f->rule_id }} &middot; {{ $f->categoryLabel() }}</div>
                            </td>
                            <td><code>{{ $f->file_path }}{{ $f->line_start ? ':'.$f->line_start : '' }}</code></td>
                            <td class="small text-muted">{{ Str::limit($f->impact, 80) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Persistent Findings Table --}}
@if ($persisting->isNotEmpty())
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light py-3">
            <h5 class="card-title mb-0 text-muted"><i class="bi bi-hourglass-split me-2"></i> Persistent Findings Still Open ({{ $persisting->count() }})</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Severity</th><th>Rule & Title</th><th>File & Line</th><th>Remediation Advice</th></tr>
                </thead>
                <tbody>
                    @foreach ($persisting as $f)
                        <tr>
                            <td><span class="sev sev-{{ $f->severity }}">{{ $f->severity }}</span></td>
                            <td>
                                <a href="{{ route('findings.show', $f) }}" class="fw-semibold text-decoration-none">{{ $f->title }}</a>
                                <div class="small text-muted">{{ $f->rule_id }} &middot; {{ $f->categoryLabel() }}</div>
                            </td>
                            <td><code>{{ $f->file_path }}{{ $f->line_start ? ':'.$f->line_start : '' }}</code></td>
                            <td class="small text-muted">{{ Str::limit($f->remediation, 90) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- New Findings / Regressions --}}
@if ($introduced->isNotEmpty())
    <div class="card shadow-sm mb-4 border-danger">
        <div class="card-header bg-danger text-white py-3">
            <h5 class="card-title mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i> Regressions Introduced ({{ $introduced->count() }})</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Severity</th><th>Rule & Title</th><th>File & Line</th><th>Details</th></tr>
                </thead>
                <tbody>
                    @foreach ($introduced as $f)
                        <tr>
                            <td><span class="sev sev-{{ $f->severity }}">{{ $f->severity }}</span></td>
                            <td>
                                <a href="{{ route('findings.show', $f) }}" class="fw-semibold text-danger">{{ $f->title }}</a>
                                <div class="small text-muted">{{ $f->rule_id }}</div>
                            </td>
                            <td><code>{{ $f->file_path }}{{ $f->line_start ? ':'.$f->line_start : '' }}</code></td>
                            <td class="small text-muted">{{ Str::limit($f->description, 90) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
