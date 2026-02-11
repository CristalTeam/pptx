<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests;

use ZipArchive;
use SimpleXMLElement;

/**
 * Comprehensive PPTX validation tests.
 *
 * These tests verify that merged PPTX files are structurally valid
 * and will open in PowerPoint without triggering repair mode.
 */
class PPTXValidationTest extends TestCase
{
    /**
     * @test
     * Test that the repaired file passes all validation (baseline)
     */
    public function repaired_file_passes_validation(): void
    {
        $result = $this->validatePptxStructure(self::TMP_PATH . '/merge_repaired.pptx');

        $this->assertTrue(
            $result['valid'],
            'Repaired file should pass validation. Errors: ' . json_encode($result['errors'], JSON_PRETTY_PRINT)
        );
    }

    /**
     * @test
     * Test that the merged file passes validation (confirms the fix works)
     */
    public function merged_file_passes_validation(): void
    {
        $result = $this->validatePptxStructure(self::TMP_PATH . '/merge.pptx');

        // Output errors for debugging if validation fails
        if (!$result['valid']) {
            echo "\nValidation errors found:\n";
            foreach ($result['errors'] as $error) {
                echo "- [{$error['severity']}] {$error['type']}: {$error['message']}\n";
            }
        }

        $this->assertTrue(
            $result['valid'],
            'Merged file should pass validation. Errors: ' . json_encode($result['errors'], JSON_PRETTY_PRINT)
        );
    }

