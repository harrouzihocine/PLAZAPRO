<?php

declare(strict_types=1);

namespace App\Core\Media;

use Symfony\Component\Process\Process;

/**
 * Normalise videos with ffmpeg into web-streamable MP4s:
 *
 *  - H.264 High + AAC — the one codec pair every browser/phone plays.
 *  - CRF (constant-quality) encode, longest edge capped (1080p-class by
 *    default): a raw 4K phone clip (~100 MB/min) comes out ~8–12× smaller with
 *    no visible loss at viewing sizes.
 *  - `+faststart` moves the moov atom to the front so playback and seeking
 *    start instantly over the permission-gated streaming endpoint.
 *  - Metadata stripped (`-map_metadata -1`): no GPS/device leakage.
 *
 * Sources that are already efficient H.264 MP4s skip the re-encode and are
 * only remuxed (faststart + metadata strip) — zero generation loss.
 */
class VideoOptimizer
{
    /** A long 4K clip can legitimately take many minutes to encode. */
    public int $timeout = 3300;

    /**
     * ffprobe facts about the container + first video stream.
     *
     * @return array{
     *     codec: ?string, audio_codec: ?string, width: int, height: int,
     *     duration: float, bit_rate: int, format: string
     * }
     */
    public function probe(string $source): array
    {
        $process = new Process([
            (string) config('media.optimize.bins.ffprobe', 'ffprobe'),
            '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $source,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ffprobe failed: '.trim($process->getErrorOutput()));
        }

        $data = json_decode($process->getOutput(), true) ?: [];
        $video = collect($data['streams'] ?? [])->firstWhere('codec_type', 'video') ?? [];
        $audio = collect($data['streams'] ?? [])->firstWhere('codec_type', 'audio') ?? [];

        return [
            'codec' => $video['codec_name'] ?? null,
            'audio_codec' => $audio['codec_name'] ?? null,
            'width' => (int) ($video['width'] ?? 0),
            'height' => (int) ($video['height'] ?? 0),
            'duration' => (float) ($data['format']['duration'] ?? 0),
            'bit_rate' => (int) ($data['format']['bit_rate'] ?? 0),
            'format' => (string) ($data['format']['format_name'] ?? ''),
        ];
    }

    /**
     * Whether the source is already efficient enough to remux instead of
     * re-encode: H.264 + AAC (or silent) in an MP4/MOV container, within the
     * size cap, at or below the bitrate threshold.
     */
    public function canRemux(array $probe, int $maxEdge, int $bitrateThreshold): bool
    {
        return $probe['codec'] === 'h264'
            && in_array($probe['audio_codec'], ['aac', null], true)
            && max($probe['width'], $probe['height']) <= $maxEdge
            && $probe['bit_rate'] > 0
            && $probe['bit_rate'] <= $bitrateThreshold
            && str_contains($probe['format'], 'mp4');
    }

    /** Full re-encode to a capped, faststart H.264/AAC MP4. */
    public function transcode(string $source, string $dest, int $maxEdge, int $crf, string $preset, string $audioBitrate): void
    {
        // min(iw/ih) + force_original_aspect_ratio=decrease caps the longest
        // edge without ever upscaling; force_divisible_by satisfies yuv420p.
        $scale = sprintf(
            "scale='min(iw,%d)':'min(ih,%d)':force_original_aspect_ratio=decrease:force_divisible_by=2",
            $maxEdge, $maxEdge,
        );

        $this->runFfmpeg([
            '-i', $source,
            '-vf', $scale,
            '-c:v', 'libx264', '-crf', (string) $crf, '-preset', $preset,
            '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', $audioBitrate,
            '-movflags', '+faststart',
            '-map_metadata', '-1',
            $dest,
        ], $this->timeout);
    }

    /** Copy streams into a clean faststart MP4 (no quality change). */
    public function remux(string $source, string $dest): void
    {
        $this->runFfmpeg([
            '-i', $source,
            '-c', 'copy',
            '-movflags', '+faststart',
            '-map_metadata', '-1',
            $dest,
        ], 600);
    }

    /**
     * Extract one poster frame as a PNG (`$dest`) — re-encoded to WebP by
     * ImageOptimizer afterwards so all derivatives share one image path.
     */
    public function posterFrame(string $source, string $dest, float $atSeconds): void
    {
        $this->runFfmpeg([
            '-ss', number_format(max(0, $atSeconds), 2, '.', ''),
            '-i', $source,
            '-frames:v', '1',
            $dest,
        ], 120);
    }

    /** @param list<string> $args */
    private function runFfmpeg(array $args, int $timeout): void
    {
        $process = new Process([
            (string) config('media.optimize.bins.ffmpeg', 'ffmpeg'),
            '-y', '-hide_banner', '-loglevel', 'error', '-nostdin',
            ...$args,
        ]);
        $process->setTimeout($timeout);
        $process->run();

        $dest = end($args);
        if (! $process->isSuccessful() || ! is_file($dest) || filesize($dest) === 0) {
            throw new \RuntimeException('ffmpeg failed: '.trim($process->getErrorOutput()));
        }
    }
}
