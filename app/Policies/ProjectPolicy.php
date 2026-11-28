<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // the list itself is filtered with Project::visibleTo()
    }

    public function view(User $user, Project $project): bool
    {
        return $user->isAdmin()
            || $project->owner_id === $user->id
            || $project->reviewers()->where('users.id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isDeveloper();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->isAdmin() || $project->owner_id === $user->id;
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->isAdmin() || $project->owner_id === $user->id;
    }

    public function manageReviewers(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /** Upload or delete source code: owner and admin only. Viewing/downloading uses view(). */
    public function manageUploads(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }
}
