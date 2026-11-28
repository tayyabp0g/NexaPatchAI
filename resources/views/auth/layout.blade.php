<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'NexaPatch AI')</title>
    <link href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/nexapatch.css') }}" rel="stylesheet">
    <style>
        body { background:#eef2f7; }
        .auth-page { min-height:100vh; display:grid; grid-template-columns:minmax(280px, 42%) 1fr; }
        .auth-brand { background:#0f1b2d; color:#fff; display:flex; align-items:center; padding:clamp(2rem,7vw,6rem); }
        .auth-brand-inner { max-width:440px; }
        .auth-brand .brand-icon { width:58px;height:58px;border-radius:14px;background:#2b7fff;display:grid;place-items:center;font-size:2rem;margin-bottom:1.5rem; }
        .auth-brand h1 { font-size:clamp(2rem,4vw,3.2rem); font-weight:700; }
        .auth-panel { display:grid; place-items:center; padding:2rem; }
        .auth-card { width:min(430px,100%); background:#fff;border:1px solid #e1e7ef;border-radius:16px;padding:2rem;box-shadow:0 16px 45px rgba(15,27,45,.08); }
        .auth-card h2 { font-size:1.5rem;font-weight:700; }
        .auth-card .form-control { min-height:46px; }
        @media (max-width:800px){ .auth-page{grid-template-columns:1fr}.auth-brand{min-height:210px}.auth-brand h1{font-size:2rem}.auth-panel{padding:1.25rem}.auth-card{margin-top:-2rem} }
    </style>
</head>
<body>
<div class="auth-page">
    <section class="auth-brand">
        <div class="auth-brand-inner">
            <div class="brand-icon"><i class="bi bi-shield-check"></i></div>
            <h1>NexaPatch AI</h1>
            <p class="mt-3 mb-0 fs-5 text-white-50">Secure code, reviewed fixes, and clear verification reports.</p>
        </div>
    </section>
    <main class="auth-panel">@yield('content')</main>
</div>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
</body>
</html>
