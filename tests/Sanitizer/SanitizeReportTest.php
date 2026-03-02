<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer;

use Cristal\Presentation\Sanitizer\SanitizeIssue;
use Cristal\Presentation\Sanitizer\SanitizeReport;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;

class SanitizeReportTest extends TestCase
{
    /** @test */
    public function it_starts_empty(): void
    {
        $report = new SanitizeReport();
        $this->assertFalse($report->hasIssues());
        $this->assertEmpty($report->getIssues());
        $this->assertSame(0, $report->countBySeverity(Severity::CRITICAL));
    }

    /** @test */
    public function it_tracks_issues(): void
    {
        $report = new SanitizeReport();
        $issue = new SanitizeIssue(Severity::CRITICAL, 'Duplicate rId', 'UniqueRIdRule', true);

        $report->addIssue($issue);

        $this->assertTrue($report->hasIssues());
        $this->assertCount(1, $report->getIssues());
        $this->assertSame(1, $report->countBySeverity(Severity::CRITICAL));
        $this->assertSame(0, $report->countBySeverity(Severity::WARNING));
    }
}
