<?php

namespace App\Http\Controllers;

use App\Jobs\RunFix;
use App\Models\FixRun;
use App\Models\Scan;
use App\Services\Fixer\FixEngine;
use App\Services\UploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FixController extends Controller
{
    public function store(Request $request, Scan $scan): RedirectResponse
    {
        $scan->loadMissing('upload.project');
        Gate::authorize('manageUploads', $scan->upload->project);

        $useAi = (bool) $request->input('use_ai', false);

        $run = FixRun::create([
            'scan_id' => $scan->id,
            'requested_by' => auth()->id(),
            'status' => 'queued',
            'use_ai' => $useAi,
        ]);

        if (config('nexapatch.scan_mode') === 'queue') {
            RunFix::dispatch($run->id);
        } else {
            RunFix::dispatchSync($run->id);
        }

        return redirect()->route('fixes.show', $run);
    }

    public function show(FixRun $fix): View
    {
        $fix->loadMissing([
            'scan.upload.project',
            'patches.finding',
            'patches.verificationResults',
            'patchedUpload',
            'afterScan',
            'requester',
        ]);

        Gate::authorize('view', $fix->scan->upload->project);

        return view('fixes.show', [
            'fixRun' => $fix,
            'project' => $fix->scan->upload->project,
            'scan' => $fix->scan,
            'patches' => $fix->patches,
            'summary' => $fix->summary ?? [],
        ]);
    }

    public function build(Request $request, FixRun $fix, FixEngine $engine): RedirectResponse
    {
        $fix->loadMissing('scan.upload.project');
        Gate::authorize('manageUploads', $fix->scan->upload->project);

        if ($fix->status !== 'ready') {
            return redirect()->route('fixes.show', $fix)
                ->withErrors(['fix' => 'This repair run has already been processed. Start a new repair run to try again.']);
        }

        $validated = $request->validate([
            'patches' => ['required', 'array', 'min:1'],
            'patches.*' => ['required', 'integer', 'distinct'],
        ]);
        $approvedIds = array_map('intval', $validated['patches']);
        $verifiedCount = $fix->patches()
            ->whereIn('id', $approvedIds)
            ->where('status', 'verified')
            ->count();

        if ($verifiedCount !== count($approvedIds)) {
            return redirect()->route('fixes.show', $fix)
                ->withErrors(['patches' => 'Select only verified patches from this repair run.']);
        }

        $result = $engine->build($fix, $approvedIds, $request->user());

        if ($result->status !== 'completed') {
            return redirect()->route('fixes.show', $fix)
                ->withErrors(['fix' => $result->error_message ?? 'No patches were applied. Start a new repair run.']);
        }

        return redirect()->route('fixes.show', $fix)
            ->with('success', "{$result->applied_count} approved patch(es) have been applied and the repaired ZIP has been built.");
    }

    public function download(FixRun $fix): StreamedResponse
    {
        $fix->loadMissing('scan.upload.project');
        Gate::authorize('view', $fix->scan->upload->project);

        if ($fix->applied_count < 1 || ! $fix->zip_path || ! Storage::disk(UploadService::DISK)->exists($fix->zip_path)) {
            abort(404, 'Repaired ZIP archive is not yet available.');
        }

        $filename = 'nexapatch-fixed-'.$fix->id.'.zip';

        return Storage::disk(UploadService::DISK)->download($fix->zip_path, $filename);
    }
}
