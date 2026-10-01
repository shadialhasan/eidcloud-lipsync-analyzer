<?php

declare(strict_types=1);

namespace EidCloud\LipsyncAnalyzer;

use EidCloud\LipsyncAnalyzer\Audio\EnvelopeExtractor;
use EidCloud\LipsyncAnalyzer\Model\SyncAnalysisResult;
use EidCloud\LipsyncAnalyzer\Sync\CrossCorrelator;
use EidCloud\LipsyncAnalyzer\Sync\ScoreCalculator;
use EidCloud\LipsyncAnalyzer\Video\MotionExtractor;
use EidCloud\LipsyncAnalyzer\Visualization\SvgTimelineGenerator;
use RuntimeException;

class LipsyncAnalyzer
{
    private EnvelopeExtractor $audioExtractor;
    private MotionExtractor $videoExtractor;
    private CrossCorrelator $correlator;
    private ScoreCalculator $scorer;
    private SvgTimelineGenerator $timelineGenerator;

    public function __construct(
        ?EnvelopeExtractor $audioExtractor = null,
        ?MotionExtractor $videoExtractor = null,
        ?CrossCorrelator $correlator = null,
        ?ScoreCalculator $scorer = null,
        ?SvgTimelineGenerator $timelineGenerator = null
    ) {
        $this->audioExtractor = $audioExtractor ?? new EnvelopeExtractor();
        $this->videoExtractor = $videoExtractor ?? new MotionExtractor();
        $this->correlator = $correlator ?? new CrossCorrelator();
        $this->scorer = $scorer ?? new ScoreCalculator();
        $this->timelineGenerator = $timelineGenerator ?? new SvgTimelineGenerator();
    }

    /**
     * Performs comprehensive lip-sync quality and timing alignment analysis.
     *
     * @param string $mediaPath Path to video file (or audio/video container)
     * @param float $samplingRateHz Analysis rate in Hz (default 50.0 Hz = 20ms resolution)
     * @param float|null $maxDuration Maximum duration to analyze in seconds
     * @return SyncAnalysisResult
     */
    public function analyze(string $mediaPath, float $samplingRateHz = 50.0, ?float $maxDuration = null): SyncAnalysisResult
    {
        if (!file_exists($mediaPath)) {
            throw new RuntimeException("Target media file does not exist: {$mediaPath}");
        }

        // 1. Extract acoustic energy envelope
        $audioEnvelope = $this->audioExtractor->extractEnvelope($mediaPath, $samplingRateHz, $maxDuration);

        // 2. Extract visual mouth motion vectors
        $videoMotion = $this->videoExtractor->extractMotion($mediaPath, $samplingRateHz, $maxDuration);

        // 3. Align lengths
        $samplesCount = min(count($audioEnvelope), count($videoMotion));
        if ($samplesCount === 0) {
            throw new RuntimeException("Could not extract sufficient audio/video signals from media.");
        }

        $alignedAudio = array_slice($audioEnvelope, 0, $samplesCount);
        $alignedVideo = array_slice($videoMotion, 0, $samplesCount);
        $duration = $samplesCount / $samplingRateHz;

        // 4. Cross-correlate for drift
        $corrResult = $this->correlator->correlate($alignedAudio, $alignedVideo, $samplingRateHz);
        $driftMs = $corrResult['drift_ms'];
        $peakCorr = $corrResult['peak_correlation'];

        // 5. Score calculation and categorization
        $scoreInfo = $this->scorer->calculate($driftMs, $peakCorr);

        return new SyncAnalysisResult(
            driftMs: $driftMs,
            score: $scoreInfo['score'],
            category: $scoreInfo['category'],
            correlationPeak: $peakCorr,
            audioEnvelope: $alignedAudio,
            videoMotion: $alignedVideo,
            samplingRateHz: $samplingRateHz,
            durationSeconds: $duration,
            metadata: [
                'file' => basename($mediaPath),
                'filesize' => filesize($mediaPath),
                'samples_analyzed' => $samplesCount,
            ]
        );
    }

    /**
     * Analyzes and renders an SVG timeline directly.
     *
     * @param string $mediaPath
     * @param string|null $outputPath If null, returns SVG string
     * @param float $samplingRateHz
     * @param float|null $maxDuration
     * @return string
     */
    public function generateTimeline(
        string $mediaPath,
        ?string $outputPath = null,
        float $samplingRateHz = 50.0,
        ?float $maxDuration = null
    ): string {
        $result = $this->analyze($mediaPath, $samplingRateHz, $maxDuration);
        $svg = $this->timelineGenerator->render($result);

        if ($outputPath !== null) {
            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($outputPath, $svg);
        }

        return $svg;
    }

    public function getAudioExtractor(): EnvelopeExtractor
    {
        return $this->audioExtractor;
    }

    public function getVideoExtractor(): MotionExtractor
    {
        return $this->videoExtractor;
    }

    public function getCorrelator(): CrossCorrelator
    {
        return $this->correlator;
    }

    public function getScorer(): ScoreCalculator
    {
        return $this->scorer;
    }

    public function getTimelineGenerator(): SvgTimelineGenerator
    {
        return $this->timelineGenerator;
    }
}
