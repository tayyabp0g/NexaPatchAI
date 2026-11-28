<?php

namespace App\Http\Controllers;

use App\Models\Finding;
use App\Services\AuditLogger;
use App\Services\ReportService;
use App\Services\Scanner\ScanSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FindingController extends Controller
{
    public function show(Finding $finding): View
    {
        $finding->load('scan.upload.project');
        $project = $finding->scan->upload->project;
        Gate::authorize('view', $project);

        return view('findings.show', [
            'finding' => $finding,
            'scan' => $finding->scan,
            'project' => $project,
            'canManage' => request()->user()->can('manageUploads', $project),
        ]);
    }

    public function updateStatus(Request $request, Finding $finding, ReportService $reports): RedirectResponse
    {
        $finding->load('scan.upload.project');
        Gate::authorize('manageUploads', $finding->scan->upload->project);

        $data = $request->validate(['status' => ['required', Rule::in(Finding::USER_STATUSES)]]);

        $old = $finding->status;
        $finding->update(['status' => $data['status']]);
        ScanSummary::refresh($finding->scan);
        $reports->invalidate($finding->scan);

        AuditLogger::log('finding.status_changed', $finding, ['from' => $old, 'to' => $data['status'], 'rule' => $finding->rule_id]);

        return back()->with('status', 'Finding marked as '.str_replace('_', ' ', $data['status']).'.');
    }
}
