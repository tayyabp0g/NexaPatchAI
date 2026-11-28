@extends('layouts.app')
@section('title', 'Audit log')
@section('content')
<h1 class="h5 mb-3">Audit log</h1>
<div class="np-card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Details</th></tr>
            </thead>
            <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="small text-nowrap">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                    <td>{{ $log->user?->name ?? 'Guest / system' }}</td>
                    <td><span class="badge text-bg-light border">{{ $log->action }}</span></td>
                    <td class="small">{{ $log->entity_type ? $log->entity_type.' #'.$log->entity_id : '-' }}</td>
                    <td class="small">{{ $log->ip_address }}</td>
                    <td>@if ($log->metadata)<code class="np-meta">{{ json_encode($log->metadata) }}</code>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="np-empty">No activity recorded yet.</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $logs->links() }}</div>
</div>
@endsection
