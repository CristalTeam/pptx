<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * SVG image resource class.
 *
 * SVG support added in Office 365 (2016+)
 *
 * @see https://support.microsoft.com/en-us/office/edit-svg-images-in-microsoft-365
 */
class SvgImage extends GenericResource
{
    /**
     * Check if the SVG is valid XML.
     */
    public function isValid(): bool
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($this->getContent());
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === false || !empty($errors)) {
            return false;
        }

        return $xml->getName() === 'svg';
    }

    /**
     * Get SVG dimensions from attributes.
     *
     * @return array{width: int|null, height: int|null}
     */
    public function getDimensions(): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($this->getContent());
        libxml_clear_errors();

        if ($xml === false) {
            return ['width' => null, 'height' => null];
        }

        $width = null;
        $height = null;

        // Try to get dimensions from width/height attributes
        if (isset($xml['width'])) {
            $width = $this->parseDimension((string) $xml['width']);
        }
        if (isset($xml['height'])) {
            $height = $this->parseDimension((string) $xml['height']);
        }

        // If no dimensions, try viewBox
        if (($width === null || $height === null) && isset($xml['viewBox'])) {
            $viewBox = explode(' ', (string) $xml['viewBox']);
            if (count($viewBox) === 4) {
                $width = $width ?? (int) $viewBox[2];
                $height = $height ?? (int) $viewBox[3];
            }
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Parse a dimension value (e.g., "100px", "50%", "200").
     */
    private function parseDimension(string $value): ?int
    {
        // Remove units and extract numeric value
        $numeric = preg_replace('/[^0-9.]/', '', $value);

        return $numeric !== '' ? (int) (float) $numeric : null;
    }

    /**
     * Get the viewBox attribute.
     *
     * @return array{minX: int, minY: int, width: int, height: int}|null
     */
    public function getViewBox(): ?array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($this->getContent());
        libxml_clear_errors();

        if ($xml === false || !isset($xml['viewBox'])) {
            return null;
        }

        $viewBox = preg_split('/[\s,]+/', (string) $xml['viewBox']);
        if ($viewBox === false || count($viewBox) !== 4) {
            return null;
        }

        return [
            'minX' => (int) $viewBox[0],
            'minY' => (int) $viewBox[1],
            'width' => (int) $viewBox[2],
            'height' => (int) $viewBox[3],
        ];
    }

    /**
     * Get the SVG as a data URI for embedding.
     */
    public function toDataUri(): string
    {
        $content = $this->getContent();
        $base64 = base64_encode($content);

        return 'data:image/svg+xml;base64,' . $base64;
    }

    /**
     * Convert SVG to PNG for fallback (requires Imagick extension).
     *
     * @param int $width Target width
     * @param int $height Target height
     * @return string|null PNG content or null if conversion fails
     */
    public function toPng(int $width = 800, int $height = 600): ?string
    {
        if (!extension_loaded('imagick')) {
            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->readImageBlob($this->getContent());
            $imagick->setImageFormat('png');
            $imagick->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);

            return $imagick->getImageBlob();
        } catch (\ImagickException $e) {
            return null;
        }
    }

    /**
     * Check if Imagick extension is available for PNG conversion.
     */
    public static function canConvertToPng(): bool
    {
        return extension_loaded('imagick');
    }

    /**
     * Sanitize SVG content for security.
     * Removes potentially dangerous elements like scripts.
     */
    public function sanitize(): string
    {
        $content = $this->getContent();

        // Remove script tags
        $content = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $content) ?? $content;

        // Remove on* event attributes
        $content = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content) ?? $content;

        // Remove javascript: URLs
        $content = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', '', $content) ?? $content;

        return $content;
    }

    /**
     * Get all text content from the SVG.
     *
     * @return array<int, string>
     */
    public function getTextContent(): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($this->getContent());
        libxml_clear_errors();

        if ($xml === false) {
            return [];
        }

        $texts = [];
        $xml->registerXPathNamespace('svg', 'http://www.w3.org/2000/svg');

        $textNodes = $xml->xpath('//svg:text | //text');
        if ($textNodes !== false) {
            foreach ($textNodes as $textNode) {
                $text = (string) $textNode;
                if (!empty(trim($text))) {
                    $texts[] = trim($text);
                }
            }
        }

        return $texts;
    }
}