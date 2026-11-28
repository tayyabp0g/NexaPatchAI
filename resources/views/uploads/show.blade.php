@extends('layouts.app')
@section('title', $upload->original_name)
@section('content')
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="{{ route('projects.index') }}">Projects</a></li>
        <li class="breadcrumb-item"><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $upload->original_name }}</li>
    </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <h1 class="h4 mb-0">{{ $upload->original_name }}</h1>
    <div class="d-flex gap-2">
        @can('manageUploads', $project)
            <form method="POST" action="{{ route('projects.uploads.scans.store', [$project, $upload]) }}">
                @csrf
                <button class="btn btn-success btn-sm" type="submit" data-loading="Scanning..."><i class="bi bi-shield-search me-1"></i> Start security scan</button>
            </form>
        @endcan
        <a href="{{ route('projects.uploads.download', [$project, $upload]) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i> Download original</a>
    </div>
</div>

@if ($upload->scans()->exists())
<div class="np-card p-3 mb-3">
    <h2 class="h6 mb-2">Scan history</h2>
    @foreach ($upload->scans()->latest('id')->limit(10)->get() as $s)
        <a href="{{ route('scans.show', $s) }}" class="np-list-row">
            <span><span class="t">Scan #{{ $s->id }}</span> <span class="s">{{ $s->created_at->format('d M Y H:i') }}</span></span>
            @if ($s->status === 'completed')<span class="fw-bold grade-{{ $s->grade }}">{{ $s->score }} ({{ $s->grade }})</span>@else<span class="badge text-bg-secondary">{{ $s->status }}</span>@endif
        </a>
    @endforeach
</div>
@endif

<div class="row g-3">
    <div class="col-lg-4">
        <div class="np-card p-3">
            <h2 class="h6 mb-3">Details</h2>
            <dl class="mb-0 small">
                <dt>Type</dt><dd>{{ $upload->is_archive ? 'ZIP project' : 'Single file' }}</dd>
                <dt>Uploaded</dt><dd>{{ $upload->created_at->format('d M Y H:i') }} by {{ $upload->uploader?->name ?? 'unknown' }}</dd>
                <dt>Size</dt><dd>{{ number_format($upload->size_bytes / 1024, 1) }} KB</dd>
                <dt>Source files stored</dt><dd>{{ $upload->file_count }}@if ($upload->skipped_count) ({{ $upload->skipped_count }} skipped: not source code or in vendor/node_modules)@endif</dd>
                <dt>SHA-256</dt><dd class="np-hash">{{ $upload->sha256 }}</dd>
                <dt>Original integrity</dt>
                <dd>
                    @if ($intact)
                        <span class="badge text-bg-success"><i class="bi bi-shield-check me-1"></i>Unchanged</span>
                    @else
                        <span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Changed or missing</span>
                    @endif
                    <div class="text-secondary mt-1">The original is stored read-only and never modified.</div>
                </dd>
            </dl>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="np-card p-3">
            <h2 class="h6 mb-3">Stored source files</h2>
            @if (count($listing['files']))
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Path</th><th class="text-end">Size</th></tr></thead>
                        <tbody>
                        @foreach ($listing['files'] as $f)
                            <tr>
                                <td><i class="bi bi-file-earmark-code me-1 text-secondary"></i>{{ $f['path'] }}</td>
                                <td class="text-end text-secondary small">{{ number_format($f['size'] / 1024, 1) }} KB</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($listing['truncated'])
                    <p class="small text-secondary mt-2 mb-0">Showing the first 500 files.</p>
                @endif
            @else
                <div class="np-empty">No files found.</div>
            @endif
        </div>
    </div>
</div>
@endsection
