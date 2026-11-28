<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') | NexaPatch AI</title>
    <link href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/nexapatch.css') }}?v={{ filemtime(public_path('css/nexapatch.css')) }}" rel="stylesheet">
</head>
<body>
<div class="np-shell">
    <aside class="offcanvas-lg offcanvas-start np-sidebar" tabindex="-1" id="sidebar" aria-label="Main navigation">
        <div class="np-brand">
            <i class="bi bi-shield-check"></i> NexaPatch AI
            <button type="button" class="btn-close btn-close-white ms-auto d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close"></button>
        </div>
        <nav class="np-nav">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="{{ route('projects.index') }}" class="{{ request()->routeIs('projects.*') ? 'active' : '' }}">
                <i class="bi bi-folder2-open"></i> Projects
            </a>
            @if (auth()->user()->isAdmin())
                <div class="np-section">Administration</div>
                <a href="{{ route('admin.users') }}" class="{{ request()->routeIs('admin.users*') ? 'active' : '' }}">
                    <i class="bi bi-people"></i> Users
                </a>
                <a href="{{ route('admin.monitor') }}" class="{{ request()->routeIs('admin.monitor') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2"></i> Job monitor
                </a>
                <a href="{{ route('admin.settings') }}" class="{{ request()->routeIs('admin.settings') ? 'active' : '' }}">
                    <i class="bi bi-sliders"></i> Settings
                </a>
                <a href="{{ route('admin.audit') }}" class="{{ request()->routeIs('admin.audit') ? 'active' : '' }}">
                    <i class="bi bi-journal-text"></i> Audit log
                </a>
            @endif
        </nav>
        <div class="np-side-foot">
            <div class="np-side-user">
                <div class="np-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
                <div class="lh-sm">
                    <div class="fw-semibold">{{ auth()->user()->name }}</div>
                    <div class="small" style="color:var(--np-muted)">{{ ucfirst(auth()->user()->role) }}</div>
                </div>
            </div>
        </div>
    </aside>

    <div class="np-main">
        <header class="np-topbar">
            <button class="btn btn-outline-secondary btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu">
                <i class="bi bi-list"></i>
            </button>
            <div class="d-none d-lg-block fw-semibold text-secondary">@yield('title', 'Dashboard')</div>
            <div class="dropdown">
                <button class="btn btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle me-1"></i> {{ auth()->user()->name }}
                    <span class="badge text-bg-secondary ms-1">{{ ucfirst(auth()->user()->role) }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="dropdown-item-text small text-secondary">{{ auth()->user()->email }}</li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-1"></i> Log out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </header>

        <main class="np-content">
            @include('partials.alerts')
            @yield('content')
        </main>
    </div>
</div>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
