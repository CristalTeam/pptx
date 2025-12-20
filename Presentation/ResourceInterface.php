<?php

declare(strict_types=1);

namespace Cristal\Presentation;

/**
 * Interface for resources in a PPTX archive.
 */
interface ResourceInterface
{
    /**
     * Get the relationship type.
     */
    public function getRelType(): string;

    /**
     * Get the relative target path.
     *
     * @param string $relPath Base path for relativity
     */
    public function getRelativeTarget(string $relPath): string;

    /**
     * Get the target path.
     */
    public function getTarget(): string;

    /**
     * Get the content of this resource.
     */
    public function getContent(): string;

    /**
     * Set the content of this resource.
     */
    public function setContent(string $content): void;

    /**
     * Save the resource.
     */
    public function save(): void;
}