    /**
     * Comprehensive PPTX structure validation.
     *
     * @param string $pptxPath Path to the PPTX file
     * @return array ['valid' => bool, 'errors' => array]
     */
    protected function validatePptxStructure(string $pptxPath): array
    {
        $errors = [];
        $zip = new ZipArchive();

        if ($zip->open($pptxPath) !== true) {
            return [
                'valid' => false,
                'errors' => [['severity' => 'CRITICAL', 'type' => 'FILE_ERROR', 'message' => 'Cannot open file']]
            ];
        }

        // Rule 1: All slideMasters in sldMasterIdLst must exist
        $errors = array_merge($errors, $this->validateSlideMasterReferences($zip));

        // Rule 2: All slideLayouts must reference existing slideMasters
        $errors = array_merge($errors, $this->validateSlideLayoutToMasterReferences($zip));

        // Rule 3: slideMasters referenced by layouts should be in sldMasterIdLst
        $errors = array_merge($errors, $this->validateLayoutMastersInPresentation($zip));

        // Rule 4: All notesSlides must reference existing notesMaster
        $errors = array_merge($errors, $this->validateNoteSlideReferences($zip));

        // Rule 5: Presentation rIds must be consistent with actual targets
        $errors = array_merge($errors, $this->validatePresentationRelsConsistency($zip));

        // Rule 6: No duplicate content with different paths (indicates deduplication failure)
        $errors = array_merge($errors, $this->validateNoRedundantMasters($zip));

        // Rule 7: rId ordering should follow OPC conventions
        $errors = array_merge($errors, $this->validateRIdOrdering($zip));

        // Rule 8: Bidirectional master-layout consistency (CRITICAL)
        $errors = array_merge($errors, $this->validateMasterLayoutBidirectional($zip));

        // Rule 9: Section consistency - all slides must be in a section when sectionLst exists
        $errors = array_merge($errors, $this->validateSectionConsistency($zip));

        // Rule 10: SlideMaster sldLayoutIdLst consistency
        $errors = array_merge($errors, $this->validateSldLayoutIdLstConsistency($zip));

        $zip->close();

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate that all slideMasters in sldMasterIdLst exist in the archive.
     */
    protected function validateSlideMasterReferences(ZipArchive $zip): array
    {
        $errors = [];

        $presContent = $zip->getFromName('ppt/presentation.xml');
        $relsContent = $zip->getFromName('ppt/_rels/presentation.xml.rels');

        if (!$presContent || !$relsContent) {
            return [['severity' => 'CRITICAL', 'type' => 'MISSING_FILE', 'message' => 'Missing presentation.xml or its rels']];
        }

        $presXml = simplexml_load_string($presContent);
        $presXml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $presXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $relsXml = simplexml_load_string($relsContent);

        // Build rId -> target mapping
        $rIdToTarget = [];
        foreach ($relsXml->Relationship as $rel) {
            $rIdToTarget[(string)$rel['Id']] = (string)$rel['Target'];
        }

        // Check each sldMasterId
        $masters = $presXml->xpath('//p:sldMasterIdLst/p:sldMasterId');
        foreach ($masters as $master) {
            $rId = (string)$master->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;

            if (!isset($rIdToTarget[$rId])) {
                $errors[] = [
                    'severity' => 'CRITICAL',
                    'type' => 'BROKEN_MASTER_REF',
                    'message' => "sldMasterId references non-existent rId: $rId"
                ];
                continue;
            }

            $target = 'ppt/' . $rIdToTarget[$rId];
            if ($zip->locateName($target) === false) {
                $errors[] = [
                    'severity' => 'CRITICAL',
                    'type' => 'MISSING_MASTER',
                    'message' => "SlideMaster file missing: $target (rId=$rId)"
                ];
            }
        }

        return $errors;
    }

    /**
     * Validate that all slideLayouts reference existing slideMasters.
     */
    protected function validateSlideLayoutToMasterReferences(ZipArchive $zip): array
    {
        $errors = [];

        // Find all slideLayout .rels files
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (!preg_match('#ppt/slideLayouts/_rels/slideLayout(\d+)\.xml\.rels#', $filename, $matches)) {
                continue;
            }

            $layoutNum = $matches[1];
            $content = $zip->getFromName($filename);
            $xml = simplexml_load_string($content);

            foreach ($xml->Relationship as $rel) {
                $type = basename((string)$rel['Type']);
                if ($type !== 'slideMaster') {
                    continue;
                }

                $target = (string)$rel['Target'];
                // Resolve relative path
                $fullPath = 'ppt/slideLayouts/' . $target;
                $fullPath = $this->normalizePath($fullPath);

                if ($zip->locateName($fullPath) === false) {
                    $errors[] = [
                        'severity' => 'CRITICAL',
                        'type' => 'LAYOUT_MISSING_MASTER',
                        'message' => "slideLayout$layoutNum references non-existent slideMaster: $fullPath"
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Validate that slideMasters referenced by layouts are in presentation's sldMasterIdLst.
     */
    protected function validateLayoutMastersInPresentation(ZipArchive $zip): array
    {
        $errors = [];

        // Get list of slideMasters declared in presentation
        $presContent = $zip->getFromName('ppt/presentation.xml');
        $relsContent = $zip->getFromName('ppt/_rels/presentation.xml.rels');

        if (!$presContent || !$relsContent) {
            return [];
        }

        $presXml = simplexml_load_string($presContent);
        $presXml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $presXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $relsXml = simplexml_load_string($relsContent);

        // Build list of declared slideMasters
        $declaredMasters = [];
        $rIdToTarget = [];
        foreach ($relsXml->Relationship as $rel) {
            $rIdToTarget[(string)$rel['Id']] = (string)$rel['Target'];
        }

        $masterIds = $presXml->xpath('//p:sldMasterIdLst/p:sldMasterId');
        foreach ($masterIds as $master) {
            $rId = (string)$master->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            if (isset($rIdToTarget[$rId])) {
                $declaredMasters[] = $rIdToTarget[$rId];
            }
        }

        // Check each slideLayout's master reference
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (!preg_match('#ppt/slideLayouts/_rels/slideLayout(\d+)\.xml\.rels#', $filename, $matches)) {
                continue;
            }

            $layoutNum = $matches[1];
            $content = $zip->getFromName($filename);
            $xml = simplexml_load_string($content);

            foreach ($xml->Relationship as $rel) {
                $type = basename((string)$rel['Type']);
                if ($type !== 'slideMaster') {
                    continue;
                }

                $target = (string)$rel['Target'];
                // Convert relative to presentation-relative path
                $masterPath = str_replace('../', '', $target);

                if (!in_array($masterPath, $declaredMasters)) {
                    $errors[] = [
                        'severity' => 'HIGH',
                        'type' => 'UNDECLARED_MASTER',
                        'message' => "slideLayout$layoutNum references slideMaster not in sldMasterIdLst: $masterPath"
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Validate that all notesSlides reference an existing notesMaster.
     */
    protected function validateNoteSlideReferences(ZipArchive $zip): array
    {
        $errors = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (!preg_match('#ppt/notesSlides/_rels/notesSlide(\d+)\.xml\.rels#', $filename, $matches)) {
                continue;
            }

            $noteNum = $matches[1];
            $content = $zip->getFromName($filename);
            $xml = simplexml_load_string($content);

            foreach ($xml->Relationship as $rel) {
                $type = basename((string)$rel['Type']);
                if ($type !== 'notesMaster') {
                    continue;
                }

                $target = (string)$rel['Target'];
                $fullPath = 'ppt/notesSlides/' . $target;
                $fullPath = $this->normalizePath($fullPath);

                if ($zip->locateName($fullPath) === false) {
                    $errors[] = [
                        'severity' => 'CRITICAL',
                        'type' => 'MISSING_NOTES_MASTER',
                        'message' => "notesSlide$noteNum references non-existent notesMaster: $fullPath"
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Validate presentation.xml.rels consistency.
     */
    protected function validatePresentationRelsConsistency(ZipArchive $zip): array
    {
        $errors = [];

        $relsContent = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        if (!$relsContent) {
            return [['severity' => 'CRITICAL', 'type' => 'MISSING_FILE', 'message' => 'Missing presentation.xml.rels']];
        }

        $xml = simplexml_load_string($relsContent);

        foreach ($xml->Relationship as $rel) {
            $rId = (string)$rel['Id'];
            $target = (string)$rel['Target'];
            $type = basename((string)$rel['Type']);

            // Skip external targets
            if ((string)$rel['TargetMode'] === 'External') {
                continue;
            }

            $fullPath = 'ppt/' . $target;

            if ($zip->locateName($fullPath) === false) {
                $errors[] = [
                    'severity' => 'CRITICAL',
                    'type' => 'BROKEN_PRES_REL',
                    'message' => "presentation.xml.rels $rId references missing file: $fullPath (type: $type)"
                ];
            }
        }

        return $errors;
    }

    /**
     * Check for redundant slideMasters (same content, different paths).
     * This indicates a deduplication failure during merge.
     */
    protected function validateNoRedundantMasters(ZipArchive $zip): array
    {
        $errors = [];
        $masterHashes = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (!preg_match('#ppt/slideMasters/slideMaster(\d+)\.xml$#', $filename)) {
                continue;
            }

            $content = $zip->getFromName($filename);
            // Create a normalized hash (remove whitespace differences)
            $normalized = preg_replace('/\s+/', ' ', $content);
            $hash = md5($normalized);

            if (isset($masterHashes[$hash])) {
                $errors[] = [
                    'severity' => 'HIGH',
                    'type' => 'REDUNDANT_MASTER',
                    'message' => "Redundant slideMasters detected: $filename has same content as {$masterHashes[$hash]}"
                ];
            } else {
                $masterHashes[$hash] = $filename;
            }
        }

        return $errors;
    }

    /**
     * Validate rId ordering follows OPC conventions.
     * SlideMasters should come before slides in rId ordering.
     */
    protected function validateRIdOrdering(ZipArchive $zip): array
    {
        $errors = [];

        $relsContent = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        if (!$relsContent) {
            return [];
        }

        $xml = simplexml_load_string($relsContent);

        $masterRIds = [];
        $slideRIds = [];

        foreach ($xml->Relationship as $rel) {
            $rId = (string)$rel['Id'];
            $type = basename((string)$rel['Type']);
            $num = (int)str_replace('rId', '', $rId);

            if ($type === 'slideMaster') {
                $masterRIds[] = $num;
            } elseif ($type === 'slide') {
                $slideRIds[] = $num;
            }
        }

        if (!empty($masterRIds) && !empty($slideRIds)) {
            $maxMasterRId = max($masterRIds);
            $minSlideRId = min($slideRIds);

            // Slides should come AFTER masters (higher rId numbers)
            // But the first slide should be immediately after the last master
            if ($minSlideRId < $maxMasterRId) {
                $errors[] = [
                    'severity' => 'HIGH',
                    'type' => 'RID_ORDER_VIOLATION',
                    'message' => "Slide rIds ($minSlideRId) should not be less than master rIds ($maxMasterRId)"
                ];
            }
        }

        return $errors;
    }

    /**
     * Validate bidirectional consistency between slideMasters and slideLayouts.
     *
     * A slideLayout should only be claimed by the slideMaster it references.
     * If slideMaster4 claims slideLayout1, but slideLayout1 references slideMaster1,
     * this is a CRITICAL error that will cause PowerPoint repair.
     */
    protected function validateMasterLayoutBidirectional(ZipArchive $zip): array
    {
        $errors = [];

        // Step 1: Build layout -> master mapping (from layout's perspective)
        $layoutToMaster = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (!preg_match('#ppt/slideLayouts/_rels/(slideLayout\d+)\.xml\.rels#', $filename, $matches)) {
                continue;
            }

            $layoutName = $matches[1];
            $content = $zip->getFromName($filename);
            $xml = simplexml_load_string($content);

            foreach ($xml->Relationship as $rel) {
                $type = basename((string)$rel['Type']);
                if ($type === 'slideMaster') {
                    $masterName = basename((string)$rel['Target'], '.xml');
                    $layoutToMaster[$layoutName] = $masterName;
                }
            }
        }

        // Step 2: Check each master's claimed layouts
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (!preg_match('#ppt/slideMasters/_rels/(slideMaster\d+)\.xml\.rels#', $filename, $matches)) {
                continue;
            }

            $masterName = $matches[1];
            $content = $zip->getFromName($filename);
            $xml = simplexml_load_string($content);

            foreach ($xml->Relationship as $rel) {
                $type = basename((string)$rel['Type']);
                if ($type !== 'slideLayout') {
                    continue;
                }

                $layoutName = basename((string)$rel['Target'], '.xml');
                $actualMaster = $layoutToMaster[$layoutName] ?? null;

                if ($actualMaster !== null && $actualMaster !== $masterName) {
                    $errors[] = [
                        'severity' => 'CRITICAL',
                        'type' => 'MASTER_LAYOUT_CONFLICT',
                        'message' => "$masterName claims $layoutName, but $layoutName references $actualMaster"
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Validate that sldLayoutIdLst in each SlideMaster matches its .rels file.
     *
     * Each SlideMaster has a sldLayoutIdLst element that lists layout rIds.
     * These must match the slideLayout relationships in the .rels file.
     * Duplicates or mismatches cause PowerPoint corruption.
     */
    protected function validateSldLayoutIdLstConsistency(ZipArchive $zip): array
    {
        $errors = [];

        for ($i = 1; $i <= 10; $i++) {
            $masterPath = "ppt/slideMasters/slideMaster$i.xml";
            $relsPath = "ppt/slideMasters/_rels/slideMaster$i.xml.rels";

            $masterContent = $zip->getFromName($masterPath);
            $relsContent = $zip->getFromName($relsPath);

            if ($masterContent === false || $relsContent === false) {
                continue;
            }

            // Extract rIds from sldLayoutIdLst in master XML
            $xmlRIds = [];
            if (preg_match('/<p:sldLayoutIdLst>(.*?)<\/p:sldLayoutIdLst>/s', $masterContent, $match)) {
                preg_match_all('/r:id="([^"]+)"/', $match[1], $rIdMatches);
                $xmlRIds = $rIdMatches[1];
            }

            // Extract layout rIds from .rels
            $relsXml = simplexml_load_string($relsContent);
            $relsLayoutRIds = [];
            foreach ($relsXml->Relationship as $rel) {
                $type = basename((string)$rel['Type']);
                if ($type === 'slideLayout') {
                    $relsLayoutRIds[] = (string)$rel['Id'];
                }
            }

            // Check for duplicates in XML
            $uniqueXmlRIds = array_unique($xmlRIds);
            if (count($uniqueXmlRIds) !== count($xmlRIds)) {
                $duplicates = array_diff_assoc($xmlRIds, $uniqueXmlRIds);
                $errors[] = [
                    'severity' => 'CRITICAL',
                    'type' => 'SLDLAYOUTIDLST_DUPLICATES',
                    'message' => "slideMaster$i has duplicate rIds in sldLayoutIdLst: " . implode(', ', $duplicates)
                ];
            }

            // Check that XML rIds match rels layout rIds
            sort($uniqueXmlRIds);
            sort($relsLayoutRIds);

            if ($uniqueXmlRIds !== $relsLayoutRIds) {
                $errors[] = [
                    'severity' => 'CRITICAL',
                    'type' => 'SLDLAYOUTIDLST_MISMATCH',
                    'message' => "slideMaster$i sldLayoutIdLst doesn't match .rels. " .
                        "XML: [" . implode(', ', $uniqueXmlRIds) . "], " .
                        "Rels: [" . implode(', ', $relsLayoutRIds) . "]"
                ];
            }
        }

        return $errors;
    }

    /**
     * Validate section consistency.
     *
     * When sectionLst exists in presentation.xml, ALL slides must be in a section.
     * Slides not in any section will cause PowerPoint to trigger repair mode.
     */
    protected function validateSectionConsistency(ZipArchive $zip): array
    {
        $errors = [];

        $presContent = $zip->getFromName('ppt/presentation.xml');
        if (!$presContent) {
            return [];
        }

        // Check if sectionLst exists
        if (!preg_match('/<p14:sectionLst[^>]*>/s', $presContent)) {
            // No sections - no validation needed
            return [];
        }

        $xml = simplexml_load_string($presContent);
        $xml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');

        // Get all slide IDs from sldIdLst
        $slides = $xml->xpath('//p:sldIdLst/p:sldId');
        $allSlideIds = [];
        foreach ($slides as $slide) {
            $allSlideIds[] = (string)$slide['id'];
        }

        // Get all slide IDs that are in sections
        $slidesInSections = [];
        if (preg_match_all('/<p14:sldId id="(\d+)"/', $presContent, $matches)) {
            $slidesInSections = $matches[1];
        }

        // Find orphaned slides
        $orphanedSlides = array_diff($allSlideIds, $slidesInSections);

        if (!empty($orphanedSlides)) {
            $errors[] = [
                'severity' => 'CRITICAL',
                'type' => 'ORPHANED_SLIDES_IN_SECTIONS',
                'message' => 'Slides not in any section: ' . implode(', ', $orphanedSlides) .
                    '. When sectionLst exists, ALL slides must be in a section.'
            ];
        }

        return $errors;
    }

    /**
     * Normalize a path by resolving .. references.
     */
    protected function normalizePath(string $path): string
    {
        $parts = explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($normalized);
            } elseif ($part !== '.' && $part !== '') {
                $normalized[] = $part;
            }
        }

        return implode('/', $normalized);
    }
}
