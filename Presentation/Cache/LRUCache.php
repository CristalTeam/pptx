<?php

declare(strict_types=1);

namespace Cristal\Presentation\Cache;

/**
 * Least Recently Used (LRU) cache implementation.
 */
class LRUCache
{
    /**
     * Maximum cache size.
     */
    private int $maxSize;

    /**
     * Cache storage [key => value].
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * Access order tracking [key => timestamp].
     *
     * @var array<string, int>
     */
    private array $order = [];

    /**
     * Counter for access ordering.
     */
    private int $counter = 0;

    /**
     * Number of evictions performed.
     */
    private int $evictions = 0;

    /**
     * Number of cache hits.
     */
    private int $hits = 0;

    /**
     * Number of cache misses.
     */
    private int $misses = 0;

    /**
     * LRUCache constructor.
     *
     * @param int $maxSize Maximum cache size
     */
    public function __construct(int $maxSize = 100)
    {
        $this->maxSize = $maxSize;
    }

    /**
     * Get a value from the cache.
     *
     * @param string $key Key to retrieve
     * @return mixed|null Value or null if not found
     */
    public function get(string $key): mixed
    {
        if (!isset($this->cache[$key])) {
            $this->misses++;

            return null;
        }

        // Update access order (mark as recently used)
        $this->order[$key] = ++$this->counter;
        $this->hits++;

        return $this->cache[$key];
    }

    /**
     * Add or update a value in the cache.
     *
     * @param string $key Key
     * @param mixed $value Value
     */
    public function set(string $key, mixed $value): void
    {
        // If key already exists, update it
        if (isset($this->cache[$key])) {
            $this->cache[$key] = $value;
            $this->order[$key] = ++$this->counter;

            return;
        }

        // If cache is full, evict the oldest
        if (count($this->cache) >= $this->maxSize) {
            $this->evict();
        }

        // Add new element
        $this->cache[$key] = $value;
        $this->order[$key] = ++$this->counter;
    }

    /**
     * Evict the least recently used element.
     */
    private function evict(): void
    {
        if (empty($this->order)) {
            return;
        }

        // Find the key with the smallest timestamp
        $oldestKey = array_search(min($this->order), $this->order, true);

        unset($this->cache[$oldestKey]);
        unset($this->order[$oldestKey]);
        $this->evictions++;
    }

    /**
     * Check if a key exists in the cache.
     *
     * @param string $key Key to check
     */
    public function has(string $key): bool
    {
        return isset($this->cache[$key]);
    }

    /**
     * Remove a key from the cache.
     *
     * @param string $key Key to remove
     */
    public function delete(string $key): void
    {
        unset($this->cache[$key]);
        unset($this->order[$key]);
    }

    /**
     * Clear the entire cache.
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->order = [];
        $this->counter = 0;
        $this->evictions = 0;
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * Get the number of elements in the cache.
     */
    public function count(): int
    {
        return count($this->cache);
    }

    /**
     * Get the maximum cache size.
     */
    public function getMaxSize(): int
    {
        return $this->maxSize;
    }

    /**
     * Set the maximum cache size.
     *
     * @param int $maxSize New maximum size
     */
    public function setMaxSize(int $maxSize): void
    {
        $this->maxSize = $maxSize;

        // Evict if necessary to respect the new size
        while (count($this->cache) > $this->maxSize) {
            $this->evict();
        }
    }

    /**
     * Get cache statistics.
     *
     * @return array{size: int, max_size: int, hits: int, misses: int, hit_rate: float, evictions: int, usage_percent: float}
     */
    public function getStats(): array
    {
        $total = $this->hits + $this->misses;
        $hitRate = $total > 0 ? ($this->hits / $total) * 100 : 0;

        return [
            'size' => count($this->cache),
            'max_size' => $this->maxSize,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => round($hitRate, 2),
            'evictions' => $this->evictions,
            'usage_percent' => round((count($this->cache) / $this->maxSize) * 100, 2),
        ];
    }

    /**
     * Get all cache keys.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->cache);
    }

    /**
     * Get all cache values.
     *
     * @return array<int, mixed>
     */
    public function values(): array
    {
        return array_values($this->cache);
    }

    /**
     * Get all cache entries.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->cache;
    }
}
