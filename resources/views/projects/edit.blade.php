@extends('layouts.app')
@section('title', 'Edit '.$project->name)
@section('content')
<div class="row justify-content-center"><div class="col-lg-8">
<div class="d-flex align-items-center gap-2 mb-3"><a href="{{ route('projects.show',$project) }}" class="btn btn-light border btn-sm"><i class="bi bi-arrow-left"></i></a><h1 class="h4 mb-0">Edit project</h1></div>
<div class="np-card p-4">
<form method="POST" action="{{ route('projects.update',$project) }}">
@csrf
@method('PATCH')
<div class="mb-3"><label class="form-label" for="name">Project name</label><input id="name" name="name" value="{{ old('name',$project->name) }}" class="form-control" maxlength="120" required></div>
<div class="mb-3"><label class="form-label" for="description">Description</label><textarea id="description" name="description" class="form-control" rows="4" maxlength="2000">{{ old('description',$project->description) }}</textarea></div>
<div class="row g-3 mb-4">
<div class="col-md-6"><label class="form-label" for="language">Language</label><select id="language" name="language" class="form-select" required><option value="php" @selected(old('language',$project->language)==='php')>PHP</option></select></div>
<div class="col-md-6"><label class="form-label" for="retention_days">Retention (days)</label><input id="retention_days" name="retention_days" type="number" min="1" max="365" value="{{ old('retention_days',$project->retention_days) }}" class="form-control" required></div>
</div>
<div class="d-flex justify-content-end gap-2"><a href="{{ route('projects.show',$project) }}" class="btn btn-outline-secondary">Cancel</a><button type="submit" class="btn btn-primary">Save changes</button></div>
</form>
</div></div></div>
@endsection
