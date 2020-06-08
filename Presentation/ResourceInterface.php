<?php

namespace Cristal\Presentation;

interface ResourceInterface
{
    public function getRelType(): string;

    public function getRelativeTarget(string $relPath): string;

    public function getTarget(): string;

    public function save(): void;
}
