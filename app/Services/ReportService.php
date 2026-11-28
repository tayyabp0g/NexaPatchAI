<?php

namespace App\Services;

use App\Models\Finding;
use App\Models\Report;
use App\Models\Scan;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Builds the PDF security assessment report (FR-049..FR-050).
 * Needs the dompdf library: php composer.phar require dompdf/dompdf
 */
class ReportService
{
    public const MAX_DETAILED = 150;

    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /** Returns the absolute path of the PDF, creating it when needed. */
    public function ensure(Scan $scan, bool $refresh = false): string
    {
        $rel = "reports/scan-{$scan->id}.pdf";
        $disk = Storage::disk(UploadService::DISK);

        $existing = Report::where('scan_id', $scan->id)->first();
        if (! $refresh && $existing && $disk->exists($existing->file_path)) {
            return $disk->path($existing->file_path);
        }

        $disk->put($rel, $this->render($scan));
        Report::updateOrCreate(['scan_id' => $scan->id], ['file_path' => $rel, 'generated_at' => now()]);

        return $disk->path($rel);
    }

    /** Forget the stored PDF (findings changed). */
    public function invalidate(Scan $scan): void
    {
        $report = Report::where('scan_id', $scan->id)->first();
        if ($report) {
            Storage::disk(UploadService::DISK)->delete($report->file_path);
            $report->delete();
        }
    }

    public function render(Scan $scan): string
    {
        if (! $this->available()) {
            throw new RuntimeException('The PDF library is not installed. Run: php composer.phar require dompdf/dompdf');
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(0);

        $scan->loadMissing('upload.project', 'starter');
        $all = $scan->findings()->bySeverity()->get();
        $open = $all->where('status', 'open')->values();
        $closed = $all->where('status', '!=', 'open')->values();

        $counts = [];
        foreach (Finding::SEVERITIES as $level) {
            $counts[$level] = $open->where('severity', $level)->count();
        }
        $categories = $open->groupBy('category')->map->count()->sortDesc();

        $html = view('reports.scan', [
            'scan' => $scan,
            'project' => $scan->upload->project,
            'upload' => $scan->upload,
            'counts' => $counts,
            'categories' => $categories,
            'open' => $open,
            'detailed' => $open->take(self::MAX_DETAILED),
            'omitted' => max(0, $open->count() - self::MAX_DETAILED),
            'closed' => $closed,
            'generatedAt' => now(),
        ])->render();

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
