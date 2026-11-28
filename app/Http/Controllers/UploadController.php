<?php

namespace App\Http\Controllers;

use App\Exceptions\UploadRejected;
use App\Models\Project;
use App\Models\Upload;
use App\Services\AuditLogger;
use App\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UploadController extends Controller
{
    public function __construct(private UploadService $uploads)
    {
    }

    public function store(Request $request, Project $project): RedirectResponse|JsonResponse
    {
        Gate::authorize('manageUploads', $project);

        $maxMb = (int) config('nexapatch.upload_max_mb');
        $request->validate(
            ['file' => ['required', 'file', 'max:'.($maxMb * 1024)]],
            [
                'file.required' => 'Please choose a file to upload.',
                'file.uploaded' => 'The upload failed. The file may be larger than the PHP limit (upload_max_filesize). See CHANGES.md.',
                'file.max' => "The file is larger than {$maxMb} MB.",
            ]
        );

        try {
            $upload = $this->uploads->store($project, $request->file('file'), $request->user());
        } catch (UploadRejected $e) {
            AuditLogger::log('upload.rejected', $project, [
                'file' => Str::limit(basename((string) $request->file('file')->getClientOriginalName()), 120),
                'reason' => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
            }

            return back()->withErrors(['file' => $e->getMessage()]);
        }

        AuditLogger::log('upload.created', $upload, [
            'project_id' => $project->id,
            'file' => $upload->original_name,
            'files' => $upload->file_count,
            'bytes' => $upload->size_bytes,
        ]);

        $message = $upload->is_archive
            ? "ZIP uploaded: {$upload->file_count} source file(s) stored"
                .($upload->skipped_count ? ", {$upload->skipped_count} skipped (not source code)." : '.')
            : 'File uploaded.';

        $request->session()->flash('status', $message);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'redirect' => route('projects.show', $project)]);
        }

        return redirect()->route('projects.show', $project);
    }

    public function show(Project $project, Upload $upload): View
    {
        Gate::authorize('view', $project);
        $this->ensureBelongs($project, $upload);

        return view('uploads.show', [
            'project' => $project,
            'upload' => $upload->load('uploader'),
            'listing' => $this->uploads->listFiles($upload),
            'intact' => $this->uploads->verifyIntegrity($upload),
        ]);
    }

    public function download(Project $project, Upload $upload): BinaryFileResponse
    {
        Gate::authorize('view', $project);
        $this->ensureBelongs($project, $upload);

        $path = $this->uploads->originalPath($upload);
        abort_if($path === null, 404);

        AuditLogger::log('upload.downloaded', $upload);

        return response()->download($path, $upload->original_name);
    }

    public function destroy(Project $project, Upload $upload): RedirectResponse
    {
        Gate::authorize('manageUploads', $project);
        $this->ensureBelongs($project, $upload);

        AuditLogger::log('upload.deleted', $upload, ['file' => $upload->original_name]);
        $this->uploads->delete($upload);

        return redirect()->route('projects.show', $project)->with('status', 'Upload deleted.');
    }

    /** Stops someone using their own project id with another project's upload id. */
    private function ensureBelongs(Project $project, Upload $upload): void
    {
        abort_unless($upload->project_id === $project->id, 404);
    }
}
