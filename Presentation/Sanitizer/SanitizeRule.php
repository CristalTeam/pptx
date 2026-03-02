<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer;

use ZipArchive;

interface SanitizeRule
{
    public function name(): string;

    public function severity(): Severity;

    /**
     * Detect issues in the PPTX archive.
     *
     * @return SanitizeIssue[]
     */
    public function detect(ZipArchive $archive): array;

    /**
     * Repair detected issues in the PPTX archive.
     *
     * @param SanitizeIssue[] $issues Issues detected by detect()
     */
    public function repair(ZipArchive $archive, array $issues): void;
}
