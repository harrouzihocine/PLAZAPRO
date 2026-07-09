<?php

declare(strict_types=1);

namespace App\Core\Media;

use Symfony\Component\Process\Process;

/**
 * Re-encode images to WebP with libvips (`vipsthumbnail`) — the same engine
 * behind the big image CDNs (Cloudflare Images, imgix run libvips). Fast,
 * constant-memory (streams tiles, never decodes the full bitmap), auto-rotates
 * from EXIF and strips all metadata (EXIF/GPS/ICC bloat) from the output.
 *
 * `maxEdge` caps the LONGEST edge; the `>` size suffix means "only ever
 * shrink" — a smaller source keeps its pixels (no destructive upscale).
 */
class ImageOptimizer
{
    public int $timeout = 120;

    /**
     * Encode `$source` into a WebP at `$dest` (both absolute local paths).
     *
     * @return array{width: int, height: int, bytes: int} final output facts
     */
    public function toWebp(string $source, string $dest, int $maxEdge, int $quality): array
    {
        $process = new Process([
            (string) config('media.optimize.bins.vipsthumbnail', 'vipsthumbnail'),
            $source,
            '--size', sprintf('%dx%d>', $maxEdge, $maxEdge),
            '-o', sprintf('%s[Q=%d,strip]', $dest, $quality),
        ]);
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($dest)) {
            throw new \RuntimeException('vipsthumbnail failed: '.trim($process->getErrorOutput()));
        }

        [$width, $height] = $this->dimensions($dest);

        return ['width' => $width, 'height' => $height, 'bytes' => (int) filesize($dest)];
    }

    /**
     * Pixel dimensions of a local image file (0×0 when unreadable).
     *
     * @return array{0: int, 1: int}
     */
    public function dimensions(string $path): array
    {
        $size = @getimagesize($path);

        return $size === false ? [0, 0] : [(int) $size[0], (int) $size[1]];
    }
}
