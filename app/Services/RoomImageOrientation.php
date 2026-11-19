<?php

namespace App\Services;

/** Keep JPEG pixel coordinates consistent with browser EXIF decoding before discarding metadata. */
class RoomImageOrientation
{
    public static function read(string $bytes): int
    {
        if (! str_starts_with($bytes, "\xff\xd8") || ! function_exists('exif_read_data')) return 1;
        $stream = fopen('php://memory', 'w+b');
        try {
            fwrite($stream, $bytes); rewind($stream);
            $metadata = @exif_read_data($stream);
            $orientation = (int) ($metadata['Orientation'] ?? 1);
            return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
        } finally {
            fclose($stream);
        }
    }

    public static function apply(\GdImage $image, string $bytes): \GdImage
    {
        $orientation = self::read($bytes);
        if (in_array($orientation, [2,5,7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
        if ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
        $angle = match ($orientation) { 3 => 180, 5,8 => 90, 6,7 => -90, default => 0 };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated) { imagedestroy($image); $image = $rotated; }
        }
        return $image;
    }
}
