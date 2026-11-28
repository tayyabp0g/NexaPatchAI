<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo accounts for LOCAL development only (SEED_DEMO_USERS=true).
     * Default password for all three: Password@123  (change before any real use).
     * Safe to run many times: it never creates duplicates.
     */
    public function run(): void
    {
        if (! config('nexapatch.seed_demo_users')) {
            $this->command?->warn('SEED_DEMO_USERS is not true: no demo users created.');

            return;
        }

        $admin = $this->user('Admin User', 'admin@nexapatch.test', User::ROLE_ADMIN);
        $dev = $this->user('Demo Developer', 'developer@nexapatch.test', User::ROLE_DEVELOPER);
        $reviewer = $this->user('Demo Reviewer', 'reviewer@nexapatch.test', User::ROLE_REVIEWER);

        $project = Project::firstOrCreate(
            ['owner_id' => $dev->id, 'name' => 'Demo PHP Shop'],
            ['description' => 'Sample project created by the seeder.', 'language' => 'php', 'retention_days' => 30]
        );
        $project->reviewers()->syncWithoutDetaching([$reviewer->id]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => 'Password@123']);
            $user->role = $role;
            $user->email_verified_at = now();
            $user->save();
        }

        return $user;
    }
}
