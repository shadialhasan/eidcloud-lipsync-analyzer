<?php

declare(strict_types=1);

namespace EidCloud\LipsyncAnalyzer\Sync;

class ScoreCalculator
{
    /**
     * ITU-R BT.1359-1 & ATSC IS-191 Broadcast Recommendations:
     * - Audio lead tolerance: up to -15 ms (imperceptible) to -45 ms (threshold)
     * - Audio lag tolerance: up to +45 ms (imperceptible) to +90 ms (threshold)
     * - Unacceptable drift: < -100 ms or > +150 ms
     *
     * @param float $driftMs Calculated drift in ms
     * @param float $correlationPeak Cross-correlation coefficient (0.0 to 1.0)
     * @return array{score: float, category: string}
     */
    public function calculate(float $driftMs, float $correlationPeak): array
    {
        $absDrift = abs($driftMs);

        // Score based on drift magnitude:
        // 0 ms -> 100
        // 25 ms -> 95
        // 50 ms -> 85
        // 100 ms -> 65
        // 200 ms -> 30
        // 500 ms -> 0
        $driftPenalty = min(100.0, ($absDrift / 300.0) * 100.0);
        $rawScore = max(0.0, 100.0 - $driftPenalty);

        // Correlation weight: higher confidence in peak preserves score; weak correlation dampens score
        $confidenceFactor = 0.5 + 0.5 * max(0.0, min(1.0, $correlationPeak));
        $finalScore = round($rawScore * $confidenceFactor, 2);

        // Determine category according to international broadcast standards
        if ($absDrift <= 25.0 && $finalScore >= 70.0) {
            $category = 'Perfect';
        } elseif ($absDrift <= 75.0 && $finalScore >= 50.0) {
            $category = 'Acceptable';
        } elseif ($absDrift <= 150.0) {
            $category = 'Noticeable Delay';
        } else {
            $category = 'Desynchronized';
        }

        return [
            'score' => $finalScore,
            'category' => $category,
        ];
    }
}
