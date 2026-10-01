<?php

declare(strict_types=1);

namespace EidCloud\LipsyncAnalyzer\Model;

final class SyncAnalysisResult
{
    /**
     * @param float $driftMs Milliseconds drift (negative = audio leads video, positive = audio lags video)
     * @param float $score Lip-sync score (0.0 to 100.0)
     * @param string $category Perfect, Acceptable, Noticeable Delay, Desynchronized
     * @param float $correlationPeak Cross-correlation maximum coefficient (-1.0 to 1.0)
     * @param array<int, float> $audioEnvelope Normalized audio envelope (0.0 to 1.0)
     * @param array<int, float> $videoMotion Normalized visual motion envelope (0.0 to 1.0)
     * @param float $samplingRateHz Envelope sample rate (e.g. 50Hz or 100Hz)
     * @param float $durationSeconds Total analyzed duration
     * @param array<string, mixed> $metadata Additional audio/video metadata
     */
    public function __construct(
        public readonly float $driftMs,
        public readonly float $score,
        public readonly string $category,
        public readonly float $correlationPeak,
        public readonly array $audioEnvelope,
        public readonly array $videoMotion,
        public readonly float $samplingRateHz,
        public readonly float $durationSeconds,
        public readonly array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'drift_ms' => round($this->driftMs, 2),
            'drift_seconds' => round($this->driftMs / 1000.0, 4),
            'sync_score' => round($this->score, 2),
            'category' => $this->category,
            'correlation_peak' => round($this->correlationPeak, 4),
            'sample_rate_hz' => $this->samplingRateHz,
            'duration_seconds' => round($this->durationSeconds, 2),
            'envelope_samples' => count($this->audioEnvelope),
            'metadata' => $this->metadata,
        ];
    }

    public function toJson(bool $pretty = true): string
    {
        return json_encode($this->toArray(), $pretty ? (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : JSON_UNESCAPED_SLASHES);
    }
}
