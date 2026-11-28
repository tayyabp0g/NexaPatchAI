<?php

namespace App\Http\Controllers;

use App\Jobs\RunScan;
use App\Models\Finding;
use App\Models\Project;
use App\Models\Scan;
use App\Models\Upload;
use App\Services\AuditLogger;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ScanController extends Controller
{
    public function store(Request $request, Project $project, Upload $upload): RedirectResponse
    {
        Gate::authorize('manageUploads', $project);
        abort_unless($upload->project_id === $project->id, 404);

        if ($upload->scans()->whereIn('status', Scan::ACTIVE)->exists()) {
            return back()->withErrors(['scan' => 'A scan is already running for this upload.']);
        }

        $scan = Scan::create([
            'upload_id' => $upload->id,
            'started_by' => $request->user()->id,
            'status' => 'queued',
            'engines_used' => ['static'],
        ]);
        AuditLogger::log('scan.started', $scan, ['upload_id' => $upload->id, 'file' => $upload->original_name]);

        if (config('nexapatch.scan_mode') === 'queue') {
            RunScan::dispatch($scan->id);
            $message = 'Scan queued. Make sure "php artisan queue:work" is running.';
        } else {
            RunScan::dispatchSync($scan->id);
            $message = 'Scan finished.';
        }

        return redirect()->route('scans.show', $scan)->with('status', $message);
    }

    public function show(Request $request, Scan $scan): View
    {
        $scan->load('upload.project', 'starter');
        $project = $scan->upload->project;
        Gate::authorize('view', $project);

        $severities = array_values(array_intersect((array) $request->query('severity', []), Finding::SEVERITIES));
        $status = $request->query('status', 'open');
        $search = trim((string) $request->query('q', ''));
        $category = $request->query('category');

        $query = $scan->findings()->bySeverity();
        if ($status !== 'all' && in_array($status, ['open', 'ignored', 'false_positive'], true)) {
            $query->where('status', $status);
        }
        if ($severities) {
            $query->whereIn('severity', $severities);
        }
        if ($category) {
            $query->where('category', $category);
        }
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(fn ($q) => $q->where('file_path', 'like', $like)->orWhere('title', 'like', $like)->orWhere('rule_id', 'like', $like));
        }

        $summary = $scan->summary ?? ['severity' => [], 'categories' => [], 'total_open' => 0];

        return view('scans.show', [
            'scan' => $scan,
            'project' => $project,
            'findings' => $query->paginate(25)->withQueryString(),
            'summary' => $summary,
            'filters' => ['severity' => $severities, 'status' => $status, 'q' => $search, 'category' => $category],
            'canManage' => $request->user()->can('manageUploads', $project),
            'pdfReady' => app(ReportService::class)->available(),
        ]);
    }

    public function status(Scan $scan): JsonResponse
    {
        Gate::authorize('view', $scan->upload->project);

        return response()->json([
            'status' => $scan->status,
            'finished' => ! $scan->isActive(),
            'score' => $scan->score,
            'grade' => $scan->grade,
        ]);
    }

    public function report(Request $request, Scan $scan, ReportService $reports): BinaryFileResponse|RedirectResponse
    {
        $scan->load('upload.project');
        Gate::authorize('view', $scan->upload->project);
        abort_unless($scan->status === 'completed', 404);

        if (! $reports->available()) {
            return back()->withErrors(['report' => 'The PDF library is not installed yet. Run in the project folder: php composer.phar require dompdf/dompdf']);
        }

        $path = $reports->ensure($scan, $request->boolean('refresh'));
        AuditLogger::log('report.downloaded', $scan);

        $name = 'NexaPatch-Report-'.Str::slug($scan->upload->project->name).'-'.$scan->created_at->format('Ymd').'.pdf';

        return response()->download($path, $name, ['Content-Type' => 'application/pdf']);
    }

    public function compare(Scan $scan, Scan $otherScan): View
    {
        $scan->loadMissing('upload.project', 'findings');
        $otherScan->loadMissing('upload.project', 'findings');

        Gate::authorize('view', $scan->upload->project);
        Gate::authorize('view', $otherScan->upload->project);

        $beforeFindings = $scan->findings;
        $afterFindings = $otherScan->findings;

        $beforeKeys = $beforeFindings->keyBy(fn ($f) => $f->fingerprint ?: "{$f->file_path}:{$f->line_start}:{$f->rule_id}");
        $afterKeys = $afterFindings->keyBy(fn ($f) => $f->fingerprint ?: "{$f->file_path}:{$f->line_start}:{$f->rule_id}");

        $resolved = $beforeKeys->diffKeys($afterKeys)->values();
        $introduced = $afterKeys->diffKeys($beforeKeys)->values();
        $persisting = $beforeKeys->intersectByKeys($afterKeys)->values();

        return view('scans.compare', [
            'scan' => $scan,
            'otherScan' => $otherScan,
            'project' => $scan->upload->project,
            'resolved' => $resolved,
            'introduced' => $introduced,
            'persisting' => $persisting,
            'scoreDiff' => ($otherScan->score ?? 0) - ($scan->score ?? 0),
        ]);
    }
}
