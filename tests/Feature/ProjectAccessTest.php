<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $owner, string $name = 'Shop'): Project
    {
        $project = new Project(['name' => $name, 'language' => 'php', 'retention_days' => 30]);
        $project->owner_id = $owner->id;
        $project->save();

        return $project;
    }

    public function test_developer_can_create_project_and_it_is_audited(): void
    {
        $dev = User::factory()->create();

        $this->actingAs($dev)->post('/projects', [
            'name' => 'My Shop',
            'description' => 'Test',
            'language' => 'php',
            'retention_days' => 30,
        ])->assertRedirect();

        $this->assertDatabaseHas('projects', ['name' => 'My Shop', 'owner_id' => $dev->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.created', 'user_id' => $dev->id]);
    }

    public function test_project_validation_rejects_bad_input(): void
    {
        $dev = User::factory()->create();

        $this->actingAs($dev)->post('/projects', [
            'name' => '',
            'language' => 'cobol',
            'retention_days' => 0,
        ])->assertSessionHasErrors(['name', 'language', 'retention_days']);
    }

    public function test_developer_cannot_access_another_developers_project(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = $this->project($owner);

        $this->actingAs($other)->get(route('projects.show', $project))->assertForbidden();
        $this->actingAs($other)->get(route('projects.edit', $project))->assertForbidden();
        $this->actingAs($other)->put(route('projects.update', $project), [
            'name' => 'Hacked', 'language' => 'php', 'retention_days' => 30,
        ])->assertForbidden();
        $this->actingAs($other)->delete(route('projects.destroy', $project))->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Shop']);
    }

    public function test_project_list_only_shows_own_projects(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->project($a, 'Alpha Project');
        $this->project($b, 'Beta Project');

        $this->actingAs($a)->get('/projects')
            ->assertOk()
            ->assertSee('Alpha Project')
            ->assertDontSee('Beta Project');
    }

    public function test_owner_can_update_and_delete_project(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $this->actingAs($dev)->put(route('projects.update', $project), [
            'name' => 'Renamed', 'language' => 'php', 'retention_days' => 60,
        ])->assertRedirect();
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Renamed', 'retention_days' => 60]);

        $this->actingAs($dev)->delete(route('projects.destroy', $project))->assertRedirect('/projects');
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.deleted']);
    }

    public function test_assigned_reviewer_can_view_but_not_change_project(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        $project = $this->project($owner);
        $project->reviewers()->attach($reviewer->id);

        $this->actingAs($reviewer)->get(route('projects.show', $project))->assertOk();
        $this->actingAs($reviewer)->get(route('projects.edit', $project))->assertForbidden();
        $this->actingAs($reviewer)->delete(route('projects.destroy', $project))->assertForbidden();
    }

    public function test_unassigned_reviewer_cannot_view_project_and_cannot_create_projects(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        $project = $this->project($owner);

        $this->actingAs($reviewer)->get(route('projects.show', $project))->assertForbidden();
        $this->actingAs($reviewer)->get('/projects/create')->assertForbidden();
    }

    public function test_admin_can_view_any_project(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $project = $this->project($owner);

        $this->actingAs($admin)->get(route('projects.show', $project))->assertOk();
    }

    public function test_owner_can_assign_reviewer_by_email(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create(['email' => 'rev@example.com']);
        $project = $this->project($owner);

        $this->actingAs($owner)->post(route('projects.reviewers.store', $project), ['email' => 'rev@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($project->reviewers()->where('users.id', $reviewer->id)->exists());
    }

    public function test_only_reviewer_accounts_can_be_assigned(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['email' => 'plain@example.com']); // developer, not reviewer
        $project = $this->project($owner);

        $this->actingAs($owner)->post(route('projects.reviewers.store', $project), ['email' => 'plain@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, $project->reviewers()->count());
    }

    public function test_other_developer_cannot_assign_reviewers(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        User::factory()->reviewer()->create(['email' => 'rev@example.com']);
        $project = $this->project($owner);

        $this->actingAs($other)->post(route('projects.reviewers.store', $project), ['email' => 'rev@example.com'])
            ->assertForbidden();
    }
}
