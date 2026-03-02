<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer;

use ZipArchive;

class PPTXSanitizer
{
    /** @var SanitizeRule[] */
    private array $rules;

    /** @param SanitizeRule[] $rules */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function sanitize(ZipArchive $archive): SanitizeReport
    {
        $report = new SanitizeReport();

        foreach ($this->rules as $rule) {
            $issues = $rule->detect($archive);

            if (empty($issues)) {
                continue;
            }

            $rule->repair($archive, $issues);

            // Mark all issues as repaired
            foreach ($issues as $issue) {
                $report->addIssue(new SanitizeIssue(
                    $issue->severity,
                    $issue->message,
                    $issue->ruleName,
                    repaired: true,
                    details: $issue->details,
                ));
            }
        }

        return $report;
    }

    /** @return SanitizeRule[] */
    public function getRules(): array
    {
        return $this->rules;
    }
}
