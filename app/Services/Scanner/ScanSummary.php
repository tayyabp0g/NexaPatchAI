<?php

namespace App\Services\Scanner;

use App\Models\Finding;
use App\Models\Scan;

class ScanSummary
{
    /** Recalculate counts, score and grade from the scan's open findings. */
    public static function refresh(Scan $scan): Scan
    {
        $open = $scan->findings()->where('status', 'open')
            ->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity');

        $counts = [];
        foreach (Finding::SEVERITIES as $level) {
            $counts[$level] = (int) ($open[$level] ?? 0);
        }

        $byCategory = $scan->findings()->where('status', 'open')
            ->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category')->all();

        $result = ScanScorer::score($counts);

        $scan->update([
            'score' => $result['score'],
            'grade' => $result['grade'],
            'summary' => [
                'severity' => $counts,
                'categories' => $byCategory,
                'total_open' => array_sum($counts),
                'total_all' => $scan->findings()->count(),
            ],
        ]);

        return $scan;
    }
}
