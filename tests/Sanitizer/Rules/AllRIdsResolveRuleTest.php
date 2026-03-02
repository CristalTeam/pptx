<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\Rules\AllRIdsResolveRule;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class AllRIdsResolveRuleTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pptx_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    /** @test */
    public function it_detects_rids_not_in_rels(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // presentation.xml references rId99 which doesn't exist in .rels
        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldIdLst><p:sldId id="256" r:id="rId1"/><p:sldId id="257" r:id="rId99"/></p:sldIdLst>
        </p:presentation>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $rels);
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new AllRIdsResolveRule();
        $issues = $rule->detect($zip);
        $zip->close();

        $this->assertNotEmpty($issues);
        $this->assertSame(Severity::HIGH, $issues[0]->severity);
        $this->assertStringContainsString('rId99', $issues[0]->message);
    }

    /** @test */
    public function it_passes_when_all_rids_resolve(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst>
        </p:presentation>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $rels);
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new AllRIdsResolveRule();
        $issues = $rule->detect($zip);
        $zip->close();

        $this->assertEmpty($issues);
    }
}
