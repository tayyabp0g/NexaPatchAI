@extends('layouts.app')
@section('title', 'New project')
@section('content')
<div class="row justify-content-center"><div class="col-lg-8">
<div class="d-flex align-items-center gap-2 mb-3"><a href="{{ route('projects.index') }}" class="btn btn-light border btn-sm"><i class="bi bi-arrow-left"></i></a><h1 class="h4 mb-0">Create project</h1></div>
<div class="np-card p-4">
<form method="POST" action="{{ route('projects.store') }}">
@csrf
<div class="mb-3"><label class="form-label" for="name">Project name</label><input id="name" name="name" value="{{ old('name') }}" class="form-control" maxlength="120" required autofocus></div>
<div class="mb-3"><label class="form-label" for="description">Description</label><textarea id="description" name="description" class="form-control" rows="4" maxlength="2000">{{ old('description') }}</textarea></div>
<div class="row g-3 mb-4">
<div class="col-md-6"><label class="form-label" for="language">Language</label><select id="language" name="language" class="form-select" required><option value="php" @selected(old('language','php')==='php')>PHP</option></select></div>
<div class="col-md-6"><label class="form-label" for="retention_days">Retention (days)</label><input id="retention_days" name="retention_days" type="number" min="1" max="365" value="{{ old('retention_days',30) }}" class="form-control" required></div>
</div>
<div class="d-flex justify-content-end gap-2"><a href="{{ route('projects.index') }}" class="btn btn-outline-secondary">Cancel</a><button type="submit" class="btn btn-primary">Create project</button></div>
</form>
</div></div></div>
@endsection
