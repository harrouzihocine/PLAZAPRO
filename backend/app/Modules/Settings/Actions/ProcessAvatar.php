<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Normalise an uploaded profile photo into a small, web-friendly avatar and
 * store it on the private `media` disk. Whatever the user sends (a 12 MP phone
 * photo, a PNG with transparency, a sideways JPEG) comes out as a square,
 * centre-cropped, EXIF-rotated JPEG capped at {@see self::SIZE}px — a few KB,
 * so it loads instantly everywhere it is shown.
 *
 * Uses GD (always available in this image); no extra dependency.
 */
class ProcessAvatar
{
    /** Final avatar dimensions (square), in pixels. */
    private const SIZE = 400;

    /** JPEG quality — high enough to stay crisp, low enough to stay small. */
    private const QUALITY = 82;

    public function handle(UploadedFile $file): string
    {
        $raw = file_get_contents($file->getRealPath());
        $source = $raw !== false ? @imagecreatefromstring($raw) : false;

        if ($source === false) {
            throw ValidationException::withMessages([
                'avatar' => [__('That image could not be read. Please upload a valid JPEG or PNG.')],
            ]);
        }

        $source = $this->applyExifOrientation($source, $file->getRealPath());

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $srcX = (int) (($width - $side) / 2);
        $srcY = (int) (($height - $side) / 2);

        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        // JPEG has no alpha — flatten any transparency onto white.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $bytes = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        $path = 'avatars/'.Str::uuid()->toString().'.jpg';
        Storage::disk('media')->put($path, $bytes);

        return $path;
    }

    /** Rotate JPEGs shot on a phone so they display upright. No-op for other formats. */
    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = $exif['Orientation'] ?? null;

        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }
}
