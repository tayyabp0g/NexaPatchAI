@extends('layouts.app')

@section('title', 'Repair & Verify #' . $fixRun->id . ' · NexaPatch AI')

@section('content')
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></li>
            <li class="breadcrumb-item"><a href="{{ route('scans.show', $scan) }}">Scan #{{ $scan->id }}</a></li>
            <li class="breadcrumb-item active">Fix Run #{{ $fixRun->id }}</li>
        </ol>
    </nav>
</div>

@if (session('success'))
    <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@if ($errors->has('fix'))
    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ $errors->first('fix') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@if ($fixRun->status === 'completed' && $fixRun->applied_count < 1)
    <div class="alert alert-warning mb-4" role="alert">
        This run did not apply any patches. Its ZIP is not available as a repaired project.
        Start a new repair run and approve the verified patches.
        <form method="POST" action="{{ route('scans.fixes.store', $scan) }}" class="mt-2">
            @csrf
            <button type="submit" class="btn btn-warning btn-sm">Generate new fix proposals</button>
        </form>
    </div>
@endif

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h3 class="h4 mb-0">Automated Security Repair</h3>
                    @if ($fixRun->status === 'completed')
                        <span class="badge bg-success"><i class="bi bi-check-all me-1"></i> Completed</span>
                    @elseif ($fixRun->status === 'ready')
                        <span class="badge bg-primary"><i class="bi bi-hourglass-split me-1"></i> Ready for Review</span>
                    @elseif ($fixRun->status === 'running')
                        <span class="badge bg-info"><i class="bi bi-arrow-repeat me-1"></i> Proposing Patches...</span>
                    @else
                        <span class="badge bg-secondary">{{ ucfirst($fixRun->status) }}</span>
                    @endif

                    @if ($fixRun->use_ai)
                        <span class="badge bg-info-subtle text-info border border-info-subtle"><i class="bi bi-cpu me-1"></i> AI Assisted</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><i class="bi bi-shield-check me-1"></i> Deterministic Rules</span>
                    @endif
                </div>

                <div class="text-muted small">
                    Target: <strong>{{ $scan->upload->original_name }}</strong> &middot;
                    Requested by: {{ $fixRun->requester->name ?? 'System' }} &middot;
                    {{ $fixRun->created_at->diffForHumans() }}
                </div>
            </div>

            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                @if ($fixRun->status === 'completed' && $fixRun->applied_count > 0)
                    <a href="{{ route('fixes.download', $fixRun) }}" class="btn btn-success btn-lg shadow-sm">
                        <i class="bi bi-download me-1"></i> Download Repaired ZIP
                    </a>
                @endif
            </div>
        </div>

        {{-- Before vs After Score Card --}}
        @if ($fixRun->status === 'completed' && isset($summary['before_score'], $summary['after_score']))
            <hr class="my-4">
            <div class="row text-center g-3">
                <div class="col-sm-4">
                    <div class="p-3 border rounded bg-light">
                        <div class="text-muted small text-uppercase fw-semibold">Before Repair</div>
                        <div class="h2 mb-0 mt-1 grade-{{ $summary['before_grade'] ?? 'F' }}">
                            {{ $summary['before_score'] }} <span class="fs-6 text-muted">({{ $summary['before_grade'] ?? 'F' }})</span>
                        </div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="p-3 border rounded bg-success-subtle border-success-subtle">
                        <div class="text-success small text-uppercase fw-semibold">Score Improvement</div>
                        @if (isset($summary['findings_resolved']))
                            <div class="h2 mb-0 mt-1 text-success">
                                {{ $summary['findings_resolved'] }} resolved
                            </div>
                            <div class="small text-muted">
                                {{ $summary['before_open'] ?? 0 }} open → {{ $summary['after_open'] ?? 0 }} open
                            </div>
                        @else
                            <div class="h2 mb-0 mt-1 text-success">
                                +{{ $summary['score_diff'] ?? 0 }} pts
                            </div>
                        @endif
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="p-3 border rounded bg-light">
                        <div class="text-muted small text-uppercase fw-semibold">After Repair (Patched)</div>
                        <div class="h2 mb-0 mt-1 grade-{{ $summary['after_grade'] ?? 'A' }}">
                            {{ $summary['after_score'] }} <span class="fs-6 text-muted">({{ $summary['after_grade'] ?? 'A' }})</span>
                        </div>
                    </div>
                </div>
            </div>
            @if ($fixRun->after_scan_id)
                <div class="text-center mt-3">
                    <a href="{{ route('scans.compare', [$scan, $fixRun->after_scan_id]) }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-arrow-left-right me-1"></i> View Full Side-by-Side Scan Comparison
                    </a>
                </div>
            @endif
        @endif
    </div>
