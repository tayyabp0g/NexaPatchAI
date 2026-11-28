@extends('auth.layout')
@section('title', 'Forgot password')
@section('content')
<div class="auth-card">
    <h2>Reset your password</h2>
    <p class="text-secondary mb-4">Enter your email and we will send reset instructions if the account exists.</p>
    @include('partials.alerts')
    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="mb-4"><label class="form-label" for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email') }}" class="form-control" required autofocus></div>
        <button class="btn btn-primary w-100 py-2" type="submit">Send reset link</button>
    </form>
    <p class="text-center mt-4 mb-0"><a href="{{ route('login') }}">Back to sign in</a></p>
</div>
@endsection
