@extends('auth.layout')
@section('title', 'Create account')
@section('content')
<div class="auth-card">
    <h2>Create an account</h2>
    <p class="text-secondary mb-4">Start scanning and repairing your projects.</p>
    <p class="small text-secondary">After you create your account, your browser may ask to save your email and password for next time.</p>
    @include('partials.alerts')
    <form method="POST" action="{{ route('register.attempt') }}" autocomplete="on">
        @csrf
        <div class="mb-3"><label class="form-label" for="name">Full name</label><input id="name" name="name" value="{{ old('name') }}" class="form-control" autocomplete="name" required autofocus></div>
        <div class="mb-3"><label class="form-label" for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email') }}" class="form-control" autocomplete="username" required></div>
        <div class="mb-3"><label class="form-label" for="password">Password</label><input id="password" type="password" name="password" class="form-control" autocomplete="new-password" required></div>
        <div class="mb-4"><label class="form-label" for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" class="form-control" autocomplete="new-password" required></div>
        <button class="btn btn-primary w-100 py-2" type="submit">Create account</button>
    </form>
    <p class="text-center text-secondary mt-4 mb-0">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
</div>
@endsection