</div>

{{-- Patch Summary Statistics --}}
@php
    $verifiedPatches = $patches->where('status', 'verified');
    $unverifiedPatches = $patches->where('status', 'unverified');
    $appliedCount = $patches->where('status', 'applied')->count();
    $skippedCount = count($summary['skipped'] ?? []);
@endphp

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-success shadow-sm">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Verified Patches</div>
                <div class="h3 mb-0 mt-2 text-success">{{ $verifiedPatches->count() + $appliedCount }}</div>
                <div class="text-muted small mt-1">Passed syntax, policy & rescan</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-warning shadow-sm">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Manual Steps Required</div>
                <div class="h3 mb-0 mt-2 text-warning">{{ $skippedCount }}</div>
                <div class="text-muted small mt-1">Requires developer action</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-danger shadow-sm">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Unverified / Blocked</div>
                <div class="h3 mb-0 mt-2 text-danger">{{ $unverifiedPatches->count() }}</div>
                <div class="text-muted small mt-1">Rejected by safety policy gate</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-start border-4 border-info shadow-sm">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Applied in ZIP</div>
                <div class="h3 mb-0 mt-2 text-info">{{ $fixRun->applied_count }}</div>
                <div class="text-muted small mt-1">Integrated into clean source</div>
            </div>
        </div>
    </div>
</div>

{{-- Form to approve and build --}}
@if ($fixRun->status === 'ready')
    <form method="POST" action="{{ route('fixes.build', $fixRun) }}">
        @csrf
