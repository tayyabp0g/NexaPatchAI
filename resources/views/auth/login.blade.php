@extends('auth.layout')
@section('title', 'Login')
@section('content')
<div class="auth-card">
    <h2>Welcome back</h2>
    <p class="text-secondary mb-4">Sign in to your NexaPatch account.</p>
    <p class="small text-secondary">Choose a saved account from your browser to autofill. “Remember me” keeps you signed in; your password stays in your browser’s password manager.</p>
    @include('partials.alerts')
    <form method="POST" action="{{ route('login.attempt') }}" autocomplete="on">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="email">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" class="form-control" autocomplete="username" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input id="password" type="password" name="password" class="form-control" autocomplete="current-password" required>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" @checked(old('remember'))><label class="form-check-label" for="remember">Remember me</label></div>
            <a href="{{ route('password.request') }}">Forgot password?</a>
        </div>
        <button class="btn btn-primary w-100 py-2" type="submit">Sign in</button>
    </form>
    <p class="text-center text-secondary mt-4 mb-0">New here? <a href="{{ route('register') }}">Create an account</a></p>
</div>
@endsection
