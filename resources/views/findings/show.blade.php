@extends('layouts.app')
@section('title', $finding->title)
@section('content')
<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item"><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></li>
        <li class="breadcrumb-item"><a href="{{ route('scans.show', $scan) }}">Scan #{{ $scan->id }}</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $finding->rule_id }}</li>
    </ol>
</nav>

<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
    <span class="sev sev-{{ $finding->severity }}">{{ $finding->severity }}</span>
    <span class="badge text-bg-{{ $finding->confidence === 'confirmed' ? 'dark' : 'secondary' }}">{{ $finding->confidence }}</span>
    @if ($finding->status !== 'open') <span class="badge text-bg-light border">{{ str_replace('_', ' ', $finding->status) }}</span> @endif
</div>
<h1 class="h4">{{ $finding->title }}</h1>
<p class="np-loc text-secondary"><i class="bi bi-file-earmark-code me-1"></i>{{ $finding->file_path }}{{ $finding->line_start ? ':'.$finding->line_start : '' }}</p>

<div class="row g-3">
    <div class="col-lg-8">
        @if ($finding->snippetLines())
            <div class="np-code mb-3" role="region" aria-label="Code snippet">
                @foreach ($finding->snippetLines() as $l)
                    <div class="ln {{ $l['hit'] ? 'hit' : '' }}"><span class="n">{{ $l['n'] }}</span><span>{{ $l['code'] }}</span></div>
                @endforeach
            </div>
            <p class="small text-secondary">Secret values are hidden in snippets.</p>
        @endif

        <div class="np-card p-3 mb-3">
            <h2 class="h6">What is the problem?</h2>
            <p class="mb-3">{{ $finding->description }}</p>
            <h2 class="h6">Why it matters</h2>
            <p class="mb-0">{{ $finding->impact }}</p>
        </div>

        <div class="np-fix-box mb-3">
            <h2 class="h6"><i class="bi bi-wrench-adjustable me-1"></i>How to fix it</h2>
            <p class="mb-0">{{ $finding->remediation }}</p>
        </div>

        @if ($finding->references)
            <div class="np-card p-3">
                <h2 class="h6">References</h2>
                <ul class="mb-0 small">
                    @foreach ($finding->references as $ref)
                        <li><a href="{{ $ref }}" target="_blank" rel="noopener noreferrer">{{ $ref }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="np-card p-3 mb-3">
            <h2 class="h6 mb-3">Details</h2>
            <dl class="small mb-0">
                <dt>Rule</dt><dd>{{ $finding->rule_id }}</dd>
                <dt>Type</dt><dd>{{ $finding->categoryLabel() }}</dd>
                <dt>CWE</dt><dd>{{ $finding->cwe_id ?? '-' }}</dd>
                <dt>OWASP</dt><dd>{{ $finding->owasp_category ?? '-' }}</dd>
                <dt>Engine</dt><dd>{{ $finding->engine }}</dd>
            </dl>
        </div>

        @if ($canManage)
            <div class="np-card p-3">
                <h2 class="h6">Review</h2>
                <p class="small text-secondary">Mark findings you have checked. Ignored and false positive findings do not count in the score.</p>
                <form method="POST" action="{{ route('findings.status', $finding) }}" class="d-grid gap-2">
                    @csrf
                    @method('PATCH')
                    @if ($finding->status !== 'open')
                        <button name="status" value="open" class="btn btn-outline-primary btn-sm">Reopen</button>
                    @endif
                    @if ($finding->status !== 'false_positive')
                        <button name="status" value="false_positive" class="btn btn-outline-secondary btn-sm">This is a false positive</button>
                    @endif
                    @if ($finding->status !== 'ignored')
                        <button name="status" value="ignored" class="btn btn-outline-secondary btn-sm">Accept the risk (ignore)</button>
                    @endif
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
