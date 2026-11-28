@extends('layouts.app')
@section('title', 'Users · Admin')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">User Accounts</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.monitor') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-speedometer2 me-1"></i> Monitor</a>
        <a href="{{ route('admin.audit') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-journal-text me-1"></i> Audit Log</a>
    </div>
</div>

@if (session('status'))
    <div class="alert alert-success alert-dismissible fade show mb-3">
        <i class="bi bi-check-circle-fill me-2"></i> {{ session('status') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="np-card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Projects</th>
                    <th>Status</th>
                    <th>Last login</th>
                    <th style="min-width:180px">Role</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($users as $u)
                <tr>
                    <td>{{ $u->name }} @if ($u->id === auth()->id()) <span class="badge text-bg-light border">you</span> @endif</td>
                    <td><code>{{ $u->email }}</code></td>
                    <td>{{ $u->projects_count }}</td>
                    <td>
                        @if ($u->isActive())
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                        @else
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Deactivated</span>
                        @endif
                    </td>
                    <td class="text-secondary small">{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                    <td>
                        @if ($u->id === auth()->id())
                            <span class="badge text-bg-secondary">{{ ucfirst($u->role) }}</span>
                        @else
                            <form method="POST" action="{{ route('admin.users.role', $u) }}" class="d-flex gap-2">
                                @csrf
                                @method('PATCH')
                                <select name="role" class="form-select form-select-sm" aria-label="Role of {{ $u->name }}">
                                    @foreach (\App\Models\User::ROLES as $role)
                                        <option value="{{ $role }}" @selected($u->role === $role)>{{ ucfirst($role) }}</option>
                                    @endforeach
                                </select>
                                <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                            </form>
                        @endif
                    </td>
                    <td class="text-end">
                        @if ($u->id !== auth()->id())
                            <div class="d-inline-flex gap-1">
                                <form method="POST" action="{{ route('admin.users.toggle', $u) }}">
                                    @csrf
                                    <button class="btn btn-sm {{ $u->isActive() ? 'btn-outline-warning' : 'btn-outline-success' }}" type="submit" title="{{ $u->isActive() ? 'Deactivate user' : 'Activate user' }}">
                                        {{ $u->isActive() ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>

                                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#resetModal{{ $u->id }}" title="Reset Password">
                                    <i class="bi bi-key"></i>
                                </button>
                            </div>

                            {{-- Password Reset Modal --}}
                            <div class="modal fade" id="resetModal{{ $u->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <form method="POST" action="{{ route('admin.users.reset-password', $u) }}" class="modal-content text-start">
                                        @csrf
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reset Password for {{ $u->name }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label">New Temporary Password</label>
                                                <input type="password" name="password" class="form-control" placeholder="Minimum 10 characters" required minlength="10">
                                                <div class="form-text">User will be able to log in with this password immediately.</div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger btn-sm">Reset Password</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $users->links() }}</div>
</div>
@endsection
