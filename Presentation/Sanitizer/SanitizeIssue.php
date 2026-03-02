<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer;

class SanitizeIssue
{
    public function __construct(
        public readonly Severity $severity,
        public readonly string $message,
        public readonly string $ruleName,
        public readonly bool $repaired = false,
        public readonly array $details = [],
    ) {
    }
}
