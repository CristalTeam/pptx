<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\Config\OptimizationConfig;
use finfo;

/**
 * Image resource class for handling images in a PPTX archive.
 */
class Image extends GenericResource
{
    /**
     * Original image size.
     */
    private ?int $originalSize = null;

    /**
     * Compressed image size.
     */
    private ?int $compressedSize = null;

    /**
     * Optimization configuration.
     */
    private ?OptimizationConfig $config = null;

    /**
     * Set the optimization configuration.
     */
    public function setOptimizationConfig(OptimizationConfig $config): void
    {
        $this->config = $config;
    }

    /**
     * Set the image content with optional optimization.
     */
    public function setContent(string $content): void
    {
        $this->originalSize = strlen($content);

        // Apply optimizations if configured
        if ($this->config && $this->config->isEnabled('image_compression')) {
            $content = $this->optimizeImage($content);
        }

        $this->compressedSize = strlen($content);

        parent::setContent($content);
    }

    /**
     * Optimize an image (compression + resize + optional WebP conversion).
     *
     * @param string $content Image content
     * @return string Optimized content
     */
    private function optimizeImage(string $content): string
    {
        $imageType = $this->detectImageType($content);

        if ($imageType === null) {
            return $content; // Unsupported type, no optimization
        }

        $image = @imagecreatefromstring($content);

        if ($image === false) {
            return $content; // Corrupted image, no optimization
        }

        // Resize if needed
        if ($this->config->isEnabled('image_compression')) {
            $image = $this->resizeIfNeeded($image);
        }

        // Convert to WebP if enabled and supported
        if ($this->config->isEnabled('convert_to_webp') && function_exists('imagewebp')) {
            ob_start();
            $quality = $this->config->get('image_quality');
            if (imagewebp($image, null, $quality)) {
                $optimized = ob_get_clean();
                imagedestroy($image);

                return $optimized;
            }
            ob_end_clean();
        }

        // Otherwise, compress according to type
        $optimized = $this->compressImage($image, $imageType);

        imagedestroy($image);

        return $optimized !== false ? $optimized : $content;
    }

    /**
     * Detect image type from content.
     *
     * @param string $content Image content
     * @return string|null Image type (jpeg, png, gif, etc.) or null if unsupported
     */
    public function detectImageType(string $content): ?string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);

        $typeMap = [
            'image/jpeg' => 'jpeg',
            'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        return $typeMap[$mimeType] ?? null;
    }

    /**
     * Resize image if it exceeds max dimensions.
     *
     * @param \GdImage $image GD image resource
     * @return \GdImage Resized or original image
     */
    private function resizeIfNeeded(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $maxWidth = $this->config->get('max_image_width');
        $maxHeight = $this->config->get('max_image_height');

        if ($width <= $maxWidth && $height <= $maxHeight) {
            return $image; // No resize needed
        }

        // Calculate new dimensions preserving aspect ratio
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int) ($width * $ratio);
        $newHeight = (int) ($height * $ratio);

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG and GIF
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled(
            $resized,
            $image,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height
        );

        imagedestroy($image);

        return $resized;
    }

    /**
     * Compress an image according to its type.
     *
     * @param \GdImage $image GD image resource
     * @param string $type Image type (jpeg, png, etc.)
     * @return false|string Compressed content or false on error
     */
    private function compressImage(\GdImage $image, string $type): string
    {
        ob_start();

        $result = false;

        switch ($type) {
            case 'jpeg':
                $quality = $this->config->get('image_quality');
                $result = imagejpeg($image, null, $quality);
                break;

            case 'png':
                // PNG: compression level 0-9 (9 = max compression)
                $level = (int) (9 - ($this->config->get('image_quality') / 100 * 9));
                $result = imagepng($image, null, $level);
                break;

            case 'gif':
                $result = imagegif($image);
                break;

            case 'webp':
                if (function_exists('imagewebp')) {
                    $quality = $this->config->get('image_quality');
                    $result = imagewebp($image, null, $quality);
                }
                break;
        }

        $content = ob_get_clean();

        return $result ? $content : false;
    }

    /**
     * Convert an image to WebP format.
     *
     * @param string $content Image content
     * @param int $quality Quality (1-100)
     * @return false|string WebP content or false on error
     */
    public function convertToWebP(string $content, int $quality = 85): string
    {
        if (!function_exists('imagewebp')) {
            return false;
        }

        $image = @imagecreatefromstring($content);

        if ($image === false) {
            return false;
        }

        // Preserve transparency
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $result = imagewebp($image, null, $quality);
        $webp = ob_get_clean();

        imagedestroy($image);

        return $result ? $webp : false;
    }

    /**
     * Compress a JPEG image.
     *
     * @param string $content JPEG content
     * @param int $quality Quality (1-100)
     * @return string Compressed content
     */
    public function compressJpeg(string $content, int $quality): string
    {
        $image = @imagecreatefromstring($content);

        if ($image === false) {
            return $content;
        }

        ob_start();
        imagejpeg($image, null, $quality);
        $compressed = ob_get_clean();

        imagedestroy($image);

        return $compressed !== false ? $compressed : $content;
    }

    /**
     * Compress a PNG image.
     *
     * @param string $content PNG content
     * @param int $level Compression level (0-9)
     * @return string Compressed content
     */
    public function compressPng(string $content, int $level): string
    {
        $image = @imagecreatefromstring($content);

        if ($image === false) {
            return $content;
        }

        // Preserve transparency
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagepng($image, null, $level);
        $compressed = ob_get_clean();

        imagedestroy($image);

        return $compressed !== false ? $compressed : $content;
    }

    /**
     * Get image dimensions.
     *
     * @param string $content Image content
     * @return array{width: int, height: int}|null Dimensions or null on error
     */
    public function getDimensions(string $content): ?array
    {
        $image = @imagecreatefromstring($content);

        if ($image === false) {
            return null;
        }

        $dimensions = [
            'width' => imagesx($image),
            'height' => imagesy($image),
        ];

        imagedestroy($image);

        return $dimensions;
    }

    /**
     * Get the original image size.
     */
    public function getOriginalSize(): ?int
    {
        return $this->originalSize;
    }

    /**
     * Get the compressed image size.
     */
    public function getCompressedSize(): ?int
    {
        return $this->compressedSize;
    }

    /**
     * Get the compression ratio.
     */
    public function getCompressionRatio(): ?float
    {
        if ($this->originalSize === null || $this->compressedSize === null || $this->originalSize === 0) {
            return null;
        }

        return $this->compressedSize / $this->originalSize;
    }
}
