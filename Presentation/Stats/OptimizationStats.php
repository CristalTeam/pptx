<?php

namespace Cristal\Presentation\Stats;

class OptimizationStats
{
    /**
     * @var int Taille originale totale
     */
    private $originalSize = 0;

    /**
     * @var int Taille compressée totale
     */
    private $compressedSize = 0;

    /**
     * @var int Nombre d'images compressées
     */
    private $imagesCompressed = 0;

    /**
     * @var int Nombre d'images redimensionnées
     */
    private $imagesResized = 0;

    /**
     * @var int Nombre d'images dédupliquées
     */
    private $imagesDeduplicated = 0;

    /**
     * @var array Détails des compressions
     */
    private $compressionDetails = [];

    /**
     * Enregistre une compression d'image
     *
     * @param int $before Taille avant compression
     * @param int $after Taille après compression
     * @param string $type Type d'image (jpeg, png, etc.)
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
            'ratio' => $after / $before,
        ];
    }

    /**
     * Enregistre un redimensionnement d'image
     */
    public function recordResize(): void
    {
        $this->imagesResized++;
    }

    /**
     * Enregistre une déduplication d'image
     */
    public function recordDeduplication(): void
    {
        $this->imagesDeduplicated++;
    }

    /**
     * Calcule le ratio de compression global
     *
     * @return float
     */
    public function getCompressionRatio(): float
    {
        if ($this->originalSize === 0) {
            return 1.0;
        }
        
        return $this->compressedSize / $this->originalSize;
    }

    /**
     * Calcule les octets économisés
     *
     * @return int
     */
    public function getBytesSaved(): int
    {
        return $this->originalSize - $this->compressedSize;
    }

    /**
     * Calcule le pourcentage d'économie
     *
     * @return float
     */
    public function getSavingsPercent(): float
    {
        if ($this->originalSize === 0) {
            return 0.0;
        }
        
        return (1 - $this->getCompressionRatio()) * 100;
    }

    /**
     * Retourne un rapport complet
     *
     * @return array
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
     * Retourne les détails des compressions
     *
     * @return array
     */
    public function getCompressionDetails(): array
    {
        return $this->compressionDetails;
    }

    /**
     * Réinitialise toutes les statistiques
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
     * Retourne un résumé formaté
     *
     * @return string
     */
    public function getSummary(): string
    {
        $report = $this->getReport();
        
        return sprintf(
            "Optimisation: %d images traitées, %.2f%% économisés (%s -> %s)",
            $report['total_optimizations'],
            $report['savings_percent'],
            $this->formatBytes($report['original_size']),
            $this->formatBytes($report['optimized_size'])
        );
    }

    /**
     * Formate une taille en octets de manière lisible
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = $bytes;
        
        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }
        
        return round($size, 2) . ' ' . $units[$index];
    }
}