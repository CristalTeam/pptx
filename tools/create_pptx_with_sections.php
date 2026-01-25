<?php

require __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\PPTX;

// Create a test PPTX with sections by manually adding section data to presentation.xml
function createPPTXWithSections($sourceFile, $destFile, $sectionsConfig) {
    // Copy source to dest
    copy($sourceFile, $destFile);

    // Open the ZIP
    $zip = new \ZipArchive();
    if (!$zip->open($destFile)) {
        throw new \Exception("Cannot open $destFile");
    }

    // Read presentation.xml
    $presentationXml = $zip->getFromName('ppt/presentation.xml');
    if (!$presentationXml) {
        throw new \Exception("Cannot read presentation.xml");
    }

    $dom = new \SimpleXMLElement($presentationXml);
    $dom->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
    $dom->registerXPathNamespace('p14', 'http://schemas.microsoft.com/office/powerpoint/2010/main');

    // Get all slide IDs
    $slides = $dom->xpath('//p:sldIdLst/p:sldId');
    $slideIds = [];
    foreach ($slides as $slide) {
        $slideIds[] = (string)$slide['id'];
    }

    // Remove existing extLst if any
    $extLst = $dom->xpath('p:extLst');
    if (!empty($extLst)) {
        unset($extLst[0][0]);
    }

    // Create new extLst
    $extLst = $dom->addChild('p:extLst', null);

    // Create ext element for sections
    $ext = $extLst->addChild('ext', null);
    $ext->addAttribute('uri', '{521415D9-36F7-43E2-AB2F-B90AF26B5E84}');

    // Create sectionLst (with p14 namespace)
    $sectionLst = $ext->addChild('p14:sectionLst', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');

    // Add sections
    foreach ($sectionsConfig as $sectionConfig) {
        $section = $sectionLst->addChild('p14:section', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');
        $section->addAttribute('name', $sectionConfig['name']);
        $section->addAttribute('id', $sectionConfig['id']);

        // Add slides to section
        $sldIdLst = $section->addChild('p14:sldIdLst', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');

        foreach ($sectionConfig['slides'] as $slideIndex) {
            if (isset($slideIds[$slideIndex])) {
                $sldId = $sldIdLst->addChild('p14:sldId', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');
                $sldId->addAttribute('id', $slideIds[$slideIndex]);
            }
        }
    }

    // Save back to ZIP
    $zip->deleteName('ppt/presentation.xml');
    $zip->addFromString('ppt/presentation.xml', $dom->asXML());
    $zip->close();

    echo "Created $destFile with sections\n";
}

// Create DEBUT with sections
$sectionsDebut = [
    ['name' => 'Introduction', 'id' => '{11111111-1111-1111-1111-111111111111}', 'slides' => [0, 1, 2]],
    ['name' => 'Main Content', 'id' => '{22222222-2222-2222-2222-222222222222}', 'slides' => [3, 4, 5, 6, 7, 8, 9]],
    ['name' => 'Conclusion', 'id' => '{33333333-3333-3333-3333-333333333333}', 'slides' => [10, 11, 12, 13]]
];

createPPTXWithSections(
    __DIR__ . '/../tests/mock/DEBUT.pptx',
    __DIR__ . '/../tests/tmp/DEBUT_WITH_SECTIONS.pptx',
    $sectionsDebut
);

// Create MILIEU with sections
$sectionsMilieu = [
    ['name' => 'Extra Content', 'id' => '{44444444-4444-4444-4444-444444444444}', 'slides' => [0, 1, 2, 3, 4]],
    ['name' => 'Appendix', 'id' => '{55555555-5555-5555-5555-555555555555}', 'slides' => [5, 6, 7, 8]]
];

createPPTXWithSections(
    __DIR__ . '/../tests/mock/MILIEU.pptx',
    __DIR__ . '/../tests/tmp/MILIEU_WITH_SECTIONS.pptx',
    $sectionsMilieu
);

echo "\nTest files created successfully!\n";
echo "Now you can test merging with sections.\n";
