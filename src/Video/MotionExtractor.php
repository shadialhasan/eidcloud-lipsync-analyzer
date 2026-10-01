<?php

declare(strict_types=1);

namespace EidCloud\LipsyncAnalyzer\Video;

use RuntimeException;

class MotionExtractor
{
    private string $ffmpegBinary;

    public function __construct(string $ffmpegBinary = 'ffmpeg')
    {
        $this->ffmpegBinary = $ffmpegBinary;
    }

    /**
     * Extracts visual motion / mouth activity envelope from video file.
     * Uses FFmpeg raw video frame extraction at the target sampling rate (e.g. 50 fps or 25 fps scaled),
     * focusing on lower half / center where mouth articulation and speech gestures occur.
     *
     * @param string $videoPath
     * @param float $targetRateHz Target frames/samples per second (default: 50.0)
     * @param float|null $maxDuration Maximum duration in seconds to process
     * @return array<int, float> Normalized visual motion vector envelope (0.0 to 1.0)
     */
    public function extractMotion(string $videoPath, float $targetRateHz = 50.0, ?float $maxDuration = null): array
    {
        if (!file_exists($videoPath)) {
            throw new RuntimeException("Video file not found: {$videoPath}");
        }

        // We resize to a compact grayscale stream: 64x64 or 48x48 pixels at $targetRateHz fps
        // 64x64 grayscale is 4096 bytes per frame, highly performant to read in pure PHP.
        $width = 48;
        $height = 48;
        $frameSize = $width * $height;

        $durationFlag = $maxDuration !== null ? sprintf("-t %.3f", $maxDuration) : '';
        // FFmpeg filter: scale to 48x48, convert to gray8, set exact fps
        $vf = sprintf("fps=fps=%.2f,scale=%d:%d,format=gray", $targetRateHz, $width, $height);

        $cmd = sprintf(
            '%s -y -v error %s -i %s -an -vf %s -f rawvideo -pix_fmt gray -',
            escapeshellcmd($this->ffmpegBinary),
            $durationFlag,
            escapeshellarg($videoPath),
            escapeshellarg($vf)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'], // stdout (raw frames)
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Failed to spawn FFmpeg process for video extraction.");
        }

        fclose($pipes[0]);

        $motionSeries = [];
        $prevFrame = null;

        // Lower-half mouth region bounds (Y from height/2 to height)
        $mouthYStart = (int) ($height * 0.45);
        $mouthYEnd = (int) ($height * 0.90);
        $mouthXStart = (int) ($width * 0.20);
        $mouthXEnd = (int) ($width * 0.80);

        while (!feof($pipes[1])) {
            $frameData = fread($pipes[1], $frameSize);
            if (strlen($frameData) < $frameSize) {
                break;
            }

            if ($prevFrame === null) {
                // First frame has 0 relative motion
                $motionSeries[] = 0.0;
                $prevFrame = $frameData;
                continue;
            }

            // Compute frame difference (optical flow / mouth motion proxy)
            $diffSum = 0.0;
            $pixelCount = 0;

            for ($y = $mouthYStart; $y < $mouthYEnd; $y++) {
                $rowOffset = $y * $width;
                for ($x = $mouthXStart; $x < $mouthXEnd; $x++) {
                    $idx = $rowOffset + $x;
                    $diff = ord($frameData[$idx]) - ord($prevFrame[$idx]);
                    $diffSum += abs($diff);
                    $pixelCount++;
                }
            }

            $motionSeries[] = $pixelCount > 0 ? ($diffSum / $pixelCount) : 0.0;
            $prevFrame = $frameData;
        }

        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        if (empty($motionSeries)) {
            throw new RuntimeException("No video frames extracted or decoded. Error: " . trim($stderr));
        }

        // Apply smoothing and normalization
        return $this->smoothAndNormalize($motionSeries);
    }

    /**
     * Smoothes raw motion vectors and normalizes to [0.0, 1.0].
     *
     * @param array<int, float> $rawMotion
     * @return array<int, float>
     */
    public function smoothAndNormalize(array $rawMotion): array
    {
        $count = count($rawMotion);
        if ($count === 0) {
            return [];
        }

        // Moving average filter
        $smoothed = [];
        for ($i = 0; $i < $count; $i++) {
            $w0 = $rawMotion[max(0, $i - 2)];
            $w1 = $rawMotion[max(0, $i - 1)];
            $w2 = $rawMotion[$i];
            $w3 = $rawMotion[min($count - 1, $i + 1)];
            $w4 = $rawMotion[min($count - 1, $i + 2)];
            $smoothed[] = (0.1 * $w0 + 0.2 * $w1 + 0.4 * $w2 + 0.2 * $w3 + 0.1 * $w4);
        }

        $maxVal = 0.0;
        foreach ($smoothed as $v) {
            if ($v > $maxVal) {
                $maxVal = $v;
            }
        }

        if ($maxVal > 1e-6) {
            foreach ($smoothed as &$v) {
                $v = $v / $maxVal;
            }
        }

        return $smoothed;
    }
}
