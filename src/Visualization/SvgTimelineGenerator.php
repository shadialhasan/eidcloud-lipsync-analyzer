<?php

declare(strict_types=1);

namespace EidCloud\LipsyncAnalyzer\Visualization;

use EidCloud\LipsyncAnalyzer\Model\SyncAnalysisResult;

class SvgTimelineGenerator
{
    /**
     * Generates a sleek, high-definition SVG timeline comparing audio energy waveform vs visual motion vectors.
     *
     * @param SyncAnalysisResult $result
     * @param int $width SVG canvas width
     * @param int $height SVG canvas height
     * @return string Valid SVG markup
     */
    public function render(SyncAnalysisResult $result, int $width = 1000, int $height = 420): string
    {
        $padLeft = 70;
        $padRight = 40;
        $padTop = 70;
        $padBottom = 60;

        $chartWidth = $width - $padLeft - $padRight;
        $chartHeight = $height - $padTop - $padBottom;

        $audio = $result->audioEnvelope;
        $video = $result->videoMotion;
        $sampleCount = max(count($audio), count($video));

        if ($sampleCount < 2) {
            $sampleCount = 2;
        }

        // Build Audio Path (Cyan/Blue #00d2ff)
        $audioPoints = [];
        $audioStep = $chartWidth / max(1, count($audio) - 1);
        foreach ($audio as $idx => $val) {
            $x = $padLeft + $idx * $audioStep;
            $y = $padTop + $chartHeight * 0.45 - ($val * $chartHeight * 0.40);
            $audioPoints[] = sprintf("%.1f,%.1f", $x, $y);
        }
        $audioPathD = "M " . implode(" L ", $audioPoints);

        // Build Video Motion Path (Magenta/Amber #ff007f)
        $videoPoints = [];
        $videoStep = $chartWidth / max(1, count($video) - 1);
        foreach ($video as $idx => $val) {
            $x = $padLeft + $idx * $videoStep;
            $y = $padTop + $chartHeight * 0.95 - ($val * $chartHeight * 0.40);
            $videoPoints[] = sprintf("%.1f,%.1f", $x, $y);
        }
        $videoPathD = "M " . implode(" L ", $videoPoints);

        // Status badge color
        $statusColor = match ($result->category) {
            'Perfect' => '#10b981', // emerald
            'Acceptable' => '#3b82f6', // blue
            'Noticeable Delay' => '#f59e0b', // amber
            default => '#ef4444', // red
        };

        $driftSign = $result->driftMs >= 0 ? '+' : '';
        $driftText = sprintf("%s%.1f ms", $driftSign, $result->driftMs);
        $scoreText = sprintf("%.1f%%", $result->score);
        $durationText = sprintf("%.2fs", $result->durationSeconds);

        // Time axis grid markers
        $timeTicks = '';
        $numTicks = 10;
        for ($i = 0; $i <= $numTicks; $i++) {
            $frac = $i / $numTicks;
            $tx = $padLeft + $frac * $chartWidth;
            $tSec = $frac * $result->durationSeconds;
            $timeTicks .= sprintf(
                '<line x1="%.1f" y1="%d" x2="%.1f" y2="%d" stroke="#334155" stroke-dasharray="3,3" />' .
                '<text x="%.1f" y="%d" font-family="monospace" font-size="10" fill="#94a3b8" text-anchor="middle">%.1fs</text>',
                $tx, $padTop, $tx, $padTop + $chartHeight,
                $tx, $padTop + $chartHeight + 20, $tSec
            );
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$width} {$height}" width="100%" height="100%">
  <defs>
    <linearGradient id="bgGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#0f172a" />
      <stop offset="100%" stop-color="#1e293b" />
    </linearGradient>
    <filter id="glowAudio" x="-20%" y="-20%" width="140%" height="140%">
      <feGaussianBlur stdDeviation="3" result="blur" />
      <feMerge>
        <feMergeNode in="blur" />
        <feMergeNode in="SourceGraphic" />
      </feMerge>
    </filter>
    <filter id="glowVideo" x="-20%" y="-20%" width="140%" height="140%">
      <feGaussianBlur stdDeviation="3" result="blur" />
      <feMerge>
        <feMergeNode in="blur" />
        <feMergeNode in="SourceGraphic" />
      </feMerge>
    </filter>
  </defs>

  <!-- Background -->
  <rect width="{$width}" height="{$height}" rx="12" fill="url(#bgGrad)" stroke="#334155" stroke-width="1.5" />

  <!-- Header -->
  <g transform="translate(30, 42)">
    <text font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="20" font-weight="700" fill="#f8fafc">
      👄 Lip-Sync Alignment &amp; Drift Timeline
    </text>
    <text x="440" y="-2" font-family="monospace" font-size="12" fill="#94a3b8">
      Duration: {$durationText} | Rate: {$result->samplingRateHz}Hz
    </text>
  </g>

  <!-- Score Badge -->
  <g transform="translate({$chartWidth}, 22)">
    <rect x="0" y="0" width="160" height="34" rx="8" fill="{$statusColor}" fill-opacity="0.15" stroke="{$statusColor}" stroke-width="1.5" />
    <text x="80" y="22" font-family="-apple-system, BlinkMacSystemFont, sans-serif" font-size="13" font-weight="700" fill="{$statusColor}" text-anchor="middle">
      {$result->category} ({$driftText})
    </text>
  </g>

  <!-- Grid lines -->
  <g id="grid">
    {$timeTicks}
    <line x1="{$padLeft}" y1="{$padTop}" x2="{$chartWidth}" y2="{$padTop}" stroke="#334155" />
    <line x1="{$padLeft}" y1="{$padTop} + {$chartHeight}/2" x2="{$chartWidth}" y2="{$padTop} + {$chartHeight}/2" stroke="#334155" stroke-dasharray="2,2" />
    <line x1="{$padLeft}" y1="{$padTop} + {$chartHeight}" x2="{$chartWidth}" y2="{$padTop} + {$chartHeight}" stroke="#334155" />
  </g>

  <!-- Track Labels -->
  <text x="20" y="{$padTop} + 45" font-family="sans-serif" font-size="12" font-weight="600" fill="#00d2ff">AUDIO</text>
  <text x="20" y="{$padTop} + 60" font-family="monospace" font-size="9" fill="#64748b">RMS Energy</text>

  <text x="20" y="{$padTop} + {$chartHeight} - 45" font-family="sans-serif" font-size="12" font-weight="600" fill="#ff007f">VIDEO</text>
  <text x="20" y="{$padTop} + {$chartHeight} - 30" font-family="monospace" font-size="9" fill="#64748b">Mouth Motion</text>

  <!-- Audio Waveform -->
  <path d="{$audioPathD}" fill="none" stroke="#00d2ff" stroke-width="2.2" filter="url(#glowAudio)" opacity="0.9" />

  <!-- Video Motion Waveform -->
  <path d="{$videoPathD}" fill="none" stroke="#ff007f" stroke-width="2.2" filter="url(#glowVideo)" opacity="0.9" />

  <!-- Legend & Footer Stats -->
  <g transform="translate({$padLeft}, {$padTop} + {$chartHeight} + 40)">
    <circle cx="0" cy="-4" r="5" fill="#00d2ff" />
    <text x="12" y="0" font-family="sans-serif" font-size="11" fill="#cbd5e1">Speech Acoustic Envelope</text>

    <circle cx="200" cy="-4" r="5" fill="#ff007f" />
    <text x="212" y="0" font-family="sans-serif" font-size="11" fill="#cbd5e1">Mouth Visual Optical Flow</text>

    <text x="{$chartWidth} - 250" y="0" font-family="monospace" font-size="11" fill="#94a3b8">
      Sync Score: <tspan fill="#f8fafc" font-weight="700">{$scoreText}</tspan> | Peak Corr: <tspan fill="#f8fafc">{$result->correlationPeak}</tspan>
    </text>
  </g>
</svg>
SVG;
    }
}
