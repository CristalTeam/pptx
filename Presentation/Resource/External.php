<?php

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\ResourceInterface;

class External implements ResourceInterface
{
    /**
     * @var string
     */
    protected $relType;

    /**
     * @var string
     */
    protected $target;

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
        // Nothing to do...
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getRelativeTarget(string $relPath): string
    {
        return $this->target;
    }
}
