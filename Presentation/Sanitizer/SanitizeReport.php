<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer;

class SanitizeReport
{
    /** @var SanitizeIssue[] */
    private array $issues = [];

    public function addIssue(SanitizeIssue $issue): void
    {
        $this->issues[] = $issue;
    }

    /** @param SanitizeIssue[] $issues */
    public function addIssues(array $issues): void
    {
        foreach ($issues as $issue) {
            $this->addIssue($issue);
        }
    }

    public function hasIssues(): bool
    {
        return !empty($this->issues);
    }

    /** @return SanitizeIssue[] */
    public function getIssues(): array
    {
        return $this->issues;
    }

    public function countBySeverity(Severity $severity): int
    {
        return count(array_filter($this->issues, fn(SanitizeIssue $i) => $i->severity === $severity));
    }
}
