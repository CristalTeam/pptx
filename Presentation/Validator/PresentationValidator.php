<?php

namespace Cristal\Presentation\Validator;

use Cristal\Presentation\Config\OptimizationConfig;
use Cristal\Presentation\Resource\Image;
use Cristal\Presentation\Resource\Slide;

class PresentationValidator
{
    /**
     * @var ImageValidator
     */
    private $imageValidator;

    /**
     * @var array Erreurs de validation
     */
    private $errors = [];

    /**
     * @var array Warnings
     */
    private $warnings = [];

    /**
     * PresentationValidator constructor.
     *
     * @param OptimizationConfig|null $config
     */
    public function __construct(?OptimizationConfig $config = null)
    {
        $this->imageValidator = new ImageValidator($config);
    }

    /**
     * Valide un tableau de slides
     *
     * @param array $slides
     * @return array Rapport de validation
     */
    public function validateSlides(array $slides): array
    {
        $this->errors = [];
        $this->warnings = [];

        $report = [
            'valid' => true,
            'total_slides' => count($slides),
            'valid_slides' => 0,
            'invalid_slides' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        foreach ($slides as $index => $slide) {
            if (!$slide instanceof Slide) {
                $this->errors[] = "Slide $index n'est pas une instance de Slide";
                $report['invalid_slides']++;
                continue;
            }

            try {
                // Valider le contenu XML
                $content = $slide->getContent();
                if (empty($content)) {
                    $this->warnings[] = "Slide $index a un contenu vide";
                }

                $report['valid_slides']++;
            } catch (\Exception $e) {
                $this->errors[] = "Slide $index: " . $e->getMessage();
                $report['invalid_slides']++;
            }
        }

        $report['valid'] = $report['invalid_slides'] === 0;
        $report['errors'] = $this->errors;
        $report['warnings'] = $this->warnings;

        return $report;
    }

    /**
     * Valide les ressources (principalement les images)
     *
     * @param array $resources
     * @return array Rapport de validation
     */
    public function validateResources(array $resources): array
    {
        $this->errors = [];
        $this->warnings = [];

        $report = [
            'valid' => true,
            'total_resources' => count($resources),
            'valid_resources' => 0,
            'invalid_resources' => 0,
            'images_checked' => 0,
            'corrupted_images' => 0,
            'oversized_images' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        foreach ($resources as $resource) {
            if ($resource instanceof Image) {
                $report['images_checked']++;

                try {
                    $content = $resource->getContent();
                    $imageReport = $this->imageValidator->validateWithReport($content);

                    if (!$imageReport['valid']) {
                        $report['invalid_resources']++;
                        $report['corrupted_images']++;
                        $this->errors[] = "Image {$resource->getTarget()}: " . implode(', ', $imageReport['errors']);
                    } else {
                        $report['valid_resources']++;

                        // Vérifier la taille
                        if ($imageReport['size'] > 5 * 1024 * 1024) { // > 5MB
                            $report['oversized_images']++;
                            $this->warnings[] = sprintf(
                                "Image {$resource->getTarget()} est volumineuse: %s",
                                $this->formatBytes($imageReport['size'])
                            );
                        }
                    }
                } catch (\Exception $e) {
                    $report['invalid_resources']++;
                    $this->errors[] = "Resource {$resource->getTarget()}: " . $e->getMessage();
                }
            } else {
                $report['valid_resources']++;
            }
        }

        $report['valid'] = $report['invalid_resources'] === 0;
        $report['errors'] = $this->errors;
        $report['warnings'] = $this->warnings;

        return $report;
    }

    /**
     * Valide une présentation complète
     *
     * @param array $slides
     * @param array $resources
     * @return array Rapport complet
     */
    public function validatePresentation(array $slides, array $resources): array
    {
        $slidesReport = $this->validateSlides($slides);
        $resourcesReport = $this->validateResources($resources);

        return [
            'valid' => $slidesReport['valid'] && $resourcesReport['valid'],
            'slides' => $slidesReport,
            'resources' => $resourcesReport,
            'summary' => $this->generateSummary($slidesReport, $resourcesReport),
        ];
    }

    /**
     * Génère un résumé de validation
     *
     * @param array $slidesReport
     * @param array $resourcesReport
     * @return string
     */
    private function generateSummary(array $slidesReport, array $resourcesReport): string
    {
        $lines = [];

        $lines[] = sprintf(
            "Slides: %d/%d valides",
            $slidesReport['valid_slides'],
            $slidesReport['total_slides']
        );

        $lines[] = sprintf(
            "Ressources: %d/%d valides",
            $resourcesReport['valid_resources'],
            $resourcesReport['total_resources']
        );

        if ($resourcesReport['images_checked'] > 0) {
            $lines[] = sprintf(
                "Images: %d vérifiées, %d corrompues, %d volumineuses",
                $resourcesReport['images_checked'],
                $resourcesReport['corrupted_images'],
                $resourcesReport['oversized_images']
            );
        }

        $totalErrors = count($slidesReport['errors']) + count($resourcesReport['errors']);
        $totalWarnings = count($slidesReport['warnings']) + count($resourcesReport['warnings']);

        if ($totalErrors > 0) {
            $lines[] = "$totalErrors erreur(s)";
        }

        if ($totalWarnings > 0) {
            $lines[] = "$totalWarnings avertissement(s)";
        }

        return implode(', ', $lines);
    }

    /**
     * Formate une taille en octets
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

    /**
     * Retourne toutes les erreurs
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Retourne tous les warnings
     *
     * @return array
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}