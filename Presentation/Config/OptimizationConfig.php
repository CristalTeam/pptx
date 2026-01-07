<?php

declare(strict_types=1);

namespace Cristal\Presentation\Config;

use InvalidArgumentException;

/**
 * Configuration class for image optimization settings.
 */
class OptimizationConfig
{
    /**
     * Maximum image size constant (10MB).
     */
    public const MAX_IMAGE_SIZE_DEFAULT = 10 * 1024 * 1024;

    /**
     * Image size warning threshold (5MB).
     */
    public const IMAGE_SIZE_WARNING_THRESHOLD = 5 * 1024 * 1024;

    /**
     * Default options for optimizations.
     */
    private const DEFAULTS = [
        // Image optimization
        'image_compression' => false,
        'image_quality' => 85,
        'max_image_width' => 1920,
        'max_image_height' => 1080,
        'convert_to_webp' => false,

        // Performance
        'lazy_loading' => true,
        'cache_size' => 100,
        'deduplicate_images' => false,
        'use_lru_cache' => true,

        // Batch processing
        'batch_size' => 50,

        // Validation
        'validate_images' => false,
        'max_image_size' => self::MAX_IMAGE_SIZE_DEFAULT,

        // Debug
        'collect_stats' => false,
    ];

    /**
     * Configuration options.
     *
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * OptimizationConfig constructor.
     *
     * @param array<string, mixed> $options Configuration options
     * @throws InvalidArgumentException
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge(self::DEFAULTS, $options);
        $this->validate();
    }

    /**
     * Validate configuration options.
     *
     * @throws InvalidArgumentException
     */
    private function validate(): void
    {
        if ($this->options['image_quality'] < 1 || $this->options['image_quality'] > 100) {
            throw new InvalidArgumentException('image_quality must be between 1 and 100');
        }

        if ($this->options['max_image_width'] < 1 || $this->options['max_image_height'] < 1) {
            throw new InvalidArgumentException('Max dimensions must be positive');
        }

        if ($this->options['cache_size'] < 1) {
            throw new InvalidArgumentException('cache_size must be at least 1');
        }

        if ($this->options['max_image_size'] < 1) {
            throw new InvalidArgumentException('max_image_size must be positive');
        }
    }

    /**
     * Get a configuration option.
     *
     * @param string $key Option key
     * @return mixed Option value
     */
    public function get(string $key)
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Set a configuration option.
     *
     * @param string $key Option key
     * @param mixed $value Option value
     * @throws InvalidArgumentException
     */
    public function set(string $key, mixed $value): void
    {
        $this->options[$key] = $value;
        $this->validate();
    }

    /**
     * Check if an option is enabled.
     *
     * @param string $key Option key
     */
    public function isEnabled(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * Get all options.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->options;
    }

    /**
     * Enable default optimizations.
     */
    public function enableOptimizations(): void
    {
        $this->options['image_compression'] = true;
        $this->options['deduplicate_images'] = true;
        $this->options['lazy_loading'] = true;
        $this->options['collect_stats'] = true;
    }
}
