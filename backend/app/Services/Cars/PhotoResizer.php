<?php

namespace App\Services\Cars;

use GdImage;
use RuntimeException;

/**
 * Turns an uploaded car photo into the stored one: at most 1024 px on its longer side, aspect ratio kept, saved
 * as a progressive JPEG at quality 80. The app already sends 1024 x 576 (16:9); this is what keeps storage and
 * bandwidth small for every other upload, and it drops any metadata (location, camera) the file carried.
 *
 * Plain GD, so there is no extra dependency. JPEG, PNG and WebP are read; transparency is flattened onto white.
 * EXIF orientation is not applied (the exif extension is not installed): the app re-encodes photos upright
 * before sending them.
 */
class PhotoResizer
{
    public const MAX_SIDE = 1024;

    public const QUALITY = 80;

    /** Larger images are refused before decoding: GD needs about 4 bytes per pixel. */
    public const MAX_PIXELS = 25_000_000;

    /** @return string JPEG bytes */
    public function toJpeg(string $path): string
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException('Not a readable image.');
        }
        [$width, $height, $type] = $info;
        if ($width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('Image too large to decode.');
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
        if (! $source instanceof GdImage) {
            throw new RuntimeException('Image could not be decoded.');
        }

        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imageinterlace($target, true);

        ob_start();
        $ok = imagejpeg($target, null, self::QUALITY);
        $bytes = (string) ob_get_clean();
        if (! $ok || $bytes === '') {
            throw new RuntimeException('Image could not be encoded.');
        }

        return $bytes;
    }
}
