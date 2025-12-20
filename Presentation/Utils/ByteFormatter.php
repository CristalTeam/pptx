<?php

declare(strict_types=1);

namespace Cristal\Presentation\Utils;

/**
 * Trait for formatting byte sizes into human-readable strings.
 */
trait ByteFormatter
{
    /**
     * Format a byte size into a human-readable string.
     *
     * @param int $bytes The size in bytes
     * @return string Formatted size (e.g., "1.5 MB")
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, 2) . ' ' . $units[$index];
    }
}
