<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer;

use Cristal\Presentation\PPTX;
use Cristal\Presentation\Sanitizer\PPTXSanitizer;
use Cristal\Presentation\Sanitizer\Rules\AllRIdsResolveRule;
use Cristal\Presentation\Sanitizer\Rules\BidirectionalMasterLayoutRule;
use Cristal\Presentation\Sanitizer\Rules\OrphanedSlideMasterRule;
use Cristal\Presentation\Sanitizer\Rules\UniqueRIdRule;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class PPTXSanitizerIntegrationTest extends TestCase
{
    private function createSanitizer(): PPTXSanitizer
    {
        return new PPTXSanitizer([
            new UniqueRIdRule(),
            new BidirectionalMasterLayoutRule(),
            new AllRIdsResolveRule(),
            new OrphanedSlideMasterRule(),
        ]);
    }

    /**
     * Open a PPTX as a temporary copy to avoid modifying test fixtures.
     * sanitize() calls repair() which writes to the archive.
     */
    private function openAsCopy(string $sourcePath): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'pptx_test_');
        copy($sourcePath, $tmpPath);

        $zip = new ZipArchive();
        $zip->open($tmpPath);

        return [$zip, $tmpPath];
    }

    /** @test */
    public function it_detects_issues_in_propale_failed(): void
    {
        $path = __DIR__ . '/../mock/PropaleFailed.pptx';
        if (!file_exists($path)) {
            $this->markTestSkipped('PropaleFailed.pptx not available');
        }

        [$zip, $tmpPath] = $this->openAsCopy($path);

        $report = $this->createSanitizer()->sanitize($zip);
        $zip->close();
        unlink($tmpPath);

        $this->assertTrue($report->hasIssues(), 'PropaleFailed.pptx should have detectable issues');
        $this->assertGreaterThanOrEqual(1, $report->countBySeverity(Severity::CRITICAL));
    }

    /** @test */
    public function it_repairs_propale_failed_to_be_cleaner(): void
    {
        $sourcePath = __DIR__ . '/../mock/PropaleFailed.pptx';
        if (!file_exists($sourcePath)) {
            $this->markTestSkipped('PropaleFailed.pptx not available');
        }

        [$zip, $tmpPath] = $this->openAsCopy($sourcePath);

        $sanitizer = $this->createSanitizer();
        $report = $sanitizer->sanitize($zip);
        $zip->close();

        $this->assertTrue($report->hasIssues(), 'First pass should detect issues');

        // Re-open and verify no more CRITICAL issues
        $zip = new ZipArchive();
        $zip->open($tmpPath);
        $verifyReport = $sanitizer->sanitize($zip);
        $zip->close();
        unlink($tmpPath);

        $this->assertSame(
            0,
            $verifyReport->countBySeverity(Severity::CRITICAL),
            'After repair, no CRITICAL issues should remain. Remaining: '
            . implode(', ', array_map(fn ($i) => $i->message, $verifyReport->getIssues()))
        );
    }

    /** @test */
    public function it_does_not_flag_propale_repaired(): void
    {
        $path = __DIR__ . '/../mock/PropaleRepaired.pptx';
        if (!file_exists($path)) {
            $this->markTestSkipped('PropaleRepaired.pptx not available');
        }

        [$zip, $tmpPath] = $this->openAsCopy($path);

        $report = $this->createSanitizer()->sanitize($zip);
        $zip->close();
        unlink($tmpPath);

        $this->assertSame(
            0,
            $report->countBySeverity(Severity::CRITICAL),
            'PropaleRepaired.pptx should have no CRITICAL issues'
        );
    }

    /** @test */
    public function it_sanitizes_merge_of_T1_T9_T5(): void
    {
        $t1Path = __DIR__ . '/../mock/T1.pptx';
        $t9Path = __DIR__ . '/../mock/T9.pptx';
        $t5Path = __DIR__ . '/../mock/T5.pptx';

        foreach ([$t1Path, $t9Path, $t5Path] as $path) {
            if (!file_exists($path)) {
                $this->markTestSkipped(basename($path) . ' not available');
            }
        }

        // Merge T1 + T9 + T5
        $pptx = new PPTX($t1Path);
        $t9 = new PPTX($t9Path);
        $t5 = new PPTX($t5Path);

        $pptx->addSlides($t9->getSlides());
        $pptx->addSlides($t5->getSlides());

        $outputPath = __DIR__ . '/../tmp/merge_T1_T9_T5.pptx';
        $pptx->saveAs($outputPath);

        // saveAs() should have auto-repaired any issues
        $saveReport = $pptx->getSanitizeReport();
        $this->assertNotNull($saveReport);

        // Verify the output file is clean
        [$zip, $tmpPath] = $this->openAsCopy($outputPath);

        $verifyReport = $this->createSanitizer()->sanitize($zip);

        // Structural checks
        $presXml = $zip->getFromName('ppt/presentation.xml');
        preg_match_all('/<p:sldId /', $presXml, $slides);
        $this->assertSame(15, count($slides[0]), 'Should have 15 slides (7+1+7)');

        // No duplicate rIds
        preg_match_all('/r:id="(rId\d+)"/', $presXml, $rids);
        $dupes = array_filter(array_count_values($rids[1]), fn ($c) => $c > 1);
        $this->assertEmpty($dupes, 'No duplicate rIds in presentation.xml');

        // No CRITICAL issues
        $criticalIssues = array_filter(
            $verifyReport->getIssues(),
            fn ($i) => $i->severity === Severity::CRITICAL
        );
        $this->assertEmpty(
            $criticalIssues,
            'Output file should have no CRITICAL issues. Found: '
            . implode(', ', array_map(fn ($i) => $i->message, $criticalIssues))
        );

        $zip->close();
        unlink($tmpPath);
    }
}
