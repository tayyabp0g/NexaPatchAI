@extends('layouts.app')
@section('title', 'Projects')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h1 class="h4 mb-1">Projects</h1><p class="text-secondary mb-0">Manage codebases and security scans.</p></div>
    @can('create', App\Models\Project::class)
        <a href="{{ route('projects.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New project</a>
    @endcan
</div>
<div class="np-card">
    @forelse ($projects as $project)
        <a href="{{ route('projects.show', $project) }}" class="np-project-row d-flex justify-content-between align-items-center p-3 text-decoration-none border-bottom">
            <div class="d-flex align-items-center gap-3">
                <div class="np-project-icon rounded-3 bg-primary-subtle text-primary" aria-hidden="true"><i class="bi bi-folder2-open fs-5"></i></div>
                <div><div class="fw-semibold text-dark">{{ $project->name }}</div><div class="small text-secondary">{{ $project->uploads_count }} upload{{ $project->uploads_count === 1 ? '' : 's' }} · {{ ucfirst($project->language) }}</div></div>
            </div>
            <i class="bi bi-chevron-right text-secondary"></i>
        </a>
    @empty
        <div class="np-empty"><i class="bi bi-folder2"></i><p class="mt-2 mb-3">No projects available yet.</p>@can('create', App\Models\Project::class)<a href="{{ route('projects.create') }}" class="btn btn-primary btn-sm">Create your first project</a>@endcan</div>
    @endforelse
</div>
@if ($projects->hasPages())<div class="mt-3">{{ $projects->links() }}</div>@endif
@endsection
