<?php

declare(strict_types=1);

namespace Cristal\Presentation\Validator;

use Cristal\Presentation\Config\OptimizationConfig;
use Cristal\Presentation\Resource\Image;
use Cristal\Presentation\Resource\Slide;
use Cristal\Presentation\Utils\ByteFormatter;
use Exception;

/**
 * Validator for presentations (slides and resources).
 */
class PresentationValidator
{
    use ByteFormatter;

    /**
     * Image validator.
     */
    private ImageValidator $imageValidator;

    /**
     * Validation errors.
     *
     * @var array<int, string>
     */
    private array $errors = [];

    /**
     * Warnings.
     *
     * @var array<int, string>
     */
    private array $warnings = [];

    /**
     * PresentationValidator constructor.
     */
    public function __construct(?OptimizationConfig $config = null)
    {
        $this->imageValidator = new ImageValidator($config);
    }

    /**
     * Validate an array of slides.
     *
     * @param array<int, mixed> $slides
     * @return array{
     *     valid: bool,
     *     total_slides: int,
     *     valid_slides: int,
     *     invalid_slides: int,
     *     errors: array<int, string>,
     *     warnings: array<int, string>
     * }
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
                $this->errors[] = "Slide $index is not a Slide instance";
                $report['invalid_slides']++;
                continue;
            }

            try {
                // Validate XML content
                $content = $slide->getContent();
                if (empty($content)) {
                    $this->warnings[] = "Slide $index has empty content";
                }

                $report['valid_slides']++;
            } catch (Exception $e) {
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
     * Validate resources (mainly images).
     *
     * @param array<int, mixed> $resources
     * @return array{
     *     valid: bool,
     *     total_resources: int,
     *     valid_resources: int,
     *     invalid_resources: int,
     *     images_checked: int,
     *     corrupted_images: int,
     *     oversized_images: int,
     *     errors: array<int, string>,
     *     warnings: array<int, string>
     * }
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

                        // Check size
                        if ($imageReport['size'] > OptimizationConfig::IMAGE_SIZE_WARNING_THRESHOLD) {
                            $report['oversized_images']++;
                            $this->warnings[] = sprintf(
                                'Image %s is large: %s',
                                $resource->getTarget(),
                                $this->formatBytes($imageReport['size'])
                            );
                        }
                    }
                } catch (Exception $e) {
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
     * Validate a complete presentation.
     *
     * @param array<int, mixed> $slides
     * @param array<int, mixed> $resources
     * @return array{
     *     valid: bool,
     *     slides: array,
     *     resources: array,
     *     summary: string
     * }
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
     * Generate a validation summary.
     *
     * @param array{valid: bool, total_slides: int, valid_slides: int, errors: array<int, string>, warnings: array<int, string>} $slidesReport
     * @param array{valid: bool, total_resources: int, valid_resources: int, images_checked: int, corrupted_images: int, oversized_images: int, errors: array<int, string>, warnings: array<int, string>} $resourcesReport
     */
    private function generateSummary(array $slidesReport, array $resourcesReport): string
    {
        $lines = [];

        $lines[] = sprintf(
            'Slides: %d/%d valid',
            $slidesReport['valid_slides'],
            $slidesReport['total_slides']
        );

        $lines[] = sprintf(
            'Resources: %d/%d valid',
            $resourcesReport['valid_resources'],
            $resourcesReport['total_resources']
        );

        if ($resourcesReport['images_checked'] > 0) {
            $lines[] = sprintf(
                'Images: %d checked, %d corrupted, %d oversized',
                $resourcesReport['images_checked'],
                $resourcesReport['corrupted_images'],
                $resourcesReport['oversized_images']
            );
        }

        $totalErrors = count($slidesReport['errors']) + count($resourcesReport['errors']);
        $totalWarnings = count($slidesReport['warnings']) + count($resourcesReport['warnings']);

        if ($totalErrors > 0) {
            $lines[] = "$totalErrors error(s)";
        }

        if ($totalWarnings > 0) {
            $lines[] = "$totalWarnings warning(s)";
        }

        return implode(', ', $lines);
    }

    /**
     * Get all errors.
     *
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get all warnings.
     *
     * @return array<int, string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
