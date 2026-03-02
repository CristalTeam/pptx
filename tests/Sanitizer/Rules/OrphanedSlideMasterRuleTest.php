<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\Rules\OrphanedSlideMasterRule;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class OrphanedSlideMasterRuleTest extends TestCase
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
    public function it_detects_orphaned_slide_masters(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // presentation.xml with 2 masters, but only 1 slide uses master1's layout
        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst>
                <p:sldMasterId id="2147483648" r:id="rId1"/>
                <p:sldMasterId id="2147483649" r:id="rId2"/>
            </p:sldMasterIdLst>
            <p:sldIdLst><p:sldId id="256" r:id="rId3"/></p:sldIdLst>
        </p:presentation>';

        $presRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster2.xml"/>
            <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
        </Relationships>';

        // slide1 uses slideLayout1 which references slideMaster1
        $slide1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
        </Relationships>';

        $layout1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
        </Relationships>';

        // slideMaster2 has a layout (slideLayout2) but NO slide uses it
        $master2Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout2.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme2.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presRels);
        $zip->addFromString('ppt/slides/slide1.xml', '<xml/>');
        $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', $slide1Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<xml/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $layout1Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout2.xml', '<xml/>');
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<xml/>');
        $zip->addFromString('ppt/slideMasters/slideMaster2.xml', '<xml/>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster2.xml.rels', $master2Rels);
        $zip->addFromString('ppt/theme/theme2.xml', '<xml/>');
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new OrphanedSlideMasterRule();
        $issues = $rule->detect($zip);
        $zip->close();

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('slideMaster2', $issues[0]->message);
    }

    /** @test */
    public function it_passes_when_all_masters_used(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst>
                <p:sldMasterId id="2147483648" r:id="rId1"/>
            </p:sldMasterIdLst>
            <p:sldIdLst><p:sldId id="256" r:id="rId2"/></p:sldIdLst>
        </p:presentation>';

        $presRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
        </Relationships>';

        $slide1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
        </Relationships>';

        $layout1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presRels);
        $zip->addFromString('ppt/slides/slide1.xml', '<xml/>');
        $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', $slide1Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<xml/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $layout1Rels);
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<xml/>');
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new OrphanedSlideMasterRule();
        $issues = $rule->detect($zip);
        $zip->close();

        $this->assertEmpty($issues);
    }
}
