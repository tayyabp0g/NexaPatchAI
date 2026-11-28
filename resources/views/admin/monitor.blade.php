@extends('layouts.app')

@section('title', 'Scan & Job Monitor · Admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">System & Job Monitor</h1>
        <p class="text-muted small mb-0">Real-time status of asynchronous jobs, scanner queue, and worker runtime.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.settings') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-sliders me-1"></i> Settings</a>
        <a href="{{ route('admin.monitor') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Scan Execution Mode</div>
                <div class="h4 mb-0 mt-2 text-primary"><code>{{ $stats['scan_mode'] }}</code></div>
                <div class="text-muted small mt-1">{{ $stats['scan_mode'] === 'sync' ? 'Synchronous (in-request)' : 'Queued (background worker)' }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100 border-start border-4 {{ $queueLength > 0 ? 'border-warning' : 'border-success' }}">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Pending Queue Jobs</div>
                <div class="h3 mb-0 mt-2 {{ $queueLength > 0 ? 'text-warning' : 'text-success' }}">{{ $queueLength }}</div>
                <div class="text-muted small mt-1">Jobs waiting in queue</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100 border-start border-4 {{ count($failedJobs) > 0 ? 'border-danger' : 'border-success' }}">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Failed Queue Jobs</div>
                <div class="h3 mb-0 mt-2 {{ count($failedJobs) > 0 ? 'text-danger' : 'text-success' }}">{{ count($failedJobs) }}</div>
                <div class="text-muted small mt-1">Exceptions logged in queue</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100 border-start border-4 border-info">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">PHP Environment</div>
                <div class="h5 mb-0 mt-2 text-dark">PHP {{ $stats['php_version'] }} ({{ $stats['os'] }})</div>
                <div class="text-muted small mt-1">Memory limit: {{ $stats['memory_limit'] }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Recent Scans Table --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h5 class="card-title mb-0">Recent Security Scans</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Project</th><th>Upload</th><th>Status</th><th>Score</th><th>Initiated</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse ($recentScans as $s)
                    <tr>
                        <td>#{{ $s->id }}</td>
                        <td><strong>{{ $s->upload->project->name ?? 'Deleted' }}</strong></td>
                        <td><code>{{ $s->upload->original_name ?? '—' }}</code></td>
                        <td>
                            @if ($s->status === 'completed')
                                <span class="badge bg-success">completed</span>
                            @elseif ($s->status === 'running')
                                <span class="badge bg-info">running</span>
                            @elseif ($s->status === 'queued')
                                <span class="badge bg-warning text-dark">queued</span>
                            @else
                                <span class="badge bg-danger">{{ $s->status }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($s->score !== null)
                                <span class="fw-bold grade-{{ $s->grade }}">{{ $s->score }} ({{ $s->grade }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="small text-muted">{{ $s->created_at->diffForHumans() }}</td>
                        <td>
                            <a href="{{ route('scans.show', $s) }}" class="btn btn-outline-primary btn-sm">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center py-4 text-muted">No scans found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Recent Fix Runs Table --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h5 class="card-title mb-0">Recent Automatic Fix Runs</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Fix #</th><th>Project</th><th>Scan #</th><th>Status</th><th>Applied / Skipped</th><th>Requested By</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse ($recentFixes as $fx)
                    <tr>
                        <td>#{{ $fx->id }}</td>
                        <td><strong>{{ $fx->scan->upload->project->name ?? '—' }}</strong></td>
                        <td><a href="{{ route('scans.show', $fx->scan_id) }}">#{{ $fx->scan_id }}</a></td>
                        <td>
                            <span class="badge bg-{{ $fx->status === 'completed' ? 'success' : ($fx->status === 'ready' ? 'primary' : 'secondary') }}">
                                {{ $fx->status }}
                            </span>
                        </td>
                        <td>
                            <span class="text-success fw-bold">{{ $fx->applied_count }} applied</span> /
                            <span class="text-muted">{{ $fx->skipped_count }} skipped</span>
                        </td>
                        <td class="small text-muted">{{ $fx->requester->name ?? 'System' }}</td>
                        <td>
                            <a href="{{ route('fixes.show', $fx) }}" class="btn btn-outline-primary btn-sm">Details</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center py-4 text-muted">No fix runs executed yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Failed Jobs --}}
@if (count($failedJobs) > 0)
    <div class="card shadow-sm border-danger mb-4">
        <div class="card-header bg-danger text-white py-3">
            <h5 class="card-title mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i> Failed Queue Jobs</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr><th>UUID</th><th>Queue</th><th>Failed At</th><th>Exception</th></tr>
                </thead>
                <tbody>
                    @foreach ($failedJobs as $fj)
                        <tr>
                            <td><code>{{ substr($fj->uuid, 0, 8) }}...</code></td>
                            <td><span class="badge bg-secondary">{{ $fj->queue }}</span></td>
                            <td class="small">{{ $fj->failed_at }}</td>
                            <td class="small font-monospace text-danger" style="max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                {{ Str::limit($fj->exception, 120) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
