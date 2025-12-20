<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\ResourceInterface;

/**
 * External resource class for handling external references (URLs).
 */
class External implements ResourceInterface
{
    protected string $relType;

    protected string $target;

    public function __construct(string $target, string $relType)
    {
        $this->relType = $relType;
        $this->target = $target;
    }

    public function getRelType(): string
    {
        return $this->relType;
    }

    public function save(): void
    {
        // External resources don't need saving
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getRelativeTarget(string $relPath): string
    {
        return $this->target;
    }

    /**
     * Get the content of this external resource.
     * External resources have no content.
     */
    public function getContent(): string
    {
        return '';
    }

    /**
     * Set the content of this external resource.
     * External resources cannot have content set.
     */
    public function setContent(string $content): void
    {
        // External resources don't support content
    }
}
