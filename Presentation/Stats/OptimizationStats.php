<?php

declare(strict_types=1);

namespace Cristal\Presentation\Stats;

use Cristal\Presentation\Utils\ByteFormatter;

/**
 * Statistics collector for image optimizations.
 */
class OptimizationStats
{
    use ByteFormatter;

    /**
     * Total original size.
     */
    private int $originalSize = 0;

    /**
     * Total compressed size.
     */
    private int $compressedSize = 0;

    /**
     * Number of compressed images.
     */
    private int $imagesCompressed = 0;

    /**
     * Number of resized images.
     */
    private int $imagesResized = 0;

    /**
     * Number of deduplicated images.
     */
    private int $imagesDeduplicated = 0;

    /**
     * Compression details.
     *
     * @var array<int, array{type: string, before: int, after: int, saved: int, ratio: float}>
     */
    private array $compressionDetails = [];

    /**
     * Record an image compression.
     *
     * @param int $before Size before compression
     * @param int $after Size after compression
     * @param string $type Image type (jpeg, png, etc.)
     */
    public function recordCompression(int $before, int $after, string $type = 'unknown'): void
    {
        $this->originalSize += $before;
        $this->compressedSize += $after;
        $this->imagesCompressed++;

        $this->compressionDetails[] = [
            'type' => $type,
            'before' => $before,
            'after' => $after,
            'saved' => $before - $after,
            'ratio' => $before > 0 ? $after / $before : 1.0,
        ];
    }

    /**
     * Record an image resize.
     */
    public function recordResize(): void
    {
        $this->imagesResized++;
    }

    /**
     * Record an image deduplication.
     */
    public function recordDeduplication(): void
    {
        $this->imagesDeduplicated++;
    }

    /**
     * Calculate the global compression ratio.
     */
    public function getCompressionRatio(): float
    {
        if ($this->originalSize === 0) {
            return 1.0;
        }

        return $this->compressedSize / $this->originalSize;
    }

    /**
     * Calculate bytes saved.
     */
    public function getBytesSaved(): int
    {
        return $this->originalSize - $this->compressedSize;
    }

    /**
     * Calculate savings percentage.
     */
    public function getSavingsPercent(): float
    {
        if ($this->originalSize === 0) {
            return 0.0;
        }

        return (1 - $this->getCompressionRatio()) * 100;
    }

    /**
     * Get a complete report.
     *
     * @return array{
     *     original_size: int,
     *     optimized_size: int,
     *     bytes_saved: int,
     *     compression_ratio: float,
     *     savings_percent: float,
     *     images_compressed: int,
     *     images_resized: int,
     *     images_deduplicated: int,
     *     total_optimizations: int
     * }
     */
    public function getReport(): array
    {
        return [
            'original_size' => $this->originalSize,
            'optimized_size' => $this->compressedSize,
            'bytes_saved' => $this->getBytesSaved(),
            'compression_ratio' => round($this->getCompressionRatio(), 3),
            'savings_percent' => round($this->getSavingsPercent(), 2),
            'images_compressed' => $this->imagesCompressed,
            'images_resized' => $this->imagesResized,
            'images_deduplicated' => $this->imagesDeduplicated,
            'total_optimizations' => $this->imagesCompressed + $this->imagesResized + $this->imagesDeduplicated,
        ];
    }

    /**
     * Get compression details.
     *
     * @return array<int, array{type: string, before: int, after: int, saved: int, ratio: float}>
     */
    public function getCompressionDetails(): array
    {
        return $this->compressionDetails;
    }

    /**
     * Reset all statistics.
     */
    public function reset(): void
    {
        $this->originalSize = 0;
        $this->compressedSize = 0;
        $this->imagesCompressed = 0;
        $this->imagesResized = 0;
        $this->imagesDeduplicated = 0;
        $this->compressionDetails = [];
    }

    /**
     * Get a formatted summary.
     */
    public function getSummary(): string
    {
        $report = $this->getReport();

        return sprintf(
            'Optimization: %d images processed, %.2f%% saved (%s -> %s)',
            $report['total_optimizations'],
            $report['savings_percent'],
            $this->formatBytes($report['original_size']),
            $this->formatBytes($report['optimized_size'])
        );
    }
}
