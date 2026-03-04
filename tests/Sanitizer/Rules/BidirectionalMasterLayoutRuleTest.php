<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\Rules\BidirectionalMasterLayoutRule;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class BidirectionalMasterLayoutRuleTest extends TestCase
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
    public function it_detects_master_claiming_layout_that_points_to_different_master(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

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

        // slideMaster1 claims layout1 AND layout2
        $master1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout2.xml"/>
            <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>
        </Relationships>';

        // slideMaster2 claims layout2
        $master2Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout2.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme2.xml"/>
        </Relationships>';

        // layout1 points to master1 (correct)
        $layout1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
        </Relationships>';

        // layout2 points to master2 (NOT master1!)
        $layout2Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster2.xml"/>
        </Relationships>';

        $master1Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                     xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldLayoutIdLst>
                <p:sldLayoutId id="2147483650" r:id="rId1"/>
                <p:sldLayoutId id="2147483651" r:id="rId2"/>
            </p:sldLayoutIdLst>
        </p:sldMaster>';

        $master2Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                     xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldLayoutIdLst>
                <p:sldLayoutId id="2147483652" r:id="rId1"/>
            </p:sldLayoutIdLst>
        </p:sldMaster>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presRels);
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $master1Xml);
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $master1Rels);
        $zip->addFromString('ppt/slideMasters/slideMaster2.xml', $master2Xml);
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster2.xml.rels', $master2Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<xml/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $layout1Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout2.xml', '<xml/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout2.xml.rels', $layout2Rels);
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new BidirectionalMasterLayoutRule();
        $issues = $rule->detect($zip);
        $zip->close();

        $this->assertNotEmpty($issues, 'Should detect that master1 claims layout2 which points to master2');
        $this->assertSame(Severity::CRITICAL, $issues[0]->severity);
        $this->assertStringContainsString('slideLayout2', $issues[0]->message);
        $this->assertStringContainsString('slideMaster1', $issues[0]->message);
    }

    /** @test */
    public function it_repairs_by_removing_mismatched_layouts_from_master_rels(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

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

        // master1 claims layout1 (correct) AND layout2 (wrong — layout2 points to master2)
        $master1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout2.xml"/>
            <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>
        </Relationships>';

        $master2Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout2.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme2.xml"/>
        </Relationships>';

        $layout1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
        </Relationships>';

        $layout2Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster2.xml"/>
        </Relationships>';

        $master1Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                     xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldLayoutIdLst>
                <p:sldLayoutId id="2147483650" r:id="rId1"/>
                <p:sldLayoutId id="2147483651" r:id="rId2"/>
            </p:sldLayoutIdLst>
        </p:sldMaster>';

        $master2Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                     xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldLayoutIdLst>
                <p:sldLayoutId id="2147483652" r:id="rId1"/>
            </p:sldLayoutIdLst>
        </p:sldMaster>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presRels);
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $master1Xml);
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $master1Rels);
        $zip->addFromString('ppt/slideMasters/slideMaster2.xml', $master2Xml);
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster2.xml.rels', $master2Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<xml/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $layout1Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout2.xml', '<xml/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout2.xml.rels', $layout2Rels);
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new BidirectionalMasterLayoutRule();
        $issues = $rule->detect($zip);
        $rule->repair($zip, $issues);

        // Re-detect: should be clean now
        $newIssues = $rule->detect($zip);
        $this->assertEmpty($newIssues, 'After repair, no bidirectional mismatches should remain');

        // Verify master1 .rels no longer claims layout2
        $master1RelsAfter = $zip->getFromName('ppt/slideMasters/_rels/slideMaster1.xml.rels');
        $this->assertStringNotContainsString('slideLayout2', $master1RelsAfter);
        // But still has layout1
        $this->assertStringContainsString('slideLayout1', $master1RelsAfter);

        // Verify master1 sldLayoutIdLst was updated (rId2 entry removed)
        $master1XmlAfter = $zip->getFromName('ppt/slideMasters/slideMaster1.xml');
        $this->assertStringNotContainsString('r:id="rId2"', $master1XmlAfter);

        $zip->close();
    }

    /** @test */
    public function it_passes_when_all_relationships_are_consistent(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>
            <p:sldIdLst><p:sldId id="256" r:id="rId2"/></p:sldIdLst>
        </p:presentation>';

        $presRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
        </Relationships>';

        $master1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>
        </Relationships>';

        $layout1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presRels);
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<p:sldMaster xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $master1Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<xml/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $layout1Rels);
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new BidirectionalMasterLayoutRule();
        $issues = $rule->detect($zip);
        $zip->close();

        $this->assertEmpty($issues);
    }
}
