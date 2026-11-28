<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);

        // Public registration always creates a developer. Admins/reviewers are assigned by an admin.
        $user = new User([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
        ]);
        $user->role = User::ROLE_DEVELOPER;
        $user->save();

        Auth::login($user);
        $request->session()->regenerate();

        AuditLogger::log('user.registered', $user, [], $user->id);

        return redirect()->route('dashboard');
    }

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $credentials['email'] = Str::lower($credentials['email']);

        $maxAttempts = (int) config('nexapatch.login_max_attempts');
        $lockSeconds = (int) config('nexapatch.login_lockout_seconds');
        $key = 'login:'.Str::lower($credentials['email']).'|'.$request->ip();

        // FR-007: temporary lockout after repeated failures
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            AuditLogger::log('login.locked', null, ['email' => $credentials['email']]);

            throw ValidationException::withMessages([
                'email' => "Too many failed attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, $lockSeconds);
            AuditLogger::log('login.failed', null, ['email' => $credentials['email']]);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user = $request->user();

        if (! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            AuditLogger::log('login.disabled_blocked', $user);

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated by an administrator.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('login.success', $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        AuditLogger::log('logout', $request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
