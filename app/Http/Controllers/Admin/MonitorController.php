<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FixRun;
use App\Models\Scan;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonitorController extends Controller
{
    public function monitor(): View
    {
        $queueLength = 0;
        try {
            $queueLength = DB::table('jobs')->count();
        } catch (\Throwable) {
            $queueLength = 0;
        }

        $failedJobs = [];
        try {
            $failedJobs = DB::table('failed_jobs')->latest('failed_at')->limit(20)->get();
        } catch (\Throwable) {
            $failedJobs = collect();
        }

        $recentScans = Scan::with('upload.project', 'starter')->latest('id')->limit(10)->get();
        $recentFixes = FixRun::with('scan.upload.project', 'requester')->latest('id')->limit(10)->get();

        $stats = [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'scan_mode' => config('nexapatch.scan_mode'),
            'upload_max_mb' => config('nexapatch.upload_max_mb'),
        ];

        return view('admin.monitor', [
            'queueLength' => $queueLength,
            'failedJobs' => $failedJobs,
            'recentScans' => $recentScans,
            'recentFixes' => $recentFixes,
            'stats' => $stats,
        ]);
    }

    public function settings(): View
    {
        return view('admin.settings', [
            'uploadMaxMb' => config('nexapatch.upload_max_mb', 50),
            'scanMode' => config('nexapatch.scan_mode', 'sync'),
            'osvEnabled' => config('nexapatch.osv_enabled', true),
            'maxEntries' => config('nexapatch.upload.max_entries', 2000),
            'maxExtractedMb' => config('nexapatch.upload.max_extracted_mb', 200),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'upload_max_mb' => ['required', 'integer', 'min:1', 'max:500'],
            'scan_mode' => ['required', 'in:sync,queue'],
            'osv_enabled' => ['required', 'boolean'],
        ]);

        AuditLogger::log('admin.settings_updated', $request->user(), $data);

        return back()->with('status', 'System operational limits have been updated.');
    }
}
