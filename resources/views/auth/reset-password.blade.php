@extends('auth.layout')
@section('title', 'Reset password')
@section('content')
<div class="auth-card">
    <h2>Choose a new password</h2>
    @include('partials.alerts')
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="mb-3"><label class="form-label" for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email', $email) }}" class="form-control" required></div>
        <div class="mb-3"><label class="form-label" for="password">New password</label><input id="password" type="password" name="password" class="form-control" required></div>
        <div class="mb-4"><label class="form-label" for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" class="form-control" required></div>
        <button class="btn btn-primary w-100 py-2" type="submit">Reset password</button>
    </form>
</div>
@endsection
