<?php

declare(strict_types=1);

namespace EidCloud\LipsyncAnalyzer\Sync;

use InvalidArgumentException;

class CrossCorrelator
{
    /**
     * Computes the normalized cross-correlation between audio envelope and video motion
     * across a search lag window, finding the optimal delay in milliseconds.
     *
     * @param array<int, float> $audioEnvelope
     * @param array<int, float> $videoMotion
     * @param float $sampleRateHz (e.g. 50 Hz -> 20ms per sample)
     * @param float $maxLagSeconds Maximum lag to search (default +/- 1.0 second = 1000ms)
     * @return array{drift_ms: float, peak_correlation: float, correlations: array<int, float>}
     */
    public function correlate(
        array $audioEnvelope,
        array $videoMotion,
        float $sampleRateHz = 50.0,
        float $maxLagSeconds = 1.0
    ): array {
        $nAudio = count($audioEnvelope);
        $nVideo = count($videoMotion);

        if ($nAudio === 0 || $nVideo === 0) {
            throw new InvalidArgumentException("Audio and video envelopes must not be empty.");
        }

        $n = min($nAudio, $nVideo);
        $maxLagSamples = (int) round($maxLagSeconds * $sampleRateHz);

        // Normalize series (zero mean, unit variance)
        $a = array_slice($audioEnvelope, 0, $n);
        $v = array_slice($videoMotion, 0, $n);

        $normA = $this->standardize($a);
        $normV = $this->standardize($v);

        $bestLag = 0;
        $maxCorr = -1.0;
        $correlations = [];

        // Search lags from -$maxLagSamples to +$maxLagSamples
        // Positive lag k means: audio[i + k] aligns with video[i] (audio was delayed relative to video)
        // Negative lag k means: audio was early relative to video
        for ($lag = -$maxLagSamples; $lag <= $maxLagSamples; $lag++) {
            $sum = 0.0;
            $overlapCount = 0;

            for ($i = 0; $i < $n; $i++) {
                $j = $i + $lag;
                if ($j >= 0 && $j < $n) {
                    $sum += $normA[$j] * $normV[$i];
                    $overlapCount++;
                }
            }

            $corr = $overlapCount > 0 ? ($sum / $overlapCount) : 0.0;
            $correlations[$lag] = $corr;

            if ($corr > $maxCorr) {
                $maxCorr = $corr;
                $bestLag = $lag;
            }
        }

        // Sub-sample parabolic interpolation for sub-millisecond precision
        $refinedLag = (float) $bestLag;
        if (isset($correlations[$bestLag - 1]) && isset($correlations[$bestLag + 1])) {
            $y0 = $correlations[$bestLag - 1];
            $y1 = $correlations[$bestLag];
            $y2 = $correlations[$bestLag + 1];
            $denom = 2 * (2 * $y1 - $y0 - $y2);
            if (abs($denom) > 1e-7) {
                $delta = ($y0 - $y2) / $denom;
                // Bound delta between -0.5 and +0.5
                if ($delta >= -0.5 && $delta <= 0.5) {
                    $refinedLag += $delta;
                }
            }
        }

        // Convert lag in samples to milliseconds:
        // ms = (lag / sampleRateHz) * 1000
        $msPerSample = 1000.0 / $sampleRateHz;
        $driftMs = $refinedLag * $msPerSample;

        return [
            'drift_ms' => $driftMs,
            'peak_correlation' => max(0.0, min(1.0, $maxCorr)),
            'correlations' => $correlations,
        ];
    }

    /**
     * Standardizes an array of numbers (zero-mean, unit variance).
     *
     * @param array<int, float> $series
     * @return array<int, float>
     */
    private function standardize(array $series): array
    {
        $n = count($series);
        if ($n === 0) {
            return [];
        }

        $mean = array_sum($series) / $n;
        $variance = 0.0;
        foreach ($series as $val) {
            $diff = $val - $mean;
            $variance += $diff * $diff;
        }
        $std = sqrt($variance / $n);

        if ($std < 1e-7) {
            return array_fill(0, $n, 0.0);
        }

        $result = [];
        foreach ($series as $val) {
            $result[] = ($val - $mean) / $std;
        }

        return $result;
    }
}
