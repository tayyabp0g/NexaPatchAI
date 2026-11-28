<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProjectReviewerController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('manageReviewers', $project);

        $data = $request->validate(['email' => ['required', 'email']]);

        $reviewer = User::where('email', Str::lower($data['email']))
            ->where('role', User::ROLE_REVIEWER)
            ->first();

        if (! $reviewer) {
            return back()->withErrors(['email' => 'No reviewer account found with this email.']);
        }

        $project->reviewers()->syncWithoutDetaching([$reviewer->id]);
        AuditLogger::log('project.reviewer_assigned', $project, ['reviewer_id' => $reviewer->id]);

        return back()->with('status', "{$reviewer->name} was assigned as reviewer.");
    }

    public function destroy(Project $project, User $user): RedirectResponse
    {
        Gate::authorize('manageReviewers', $project);

        $project->reviewers()->detach($user->id);
        AuditLogger::log('project.reviewer_removed', $project, ['reviewer_id' => $user->id]);

        return back()->with('status', 'Reviewer removed.');
    }
}
