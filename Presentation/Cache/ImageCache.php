<?php

declare(strict_types=1);

namespace Cristal\Presentation\Cache;

use Cristal\Presentation\Resource\Image;

/**
 * Cache for image resources to detect and handle duplicates.
 */
class ImageCache
{
    /**
     * Cache of image hashes.
     *
     * @var array<string, Image>
     */
    private array $cache = [];

    /**
     * Number of duplicates found.
     */
    private int $duplicatesFound = 0;

    /**
     * Calculate a robust hash based on full content using SHA256.
     *
     * IMPORTANT: Always use full content hash to avoid false negatives during merge.
     * False negatives (different hashes for identical files) cause duplicate media bloat.
     *
     * Previous implementation used partial hash (first/last 8KB) which caused issues
     * when merging PPTX files with identical images.
     *
     * @param string $content Image content
     * @return string SHA256 hash of full content
     */
    public function fastHash(string $content): string
    {
        // Always use full SHA256 hash for reliable deduplication
        // SHA256 is faster than partial MD5 on modern PHP (7.4+)
        return hash('sha256', $content);
    }

    /**
     * Find a duplicate image in the cache.
     *
     * @param string $content Image content
     * @return Image|null Existing image if found, null otherwise
     */
    public function findDuplicate(string $content): ?Image
    {
        $hash = $this->fastHash($content);

        if (isset($this->cache[$hash])) {
            $this->duplicatesFound++;

            return $this->cache[$hash];
        }

        return null;
    }

    /**
     * Register an image in the cache.
     *
     * @param string $hash Image hash
     * @param Image $image Image instance
     */
    public function register(string $hash, Image $image): void
    {
        $this->cache[$hash] = $image;
    }

    /**
     * Register an image with automatic hash calculation.
     *
     * @param string $content Image content
     * @param Image $image Image instance
     * @return string Calculated hash
     */
    public function registerWithContent(string $content, Image $image): string
    {
        $hash = $this->fastHash($content);
        $this->register($hash, $image);

        return $hash;
    }

    /**
     * Check if a hash exists in the cache.
     *
     * @param string $hash Hash to check
     */
    public function has(string $hash): bool
    {
        return isset($this->cache[$hash]);
    }

    /**
     * Get an image from the cache.
     *
     * @param string $hash Image hash
     */
    public function get(string $hash): ?Image
    {
        return $this->cache[$hash] ?? null;
    }

    /**
     * Clear the cache.
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->duplicatesFound = 0;
    }

    /**
     * Get the number of images in cache.
     */
    public function count(): int
    {
        return count($this->cache);
    }

    /**
     * Get the number of duplicates found.
     */
    public function getDuplicatesFound(): int
    {
        return $this->duplicatesFound;
    }

    /**
     * Get cache statistics.
     *
     * @return array{cached_images: int, duplicates_found: int, memory_keys: int}
     */
    public function getStats(): array
    {
        return [
            'cached_images' => $this->count(),
            'duplicates_found' => $this->duplicatesFound,
            'memory_keys' => count($this->cache),
        ];
    }
}
