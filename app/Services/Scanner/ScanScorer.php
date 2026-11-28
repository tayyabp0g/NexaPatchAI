<?php

namespace App\Services\Scanner;

class ScanScorer
{
    private const PENALTY = ['critical' => 20, 'high' => 10, 'medium' => 4, 'low' => 1, 'info' => 0];

    /**
     * @param array<string,int> $counts open findings per severity
     * @return array{score:int, grade:string}
     */
    public static function score(array $counts): array
    {
        $penalty = 0;
        foreach (self::PENALTY as $level => $weight) {
            $count = max(0, (int) ($counts[$level] ?? 0));
            if ($count === 0) {
                continue;
            }

            // The first findings are fully weighted; repeated findings of the
            // same severity have diminishing penalty so the score can remain
            // meaningful for large projects instead of saturating at zero.
            $first = min($count, 10);
            $repeat = max(0, $count - $first);
            $penalty += ($weight * $first) + ($weight * $repeat * 0.25);
        }
        $score = max(0, (int) round(100 - $penalty));

        return ['score' => $score, 'grade' => self::grade($score)];
    }

    public static function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };
    }
}
