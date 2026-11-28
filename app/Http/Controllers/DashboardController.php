<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Finding;
use App\Models\FixRun;
use App\Models\Project;
use App\Models\Scan;
use App\Models\Upload;
use App\Services\Scanner\ScanScorer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $visible = fn ($q) => $q->visibleTo($user);

        // Latest completed scan of every upload: the "current" security picture
        $completed = Scan::with('upload.project')
            ->whereHas('upload.project', $visible)
            ->where('status', 'completed')
            ->latest('id')
            ->limit(300)
            ->get();
        $latest = $completed->unique('upload_id');
        $latestIds = $latest->pluck('id')->all();

        $severityCounts = array_fill_keys(Finding::SEVERITIES, 0);
        $rows = Finding::whereIn('scan_id', $latestIds)->where('status', 'open')
            ->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity');
        foreach ($rows as $level => $total) {
            if (isset($severityCounts[$level])) {
                $severityCounts[$level] = (int) $total;
            }
        }

        $categories = Finding::whereIn('scan_id', $latestIds)->where('status', 'open')->whereNotNull('category')
            ->selectRaw('category, count(*) as total')->groupBy('category')->orderByDesc('total')->limit(6)->pluck('total', 'category');

        $perProject = $latest->unique(fn ($s) => $s->upload->project_id);
        $avgScore = $perProject->isEmpty() ? null : (int) round($perProject->avg('score'));

        $pending = Scan::whereHas('upload.project', $visible)->whereIn('status', Scan::ACTIVE)->count();

        $pendingFixes = FixRun::with('scan.upload.project', 'requester')
            ->whereHas('scan.upload.project', $visible)
            ->where('status', 'ready')
            ->latest('id')
            ->limit(5)
            ->get();

        return view('dashboard', [
            'projectCount' => Project::visibleTo($user)->count(),
            'completedScans' => $completed->count(),
            'pendingScans' => $pending,
            'pendingFixes' => $pendingFixes,
            'severityCounts' => $severityCounts,
            'openFindings' => array_sum($severityCounts),
            'avgScore' => $avgScore,
            'avgGrade' => $avgScore === null ? null : ScanScorer::grade($avgScore),
            'categories' => $categories,
            'attention' => Finding::with('scan.upload.project')->whereIn('scan_id', $latestIds)->where('status', 'open')
                ->whereIn('severity', ['critical', 'high'])->bySeverity()->limit(6)->get(),
            'recentScans' => Scan::with('upload.project')->whereHas('upload.project', $visible)->latest('id')->limit(6)->get(),
            'recentProjects' => Project::visibleTo($user)->with('owner')->latest()->take(5)->get(),
            'activity' => AuditLog::with('user')->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))->latest('id')->limit(8)->get(),
            'hasUploads' => Upload::whereHas('project', $visible)->exists(),
            'hasScans' => $completed->isNotEmpty(),
        ]);
    }
}
