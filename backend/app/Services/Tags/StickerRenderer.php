<?php

namespace App\Services\Tags;

use RuntimeException;

/**
 * Builds the print-ready 70 x 70 mm Beni Bul sticker for one tag: the outlined template from
 * resources/stickers/sticker-70.svg (made by brand/scripts/outline-logo.py) with the tag's QR code placed in it.
 */
class StickerRenderer
{
    /** Where the QR goes, in the template's millimetre units: x, y, size. Keep in sync with QR_BOX in the script. */
    public const QR_BOX = [15, 21, 40];

    private const PLACEHOLDER = '<!--QR-->';

    private ?string $template = null;

    /** @param  string  $qrSvg  A complete QR SVG as rendered with WaveService::qrOptions(). */
    public function render(string $qrSvg): string
    {
        $template = $this->template();

        // Drop the XML declaration and turn the QR's root <svg> into a nested one sized to the box.
        $qr = trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', trim($qrSvg)));
        [$x, $y, $size] = self::QR_BOX;
        $nested = preg_replace(
            '/^<svg\s+xmlns="http:\/\/www\.w3\.org\/2000\/svg"/',
            sprintf('<svg x="%s" y="%s" width="%s" height="%s"', $x, $y, $size, $size),
            $qr,
            1,
            $count,
        );
        if ($count !== 1) {
            throw new RuntimeException('Unexpected QR SVG markup.');
        }

        return str_replace(self::PLACEHOLDER, $nested, $template);
    }

    private function template(): string
    {
        if ($this->template === null) {
            $template = @file_get_contents(resource_path('stickers/sticker-70.svg'));
            if ($template === false || ! str_contains($template, self::PLACEHOLDER)) {
                throw new RuntimeException('The sticker template is missing or has no QR placeholder.');
            }
            $this->template = $template;
        }

        return $this->template;
    }
}
