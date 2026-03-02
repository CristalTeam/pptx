<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\Rules\UniqueRIdRule;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class UniqueRIdRuleTest extends TestCase
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
    public function it_detects_duplicate_rids_in_presentation_xml(): void
    {
        // Build a minimal PPTX with duplicate rId14 in presentation.xml
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // presentation.xml with rId14 used in BOTH sldIdLst AND custDataLst
        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>
            <p:sldIdLst>
                <p:sldId id="256" r:id="rId2"/>
                <p:sldId id="257" r:id="rId14"/>
            </p:sldIdLst>
            <p:custDataLst><p:tags r:id="rId14"/></p:custDataLst>
        </p:presentation>';

        $presentationRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
            <Relationship Id="rId14" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide2.xml"/>
            <Relationship Id="rId29" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tags" Target="tags/tag1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presentationRels);
        $zip->close();

        $zip->open($this->tmpFile);

        $rule = new UniqueRIdRule();
        $issues = $rule->detect($zip);

        $this->assertNotEmpty($issues, 'Should detect duplicate rId14');
        $this->assertSame(Severity::CRITICAL, $issues[0]->severity);
        $this->assertStringContainsString('rId14', $issues[0]->message);

        $zip->close();
    }

    /** @test */
    public function it_repairs_duplicate_rids(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>
            <p:sldIdLst>
                <p:sldId id="256" r:id="rId2"/>
                <p:sldId id="257" r:id="rId14"/>
            </p:sldIdLst>
            <p:custDataLst><p:tags r:id="rId14"/></p:custDataLst>
        </p:presentation>';

        $presentationRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
            <Relationship Id="rId14" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide2.xml"/>
            <Relationship Id="rId29" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tags" Target="tags/tag1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presentationRels);
        $zip->close();

        $zip->open($this->tmpFile);

        $rule = new UniqueRIdRule();
        $issues = $rule->detect($zip);
        $rule->repair($zip, $issues);

        // Re-detect: should be clean now
        $newIssues = $rule->detect($zip);
        $this->assertEmpty($newIssues, 'After repair, no duplicate rIds should remain');

        // Verify custDataLst now uses the correct rId (rId29 for tags)
        $xml = $zip->getFromName('ppt/presentation.xml');
        $this->assertStringNotContainsString('custDataLst><p:tags r:id="rId14"', $xml);

        $zip->close();
    }

    /** @test */
    public function it_passes_when_no_duplicates(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>
            <p:sldIdLst><p:sldId id="256" r:id="rId2"/></p:sldIdLst>
            <p:custDataLst><p:tags r:id="rId3"/></p:custDataLst>
        </p:presentation>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->close();

        $zip->open($this->tmpFile);

        $rule = new UniqueRIdRule();
        $issues = $rule->detect($zip);

        $this->assertEmpty($issues);

        $zip->close();
    }
}
