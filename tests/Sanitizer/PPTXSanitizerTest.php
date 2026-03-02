<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer;

use Cristal\Presentation\Sanitizer\PPTXSanitizer;
use Cristal\Presentation\Sanitizer\SanitizeIssue;
use Cristal\Presentation\Sanitizer\SanitizeRule;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class PPTXSanitizerTest extends TestCase
{
    /** @test */
    public function it_returns_empty_report_with_no_rules(): void
    {
        $sanitizer = new PPTXSanitizer([]);
        $archive = $this->createMock(ZipArchive::class);

        $report = $sanitizer->sanitize($archive);

        $this->assertFalse($report->hasIssues());
    }

    /** @test */
    public function it_detects_and_repairs_issues(): void
    {
        $issue = new SanitizeIssue(Severity::CRITICAL, 'test issue', 'TestRule');

        $rule = $this->createMock(SanitizeRule::class);
        $rule->method('detect')->willReturn([$issue]);
        $rule->expects($this->once())->method('repair');

        $sanitizer = new PPTXSanitizer([$rule]);
        $archive = $this->createMock(ZipArchive::class);

        $report = $sanitizer->sanitize($archive);

        $this->assertTrue($report->hasIssues());
        $this->assertCount(1, $report->getIssues());
        $this->assertTrue($report->getIssues()[0]->repaired);
    }

    /** @test */
    public function it_skips_repair_when_no_issues(): void
    {
        $rule = $this->createMock(SanitizeRule::class);
        $rule->method('detect')->willReturn([]);
        $rule->expects($this->never())->method('repair');

        $sanitizer = new PPTXSanitizer([$rule]);
        $archive = $this->createMock(ZipArchive::class);

        $report = $sanitizer->sanitize($archive);

        $this->assertFalse($report->hasIssues());
    }
}
