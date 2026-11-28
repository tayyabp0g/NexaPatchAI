@extends('layouts.app')

@section('title', 'System Settings · Admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">System & Scanner Settings</h1>
        <p class="text-muted small mb-0">Configure operational constraints, file size limits, and security scanner modes.</p>
    </div>
    <a href="{{ route('admin.monitor') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-speedometer2 me-1"></i> Job Monitor</a>
</div>

@if (session('status'))
    <div class="alert alert-success alert-dismissible fade show mb-4">
        <i class="bi bi-check-circle-fill me-2"></i> {{ session('status') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.settings.update') }}">
            @csrf

            <h5 class="card-title mb-3">Upload & Extraction Safeguards</h5>

            <div class="mb-3">
                <label for="upload_max_mb" class="form-label fw-semibold">Maximum Upload File Size (MB)</label>
                <input type="number" class="form-control @error('upload_max_mb') is-invalid @enderror" id="upload_max_mb" name="upload_max_mb" value="{{ old('upload_max_mb', $uploadMaxMb) }}" min="1" max="500">
                <div class="form-text">Controls the maximum size of single source files or ZIP archives accepted by the upload controller (FR-010).</div>
                @error('upload_max_mb')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Max ZIP Archive Entries</label>
                    <input type="text" class="form-control" value="{{ $maxEntries }} entries" disabled>
                    <div class="form-text">Zip-bomb defence threshold (configured via <code>.env</code> UPLOAD_MAX_ENTRIES).</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Max Uncompressed Extraction Size</label>
                    <input type="text" class="form-control" value="{{ $maxExtractedMb }} MB" disabled>
                    <div class="form-text">Prevents disk exhaustion from high-ratio compression bombs.</div>
                </div>
            </div>

            <hr class="my-4">

            <h5 class="card-title mb-3">Scanner Execution Mode</h5>

            <div class="mb-3">
                <label class="form-label fw-semibold d-block">Scan Dispatching</label>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="scan_mode" id="mode_sync" value="sync" {{ old('scan_mode', $scanMode) === 'sync' ? 'checked' : '' }}>
                    <label class="form-check-label" for="mode_sync">
                        <strong>Synchronous (sync)</strong> — Run scans immediately in HTTP request (best for local development).
                    </label>
                </div>
                <div class="form-check form-check-inline mt-2">
                    <input class="form-check-input" type="radio" name="scan_mode" id="mode_queue" value="queue" {{ old('scan_mode', $scanMode) === 'queue' ? 'checked' : '' }}>
                    <label class="form-check-label" for="mode_queue">
                        <strong>Asynchronous (queue)</strong> — Dispatch scans to queue worker (best for production servers).
                    </label>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold d-block">OSV Open-Source Dependency Vulnerability Check</label>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="osv_enabled" id="osv_enabled" value="1" {{ old('osv_enabled', $osvEnabled) ? 'checked' : '' }}>
                    <label class="form-check-label" for="osv_enabled">
                        Enable OSV online dependency checks against <code>composer.lock</code>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary mt-2">Save Settings</button>
        </form>
    </div>
</div>
@endsection
