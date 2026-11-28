@extends('layouts.app')
@section('title', $project->name)
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">{{ $project->name }}</h1>
        <div class="text-secondary small">
            Owner: {{ $project->owner->name }} &middot; {{ strtoupper($project->language) }} &middot; created {{ $project->created_at->format('d M Y') }}
        </div>
    </div>
    <div class="d-flex gap-2">
        @can('update', $project)
            <a href="{{ route('projects.edit', $project) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i> Edit</a>
        @endcan
        @can('delete', $project)
            <form method="POST" action="{{ route('projects.destroy', $project) }}" data-confirm="Delete this project and all its files? This cannot be undone.">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i> Delete</button>
            </form>
        @endcan
    </div>
</div>

<div class="row g-3">
    <div class="col-12">
        <div class="np-card p-3 mb-3">
            <h2 class="h6">About</h2>
            <p class="mb-2">{{ $project->description ?: 'No description added.' }}</p>
            <div class="small text-secondary">Files are kept for {{ $project->retention_days }} days.</div>
        </div>

        <div class="np-card p-3 mb-3">
            <h2 class="h6 mb-3">Source code uploads</h2>

            @can('manageUploads', $project)
                <form id="upload-form" method="POST" action="{{ route('projects.uploads.store', $project) }}" enctype="multipart/form-data"
                      data-max-mb="{{ config('nexapatch.upload_max_mb') }}"
                      data-allowed="{{ implode(',', config('nexapatch.upload.allowed_extensions')) }}" novalidate>
                    @csrf
                    <div id="dropzone" class="np-dropzone" tabindex="0" role="button" aria-label="Choose a file or drop it here">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <p class="mb-1 mt-2">Drag and drop a PHP file or a project ZIP here, or <span class="link">browse</span></p>
                        <small class="text-secondary">
                            Up to {{ config('nexapatch.upload_max_mb') }} MB. Source files: {{ implode(', ', config('nexapatch.upload.allowed_extensions')) }}, or a .zip.
                            Folders like vendor and node_modules inside a ZIP are skipped.
                        </small>
                        <input type="file" name="file" id="file-input" hidden>
                    </div>

                    <div id="file-chosen" class="d-none d-flex flex-wrap align-items-center gap-2 mt-3">
                        <i class="bi bi-file-earmark-code"></i>
                        <span id="file-name" class="fw-semibold"></span>
                        <button type="submit" id="upload-submit" class="btn btn-primary btn-sm ms-auto">Upload</button>
                        <button type="button" id="file-clear" class="btn btn-outline-secondary btn-sm">Remove</button>
                    </div>

                    <div id="upload-progress" class="progress d-none mt-3" role="progressbar" aria-label="Upload progress">
                        <div class="progress-bar" style="width:0%">0%</div>
                    </div>
                    <div id="upload-error" class="text-danger small mt-2 d-none" role="alert"></div>
                </form>
                <hr>
            @endcan

            @if ($project->uploads->count())
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>File</th><th>Type</th><th>Source files</th><th>Last scan</th><th>Uploaded</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($project->uploads as $upload)
                            <tr>
                                <td>
                                    <a href="{{ route('projects.uploads.show', [$project, $upload]) }}" class="text-decoration-none fw-semibold">{{ $upload->original_name }}</a>
                                    <div class="np-hash text-secondary">{{ substr($upload->sha256, 0, 16) }}...</div>
                                </td>
                                <td><span class="badge text-bg-light border">{{ $upload->is_archive ? 'ZIP' : 'File' }}</span></td>
                                <td>{{ $upload->file_count }}@if ($upload->skipped_count) <span class="text-secondary small">(+{{ $upload->skipped_count }} skipped)</span>@endif</td>
                                <td>
                                    @php($last = $upload->scans->first())
                                    @if ($last && $last->status === 'completed')
                                        <a href="{{ route('scans.show', $last) }}" class="text-decoration-none fw-bold grade-{{ $last->grade }}">{{ $last->score }} ({{ $last->grade }})</a>
                                    @elseif ($last)
                                        <a href="{{ route('scans.show', $last) }}" class="badge text-bg-{{ $last->status === 'failed' ? 'danger' : 'warning' }} text-decoration-none">{{ $last->status }}</a>
                                    @else
                                        <span class="text-secondary small">not scanned</span>
                                    @endif
                                </td>
                                <td class="small text-secondary">{{ $upload->created_at->format('d M Y H:i') }}</td>
                                <td class="text-end text-nowrap">
                                    @can('manageUploads', $project)
                                        <form method="POST" action="{{ route('projects.uploads.scans.store', [$project, $upload]) }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-success" type="submit" data-loading="Scanning..."><i class="bi bi-shield-search me-1"></i>Scan</button>
                                        </form>
                                    @endcan
                                    <a href="{{ route('projects.uploads.show', [$project, $upload]) }}" class="btn btn-sm btn-outline-primary" title="Details"><i class="bi bi-eye"></i></a>
                                    <a href="{{ route('projects.uploads.download', [$project, $upload]) }}" class="btn btn-sm btn-outline-secondary" title="Download original"><i class="bi bi-download"></i></a>
                                    @can('manageUploads', $project)
                                        <form method="POST" action="{{ route('projects.uploads.destroy', [$project, $upload]) }}" class="d-inline" data-confirm="Delete this upload and its stored files?">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" title="Delete" aria-label="Delete upload"><i class="bi bi-trash"></i></button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="np-empty">
                    <i class="bi bi-file-earmark-code"></i>
                    <p class="mt-2 mb-0">No code uploaded yet.</p>
                </div>
            @endif
        </div>
    </div>

</div>
@endsection

@can('manageUploads', $project)
@push('scripts')
<script src="{{ asset('js/upload.js') }}"></script>
@endpush
@endcan
