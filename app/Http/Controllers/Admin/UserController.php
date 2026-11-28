<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::withCount('projects')->orderBy('name')->paginate(15);

        return view('admin.users', compact('users'));
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(User::ROLES)],
        ]);

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['role' => 'You cannot change your own role.']);
        }

        $old = $user->role;
        $user->role = $data['role'];
        $user->save();

        AuditLogger::log('user.role_changed', $user, ['from' => $old, 'to' => $user->role]);

        return back()->with('status', "Role of {$user->name} changed to {$user->role}.");
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['status' => 'You cannot deactivate your own administrator account.']);
        }

        $user->is_active = ! $user->isActive();
        $user->remember_token = \Illuminate\Support\Str::random(60);
        $user->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        AuditLogger::log('user.status_toggled', $user, ['active' => $user->is_active]);

        $state = $user->is_active ? 'activated' : 'deactivated';

        return back()->with('status', "User {$user->name} has been {$state}.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:10'],
        ]);

        $user->password = $data['password'];
        $user->remember_token = \Illuminate\Support\Str::random(60);
        $user->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        AuditLogger::log('user.password_reset_by_admin', $user);

        return back()->with('status', "Password for {$user->name} has been reset successfully.");
    }
}