@endif

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Proposed Security Patches ({{ $patches->count() }})</h5>
        @if ($fixRun->status === 'ready' && $verifiedPatches->count() > 0)
            <button type="submit" class="btn btn-primary" data-loading="Applying patches & rebuilding ZIP...">
                <i class="bi bi-check2-circle me-1"></i> Approve Selected & Build Repaired ZIP
            </button>
        @endif
    </div>

    @if ($patches->isEmpty())
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-check-circle fs-1 text-success d-block mb-3"></i>
            <h5>No code changes proposed</h5>
            <p class="mb-0">Either no fixable code weaknesses were found, or all issues require manual remediation steps below.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        @if ($fixRun->status === 'ready')
                            <th style="width: 40px;">
                                <input class="form-check-input" type="checkbox" id="selectAll" checked>
                            </th>
                        @endif
                        <th>Target File & Line</th>
                        <th>Vulnerability</th>
                        <th>Method</th>
                        <th>Status & Verification</th>
                        <th>Proposed Minimal Diff</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($patches as $patch)
                        @php($isVerified = $patch->status === 'verified')
                        <tr>
                            @if ($fixRun->status === 'ready')
                                <td>
                                    <input class="form-check-input patch-checkbox" type="checkbox" name="patches[]" value="{{ $patch->id }}"
                                        {{ $isVerified ? 'checked' : 'disabled' }}>
                                </td>
                            @endif
                            <td>
                                <code>{{ $patch->edit['file'] ?? $patch->finding->file_path }}:{{ $patch->edit['start'] ?? $patch->finding->line_start }}</code>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $patch->finding->title }}</div>
                                <span class="badge bg-secondary-subtle text-secondary small">{{ $patch->finding->rule_id }}</span>
                            </td>
                            <td>
                                @if ($patch->method === 'ai')
                                    <span class="badge bg-info-subtle text-info border border-info-subtle"><i class="bi bi-cpu"></i> AI</span>
                                @else
                                    <span class="badge bg-light text-dark border"><i class="bi bi-wrench"></i> Rule</span>
                                @endif
                            </td>
                            <td>
                                @if ($patch->status === 'applied')
                                    <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i> Applied</span>
                                @elseif ($patch->status === 'verified')
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <i class="bi bi-shield-check me-1"></i> Verified
                                    </span>
                                @elseif ($patch->status === 'unverified')
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                        <i class="bi bi-shield-x me-1"></i> Unverified
                                    </span>
                                @else
                                    <span class="badge bg-secondary">{{ ucfirst($patch->status) }}</span>
                                @endif

                                <div class="small mt-1">
                                    @foreach ($patch->verificationResults as $vr)
                                        <span class="badge bg-{{ $vr->result === 'pass' ? 'success' : ($vr->result === 'fail' ? 'danger' : 'secondary') }}-subtle text-{{ $vr->result === 'pass' ? 'success' : ($vr->result === 'fail' ? 'danger' : 'muted') }} me-1" title="{{ $vr->log_excerpt }}">
                                            {{ $vr->check_type }}: {{ $vr->result }}
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td style="max-width: 450px;">
                                <div class="small text-muted mb-1">{{ $patch->change_summary }}</div>
                                <pre class="bg-dark text-light p-2 rounded small mb-0" style="max-height: 180px; overflow-y: auto; font-family: monospace; font-size: 0.8rem;">@foreach(explode("\n", $patch->diff_text) as $dline)@if(str_starts_with($dline, '+'))<span class="text-success">{{ $dline }}</span>
@elseif(str_starts_with($dline, '-'))<span class="text-danger">{{ $dline }}</span>
@else<span class="text-secondary">{{ $dline }}</span>
@endif
@endforeach</pre>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($fixRun->status === 'ready' && $verifiedPatches->count() > 0)
        <div class="card-footer bg-white text-end py-3">
            <button type="submit" class="btn btn-primary" data-loading="Applying patches & rebuilding ZIP...">
                <i class="bi bi-check2-circle me-1"></i> Approve Selected & Build Repaired ZIP
            </button>
        </div>
    @endif
</div>

@if ($fixRun->status === 'ready')
    </form>
@endif

{{-- Manual Remediation Steps --}}
@if (!empty($summary['skipped']))
    <div class="card shadow-sm mb-4 border-warning">
        <div class="card-header bg-warning-subtle text-warning-emphasis py-3">
            <h5 class="card-title mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i> Manual Remediation Steps Required ({{ count($summary['skipped']) }})</h5>
        </div>
        <div class="card-body">
            <p class="text-muted small">The following vulnerabilities cannot be safely patched automatically without architectural context. Follow the recommended manual actions below:</p>

            <div class="list-group list-group-flush">
                @foreach ($summary['skipped'] as $item)
                    <div class="list-group-item px-0 py-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1 fw-bold text-dark">{{ $item['title'] }} <span class="badge bg-secondary ms-1">{{ $item['rule_id'] }}</span></h6>
                                <div class="small text-muted mb-2">Location: <code>{{ $item['file'] }}{{ $item['line'] ? ':'.$item['line'] : '' }}</code></div>
                                <div class="text-danger small mb-1"><i class="bi bi-info-circle me-1"></i> <strong>Why skipped:</strong> {{ $item['reason'] }}</div>
                                <div class="p-2 bg-light border rounded small mt-2">
                                    <strong>Recommended Action:</strong> {{ $item['manual_step'] }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.patch-checkbox:not(:disabled)').forEach(cb => {
                cb.checked = selectAll.checked;
            });
        });
    }
});
</script>
@endsection
