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
     * Calculate a fast hash based on first and last bytes.
     * Faster than md5() on the entire content.
     *
     * @param string $content Image content
     * @return string Fast hash
     */
    public function fastHash(string $content): string
    {
        $length = strlen($content);

        // For small files, full hash
        if ($length < 16384) {
            return md5($content);
        }

        // For large files, partial hash (first 8KB + last 8KB + size)
        $start = substr($content, 0, 8192);
        $end = substr($content, -8192);

        return md5($start . $end . $length);
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
