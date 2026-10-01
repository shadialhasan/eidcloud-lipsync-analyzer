<?php

declare(strict_types=1);

namespace EidCloud\LipsyncAnalyzer\Audio;

use RuntimeException;

class EnvelopeExtractor
{
    private string $ffmpegBinary;

    public function __construct(string $ffmpegBinary = 'ffmpeg')
    {
        $this->ffmpegBinary = $ffmpegBinary;
    }

    /**
     * Extracts acoustic speech energy envelope from video/audio file at specified sample rate.
     *
     * @param string $mediaPath
     * @param float $targetRateHz Envelope samples per second (default: 50.0 Hz = 20ms windows)
     * @param float|null $maxDuration Maximum seconds to process
     * @return array<int, float> Normalized envelope values between 0.0 and 1.0
     */
    public function extractEnvelope(string $mediaPath, float $targetRateHz = 50.0, ?float $maxDuration = null): array
    {
        if (!file_exists($mediaPath)) {
            throw new RuntimeException("Media file not found: {$mediaPath}");
        }

        // We decode audio to mono 16-bit PCM WAV (16000 Hz) via FFmpeg
        $pcmRate = 16000;
        $tempWav = tempnam(sys_get_temp_dir(), 'lps_aud_') . '.wav';

        $durationFlag = $maxDuration !== null ? sprintf("-t %.3f", $maxDuration) : '';
        $cmd = sprintf(
            '%s -y -v error %s -i %s -vn -acodec pcm_s16le -ac 1 -ar %d %s',
            escapeshellcmd($this->ffmpegBinary),
            $durationFlag,
            escapeshellarg($mediaPath),
            $pcmRate,
            escapeshellarg($tempWav)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($tempWav) || filesize($tempWav) < 44) {
            if (file_exists($tempWav)) {
                @unlink($tempWav);
            }
            throw new RuntimeException("Failed to decode audio track from: {$mediaPath}");
        }

        try {
            $samples = $this->readPcm16Wav($tempWav);
            return $this->computeEnergyEnvelope($samples, $pcmRate, $targetRateHz);
        } finally {
            if (file_exists($tempWav)) {
                @unlink($tempWav);
            }
        }
    }

    /**
     * Reads signed 16-bit mono PCM samples normalized between -1.0 and 1.0.
     *
     * @return array<int, float>
     */
    public function readPcm16Wav(string $wavPath): array
    {
        $fh = fopen($wavPath, 'rb');
        if (!$fh) {
            throw new RuntimeException("Cannot open WAV file: {$wavPath}");
        }

        // Read RIFF header
        $header = fread($fh, 44);
        if (strlen($header) < 44 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
            fclose($fh);
            throw new RuntimeException("Invalid WAV file format.");
        }

        // Parse chunks to find 'data'
        rewind($fh);
        $headerInfo = unpack('Nriff/Vsize/Nwave', fread($fh, 12));
        
        $dataBytes = '';
        while (!feof($fh)) {
            $chunkId = fread($fh, 4);
            if (strlen($chunkId) < 4) {
                break;
            }
            $chunkSizeData = fread($fh, 4);
            if (strlen($chunkSizeData) < 4) {
                break;
            }
            $chunkSize = unpack('Vsize', $chunkSizeData)['size'];

            if ($chunkId === 'data') {
                $dataBytes = fread($fh, $chunkSize);
                break;
            } else {
                // Skip chunk
                fseek($fh, $chunkSize, SEEK_CUR);
            }
        }
        fclose($fh);

        $numSamples = (int) (strlen($dataBytes) / 2);
        if ($numSamples === 0) {
            return [];
        }

        $rawInt16 = unpack('s' . $numSamples, $dataBytes);
        $samples = [];
        foreach ($rawInt16 as $val) {
            $samples[] = $val / 32768.0;
        }

        return $samples;
    }

    /**
     * Computes smoothed speech energy envelope with onset emphasis and root-mean-square (RMS).
     *
     * @param array<int, float> $samples
     * @return array<int, float>
     */
    public function computeEnergyEnvelope(array $samples, int $sampleRate, float $targetRateHz): array
    {
        $totalSamples = count($samples);
        if ($totalSamples === 0) {
            return [];
        }

        $windowSize = (int) round($sampleRate / $targetRateHz);
        if ($windowSize < 1) {
            $windowSize = 1;
        }

        $numWindows = (int) ceil($totalSamples / $windowSize);
        $envelope = [];

        // Apply a pre-emphasis filter to boost speech consonants/formants (y[n] = x[n] - 0.95 * x[n-1])
        $filtered = [];
        $prev = 0.0;
        for ($i = 0; $i < $totalSamples; $i++) {
            $val = $samples[$i];
            $filtered[] = $val - 0.95 * $prev;
            $prev = $val;
        }

        for ($w = 0; $w < $numWindows; $w++) {
            $start = $w * $windowSize;
            $end = min($start + $windowSize, $totalSamples);
            $sumSq = 0.0;
            $count = $end - $start;

            for ($i = $start; $i < $end; $i++) {
                $sumSq += $filtered[$i] * $filtered[$i];
            }

            $rms = $count > 0 ? sqrt($sumSq / $count) : 0.0;
            $envelope[] = $rms;
        }

        // Smoothing: 3-point moving average
        $smoothed = [];
        $envCount = count($envelope);
        for ($i = 0; $i < $envCount; $i++) {
            $w0 = $envelope[max(0, $i - 1)];
            $w1 = $envelope[$i];
            $w2 = $envelope[min($envCount - 1, $i + 1)];
            $smoothed[] = 0.25 * $w0 + 0.5 * $w1 + 0.25 * $w2;
        }

        // Normalize between 0.0 and 1.0
        $maxVal = 0.0;
        foreach ($smoothed as $val) {
            if ($val > $maxVal) {
                $maxVal = $val;
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
