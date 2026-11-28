<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\AuditLogger;
use App\Services\UploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        $projects = Project::visibleTo($request->user())
            ->with('owner')
            ->withCount('uploads')
            ->latest()
            ->paginate(10);

        return view('projects.index', compact('projects'));
    }

    public function create(): View
    {
        Gate::authorize('create', Project::class);

        return view('projects.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Project::class);

        $project = new Project($this->validated($request));
        $project->owner_id = $request->user()->id;
        $project->save();

        AuditLogger::log('project.created', $project, ['name' => $project->name]);

        return redirect()->route('projects.show', $project)->with('status', 'Project created.');
    }

    public function show(Project $project): View
    {
        Gate::authorize('view', $project);

        $project->load([
            'owner',
            'reviewers',
            'uploads' => fn ($q) => $q->latest(),
            'uploads.scans' => fn ($q) => $q->latest('id'),
        ]);

        return view('projects.show', compact('project'));
    }

    public function edit(Project $project): View
    {
        Gate::authorize('update', $project);

        return view('projects.edit', compact('project'));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $project->update($this->validated($request));
        AuditLogger::log('project.updated', $project, ['name' => $project->name]);

        return redirect()->route('projects.show', $project)->with('status', 'Project updated.');
    }

    public function destroy(Project $project, UploadService $uploads): RedirectResponse
    {
        Gate::authorize('delete', $project);

        // FR-014: delete the stored files together with the project.
        AuditLogger::log('project.deleted', $project, ['name' => $project->name]);
        $uploads->deleteProjectFiles($project);
        $project->delete();

        return redirect()->route('projects.index')->with('status', 'Project deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'language' => ['required', 'in:php'], // more languages come in later phases
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);
    }
}
