<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const GOOD_PASSWORD = 'Str0ngPassw0rd!';

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/projects')->assertRedirect('/login');
    }

    public function test_login_and_register_pages_load(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Log in')
            ->assertSee('name="remember"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false);
        $this->get('/register')
            ->assertOk()
            ->assertSee('Create account')
            ->assertSee('autocomplete="name"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="new-password"', false);
    }

    public function test_user_can_register_and_becomes_developer(): void
    {
        $response = $this->post('/register', [
            'name' => 'New Dev',
            'email' => 'newdev@example.com',
            'password' => self::GOOD_PASSWORD,
            'password_confirmation' => self::GOOD_PASSWORD,
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'newdev@example.com', 'role' => 'developer']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.registered']);
        $this->assertTrue(Hash::check(self::GOOD_PASSWORD, User::first()->password));
    }

    public function test_registration_cannot_choose_admin_role(): void
    {
        $this->post('/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => self::GOOD_PASSWORD,
            'password_confirmation' => self::GOOD_PASSWORD,
            'role' => 'admin',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'sneaky@example.com', 'role' => 'developer']);
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->post('/register', [
            'name' => 'Weak',
            'email' => 'weak@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
    }

    public function test_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'dev@example.com', 'password' => self::GOOD_PASSWORD]);

        $this->post('/login', ['email' => 'dev@example.com', 'password' => self::GOOD_PASSWORD])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'login.success', 'user_id' => $user->id]);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_remember_me_uses_the_authentication_recaller_cookie(): void
    {
        $user = User::factory()->create(['email' => 'dev@example.com', 'password' => self::GOOD_PASSWORD]);

        $response = $this->post('/login', [
            'email' => 'dev@example.com',
            'password' => self::GOOD_PASSWORD,
            'remember' => '1',
        ]);

        $response->assertRedirect('/dashboard')
            ->assertCookie($this->app['auth']->guard()->getRecallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_failed_login_is_rejected_and_audited(): void
    {
        User::factory()->create(['email' => 'dev@example.com', 'password' => self::GOOD_PASSWORD]);

        $this->post('/login', ['email' => 'dev@example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, AuditLog::where('action', 'login.failed')->count());
    }

    public function test_account_is_locked_after_repeated_failures(): void
    {
        User::factory()->create(['email' => 'dev@example.com', 'password' => self::GOOD_PASSWORD]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'dev@example.com', 'password' => 'wrong-password']);
        }

        // Even the correct password is refused while locked out
        $this->post('/login', ['email' => 'dev@example.com', 'password' => self::GOOD_PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'login.locked']);
    }

    public function test_password_reset_link_is_sent_to_registered_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'dev@example.com']);

        $this->post('/forgot-password', ['email' => 'dev@example.com'])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_gets_same_message_and_no_notification(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'dev@example.com']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'dev@example.com',
            'password' => self::GOOD_PASSWORD,
            'password_confirmation' => self::GOOD_PASSWORD,
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check(self::GOOD_PASSWORD, $user->fresh()->password));
    }
}
