<?php

declare(strict_types=1);

namespace Cristal\Presentation\Validator;

use Cristal\Presentation\Config\OptimizationConfig;
use Cristal\Presentation\Utils\ByteFormatter;
use finfo;

/**
 * Validator for image resources.
 */
class ImageValidator
{
    use ByteFormatter;

    /**
     * Optimization configuration.
     */
    private ?OptimizationConfig $config;

    /**
     * Validation errors.
     *
     * @var array<int, string>
     */
    private array $errors = [];

    /**
     * Supported image formats.
     */
    private const SUPPORTED_FORMATS = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
    ];

    /**
     * ImageValidator constructor.
     */
    public function __construct(?OptimizationConfig $config = null)
    {
        $this->config = $config;
    }

    /**
     * Validate an image.
     *
     * @param string $content Image content
     * @return bool True if valid
     */
    public function validate(string $content): bool
    {
        $this->errors = [];

        // Size validation
        if (!$this->validateSize($content)) {
            return false;
        }

        // MIME type validation
        if (!$this->validateMimeType($content)) {
            return false;
        }

        // Integrity validation
        if (!$this->validateIntegrity($content)) {
            return false;
        }

        // Dimensions validation if enabled
        if ($this->config && $this->config->isEnabled('validate_images')) {
            if (!$this->validateDimensions($content)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate file size.
     */
    public function validateSize(string $content): bool
    {
        $size = strlen($content);

        if ($size === 0) {
            $this->errors[] = 'Empty image';

            return false;
        }

        $maxSize = $this->config ? $this->config->get('max_image_size') : OptimizationConfig::MAX_IMAGE_SIZE_DEFAULT;

        if ($size > $maxSize) {
            $this->errors[] = sprintf(
                'Image too large: %s (max: %s)',
                $this->formatBytes($size),
                $this->formatBytes($maxSize)
            );

            return false;
        }

        return true;
    }

    /**
     * Validate MIME type.
     */
    public function validateMimeType(string $content): bool
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);

        if (!in_array($mimeType, self::SUPPORTED_FORMATS, true)) {
            $this->errors[] = sprintf(
                'Unsupported format: %s (supported: %s)',
                $mimeType,
                implode(', ', self::SUPPORTED_FORMATS)
            );

            return false;
        }

        return true;
    }

    /**
     * Validate image integrity.
     */
    public function validateIntegrity(string $content): bool
    {
        // Check if GD extension is available
        if (!function_exists('imagecreatefromstring')) {
            // Skip integrity check if GD is not available
            return true;
        }

        // Try to create a GD resource
        $image = @imagecreatefromstring($content);

        if ($image === false) {
            $this->errors[] = 'Corrupted image or invalid format';

            return false;
        }

        imagedestroy($image);

        return true;
    }

    /**
     * Validate image dimensions.
     */
    public function validateDimensions(string $content): bool
    {
        // Check if GD extension is available
        if (!function_exists('imagecreatefromstring')) {
            // Skip dimensions check if GD is not available
            return true;
        }

        $image = @imagecreatefromstring($content);

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        imagedestroy($image);

        // Minimum dimensions
        if ($width === 0 || $height === 0) {
            $this->errors[] = 'Invalid dimensions';

            return false;
        }

        // Maximum dimensions if configured
        if ($this->config) {
            $maxWidth = $this->config->get('max_image_width') * 2; // 2x to avoid false positives
            $maxHeight = $this->config->get('max_image_height') * 2;

            if ($width > $maxWidth || $height > $maxHeight) {
                $this->errors[] = sprintf(
                    'Image too large: %dx%d (max recommended: %dx%d)',
                    $width,
                    $height,
                    $maxWidth,
                    $maxHeight
                );
                // Warning only, no failure
            }
        }

        return true;
    }

    /**
     * Check if an image is corrupted.
     *
     * @param string $content Image content
     * @return bool True if corrupted
     */
    public function isCorrupted(string $content): bool
    {
        return !$this->validateIntegrity($content);
    }

    /**
     * Get validation errors.
     *
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get the last error.
     */
    public function getLastError(): ?string
    {
        return end($this->errors) ?: null;
    }

    /**
     * Validate and return a report.
     *
     * @param string $content Image content
     * @return array{valid: bool, errors: array<int, string>, size: int, mime_type: string|null, dimensions: array{width: int, height: int}|null}
     */
    public function validateWithReport(string $content): array
    {
        $isValid = $this->validate($content);

        $report = [
            'valid' => $isValid,
            'errors' => $this->errors,
            'size' => strlen($content),
            'mime_type' => null,
            'dimensions' => null,
        ];

        if ($isValid || !empty($content)) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $report['mime_type'] = $finfo->buffer($content);

            // Check if GD extension is available
            if (function_exists('imagecreatefromstring')) {
                $image = @imagecreatefromstring($content);
                if ($image !== false) {
                    $report['dimensions'] = [
                        'width' => imagesx($image),
                        'height' => imagesy($image),
                    ];
                    imagedestroy($image);
                }
            }
        }

        return $report;
    }

    /**
     * Get supported formats.
     *
     * @return array<int, string>
     */
    public static function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }
}
