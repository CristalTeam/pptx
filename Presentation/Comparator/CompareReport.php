<?php

declare(strict_types=1);

namespace Cristal\Presentation\Comparator;

class CompareReport
{
    /** @var array<string, mixed> */
    private array $addedFiles = [];
    /** @var array<string, mixed> */
    private array $removedFiles = [];
    /** @var array<string, mixed> */
    private array $modifiedFiles = [];
    /** @var array<string, mixed> */
    private array $rIdChanges = [];

    public function addAddedFile(string $path, int $size): void
    {
        $this->addedFiles[$path] = $size;
    }

    public function addRemovedFile(string $path, int $size): void
    {
        $this->removedFiles[$path] = $size;
    }

    public function addModifiedFile(string $path, int $sizeBefore, int $sizeAfter): void
    {
        $this->modifiedFiles[$path] = ['before' => $sizeBefore, 'after' => $sizeAfter];
    }

    public function addRIdChange(string $file, string $rId, string $oldTarget, string $newTarget): void
    {
        $this->rIdChanges[] = compact('file', 'rId', 'oldTarget', 'newTarget');
    }

    /** @return array<string, int> */
    public function getAddedFiles(): array { return $this->addedFiles; }
    /** @return array<string, int> */
    public function getRemovedFiles(): array { return $this->removedFiles; }
    /** @return array<string, mixed> */
    public function getModifiedFiles(): array { return $this->modifiedFiles; }
    /** @return array<string, mixed> */
    public function getRIdChanges(): array { return $this->rIdChanges; }

    public function hasDifferences(): bool
    {
        return !empty($this->addedFiles) || !empty($this->removedFiles) || !empty($this->modifiedFiles) || !empty($this->rIdChanges);
    }
}
