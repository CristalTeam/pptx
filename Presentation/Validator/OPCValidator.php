<?php

declare(strict_types=1);

namespace Cristal\Presentation\Validator;

use ZipArchive;
use SimpleXMLElement;

/**
 * OPC (Open Packaging Conventions) validator for PPTX files.
 *
 * Validates compliance with ECMA-376 Part 2 (Open Packaging Conventions).
 * Critical for detecting issues after merge operations that could cause
 * PowerPoint to trigger auto-repair mode.
 */
class OPCValidator
{
    /**
     * Severity levels for errors.
     */
    public const SEVERITY_CRITICAL = 'CRITICAL'; // Will cause PowerPoint repair
    public const SEVERITY_HIGH = 'HIGH';         // Likely to cause issues
    public const SEVERITY_MEDIUM = 'MEDIUM';     // May cause issues
    public const SEVERITY_LOW = 'LOW';           // Cosmetic/informational

    /**
     * Validate a PPTX file for OPC compliance.
     *
     * @param string $pptxPath Path to the PPTX file
     * @return array Validation report with errors
     */
    public function validate(string $pptxPath): array
    {
        $errors = [];
        $zip = new ZipArchive();

        if ($zip->open($pptxPath) !== true) {
            return [
                'valid' => false,
                'errors' => [['severity' => self::SEVERITY_CRITICAL, 'message' => 'Cannot open PPTX file as ZIP archive']],
            ];
        }

        // Rule 1: All .rels Targets must exist
        $errors = array_merge($errors, $this->validateRelsTargets($zip));

        // Rule 2: No duplicate PartNames
        $errors = array_merge($errors, $this->validateNoDuplicateParts($zip));

        // Rule 3: All parts must have Content-Type override or default
        $errors = array_merge($errors, $this->validateContentTypes($zip));

        // Rule 4: No duplicate IDs (slides, masters, layouts)
        $errors = array_merge($errors, $this->validateUniqueIds($zip));

        // Rule 5: All rIds in XMLs must be declared in corresponding .rels
        $errors = array_merge($errors, $this->validateRIdConsistency($zip));

        // Rule 6: No orphaned media files
        $orphanedMedia = $this->findOrphanedMedia($zip);
        if (!empty($orphanedMedia)) {
            $errors[] = [
                'severity' => self::SEVERITY_HIGH,
                'type' => 'ORPHANED_MEDIA',
                'message' => 'Found ' . count($orphanedMedia) . ' orphaned media files (not referenced in any .rels)',
                'files' => array_slice($orphanedMedia, 0, 10), // First 10 for brevity
                'total_orphans' => count($orphanedMedia),
            ];
        }

        $zip->close();

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'total_errors' => count($errors),
        ];
    }

    /**
     * Validate that all Relationship Targets exist in the ZIP.
     *
     * ECMA-376 Part 2 Section 9.3: Each internal relationship must point to an existing part.
     *
     * @param ZipArchive $zip
     * @return array Errors found
     */
    protected function validateRelsTargets(ZipArchive $zip): array
    {
        $errors = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            if (!str_ends_with($filename, '.rels')) {
                continue;
            }

            $xml = $zip->getFromIndex($i);
            if ($xml === false) {
                continue;
            }

            $dom = @simplexml_load_string($xml);
            if ($dom === false) {
                $errors[] = [
                    'severity' => self::SEVERITY_CRITICAL,
                    'type' => 'MALFORMED_RELS',
                    'file' => $filename,
                    'message' => "Malformed .rels file: $filename",
                ];
                continue;
            }

            $parentDir = dirname(dirname($filename)); // e.g., ppt/slides from ppt/slides/_rels/slide1.xml.rels

            $dom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
            $relationships = $dom->xpath('//r:Relationship');

            foreach ($relationships as $rel) {
                $target = (string) $rel['Target'];
                $rid = (string) $rel['Id'];
                $targetMode = (string) ($rel['TargetMode'] ?? '');

                // Skip external relationships
                if ($targetMode === 'External' || str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
                    continue;
                }

                // Resolve relative path
                $absPath = $this->resolveRelativePath($parentDir, $target);

                if ($zip->locateName($absPath) === false) {
                    $errors[] = [
                        'severity' => self::SEVERITY_CRITICAL,
                        'type' => 'MISSING_RELATIONSHIP_TARGET',
                        'file' => $filename,
                        'rid' => $rid,
                        'target' => $target,
                        'resolved_path' => $absPath,
                        'message' => "Broken relationship: $filename rId=$rid → $absPath (NOT FOUND)",
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Validate that no duplicate PartNames exist in the ZIP.
     *
     * @param ZipArchive $zip
     * @return array Errors found
     */
    protected function validateNoDuplicateParts(ZipArchive $zip): array
    {
        $errors = [];
        $partNames = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $normalizedName = strtolower($filename); // OPC is case-insensitive

            if (isset($partNames[$normalizedName])) {
                $errors[] = [
                    'severity' => self::SEVERITY_CRITICAL,
                    'type' => 'DUPLICATE_PARTNAME',
                    'file' => $filename,
                    'message' => "Duplicate PartName (case-insensitive): $filename",
                ];
            }

            $partNames[$normalizedName] = true;
        }

        return $errors;
    }

    /**
     * Validate that all parts have a Content-Type override or default.
     *
     * @param ZipArchive $zip
     * @return array Errors found
     */
    protected function validateContentTypes(ZipArchive $zip): array
    {
        $errors = [];

        $contentTypesXml = $zip->getFromName('[Content_Types].xml');
        if ($contentTypesXml === false) {
            return [[
                'severity' => self::SEVERITY_CRITICAL,
                'type' => 'MISSING_CONTENT_TYPES',
                'message' => '[Content_Types].xml is missing',
            ]];
        }

        $ctDom = @simplexml_load_string($contentTypesXml);
        if ($ctDom === false) {
            return [[
                'severity' => self::SEVERITY_CRITICAL,
                'type' => 'MALFORMED_CONTENT_TYPES',
                'message' => '[Content_Types].xml is malformed',
            ]];
        }

        // Extract defaults and overrides
        $defaults = [];
        $overrides = [];

        foreach ($ctDom->Default as $default) {
            $defaults[(string) $default['Extension']] = (string) $default['ContentType'];
        }

        foreach ($ctDom->Override as $override) {
            $overrides[(string) $override['PartName']] = (string) $override['ContentType'];
        }

        // Check each part
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Skip directories and special files
            if (str_ends_with($filename, '/') || $filename === '[Content_Types].xml' || str_starts_with($filename, '_rels/')) {
                continue;
            }

            $partName = '/' . ltrim($filename, '/');
            $extension = pathinfo($filename, PATHINFO_EXTENSION);

            // Check if override exists
            if (isset($overrides[$partName])) {
                continue;
            }

            // Check if default exists
            if (isset($defaults[$extension])) {
                continue;
            }

            // Special case: .rels files don't need explicit content-type (OPC spec allows this)
            if (str_ends_with($filename, '.rels')) {
                continue;
            }

            $errors[] = [
                'severity' => self::SEVERITY_HIGH,
                'type' => 'MISSING_CONTENT_TYPE',
                'file' => $filename,
                'message' => "No Content-Type defined for $filename (extension: $extension)",
            ];
        }

        return $errors;
    }

    /**
     * Validate unique IDs in presentation.xml (slides, masters, layouts).
     *
     * @param ZipArchive $zip
     * @return array Errors found
     */
    protected function validateUniqueIds(ZipArchive $zip): array
    {
        $errors = [];

        $presentationXml = $zip->getFromName('ppt/presentation.xml');
        if ($presentationXml === false) {
            return [[
                'severity' => self::SEVERITY_CRITICAL,
                'type' => 'MISSING_PRESENTATION',
                'message' => 'ppt/presentation.xml is missing',
            ]];
        }

        $dom = @simplexml_load_string($presentationXml);
        if ($dom === false) {
            return [[
                'severity' => self::SEVERITY_CRITICAL,
                'type' => 'MALFORMED_PRESENTATION',
                'message' => 'ppt/presentation.xml is malformed',
            ]];
        }

        $dom->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');

        // Check slide IDs
        $slideIds = [];
        $slides = $dom->xpath('//p:sldIdLst/p:sldId');
        foreach ($slides as $slide) {
            $id = (int) $slide['id'];
            if (in_array($id, $slideIds, true)) {
                $errors[] = [
                    'severity' => self::SEVERITY_CRITICAL,
                    'type' => 'DUPLICATE_SLIDE_ID',
                    'id' => $id,
                    'message' => "Duplicate slide ID: $id",
                ];
            }
            $slideIds[] = $id;
        }

        // Check SlideMaster IDs
        $masterIds = [];
        $masters = $dom->xpath('//p:sldMasterIdLst/p:sldMasterId');
        foreach ($masters as $master) {
            $id = (int) $master['id'];
            if (in_array($id, $masterIds, true)) {
                $errors[] = [
                    'severity' => self::SEVERITY_CRITICAL,
                    'type' => 'DUPLICATE_MASTER_ID',
                    'id' => $id,
                    'message' => "Duplicate SlideMaster ID: $id",
                ];
            }
            $masterIds[] = $id;
        }

        return $errors;
    }

    /**
     * Validate that all rIds used in XMLs are declared in corresponding .rels files.
     *
     * @param ZipArchive $zip
     * @return array Errors found
     */
    protected function validateRIdConsistency(ZipArchive $zip): array
    {
        $errors = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $xmlPath = $zip->getNameIndex($i);

            // Only check XML files (not .rels)
            if (!str_ends_with($xmlPath, '.xml') || str_ends_with($xmlPath, '.rels')) {
                continue;
            }

            $xml = $zip->getFromIndex($i);
            if ($xml === false) {
                continue;
            }

            // Extract all rId="rIdXXX" with regex
            if (!preg_match_all('/r:id="(rId\d+)"/i', $xml, $matches)) {
                continue;
            }

            $usedRIds = array_unique($matches[1]);

            // Load corresponding .rels file
            $relsPath = dirname($xmlPath) . '/_rels/' . basename($xmlPath) . '.rels';
            $relsXml = $zip->getFromName($relsPath);

            if ($relsXml === false) {
                // No .rels file, but rIds are used → ERROR
                if (!empty($usedRIds)) {
                    $errors[] = [
                        'severity' => self::SEVERITY_CRITICAL,
                        'type' => 'MISSING_RELS_FILE',
                        'file' => $xmlPath,
                        'expected_rels' => $relsPath,
                        'used_rids' => $usedRIds,
                        'message' => "XML uses rIds but .rels file missing: $relsPath",
                    ];
                }
                continue;
            }

            $relsDom = @simplexml_load_string($relsXml);
            if ($relsDom === false) {
                continue;
            }

            $relsDom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
            $declaredRIds = [];
            $relationships = $relsDom->xpath('//r:Relationship');
            foreach ($relationships as $rel) {
                $declaredRIds[] = (string) $rel['Id'];
            }

            // Check if all used rIds are declared
            foreach ($usedRIds as $rId) {
                if (!in_array($rId, $declaredRIds, true)) {
                    $errors[] = [
                        'severity' => self::SEVERITY_CRITICAL,
                        'type' => 'UNDECLARED_RID',
                        'file' => $xmlPath,
                        'rid' => $rId,
                        'rels_file' => $relsPath,
                        'message' => "XML uses $rId but it's not declared in $relsPath",
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Find orphaned media files (not referenced in any .rels).
     *
     * @param ZipArchive $zip
     * @return array List of orphaned file paths
     */
    protected function findOrphanedMedia(ZipArchive $zip): array
    {
        // Collect all media files
        $mediaFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (str_starts_with($filename, 'ppt/media/')) {
                $mediaFiles[$filename] = true;
            }
        }

        if (empty($mediaFiles)) {
            return [];
        }

        // Collect all referenced media from .rels files
        $referencedMedia = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (!str_ends_with($filename, '.rels')) {
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }

            $relsDir = dirname(dirname($filename));
            if (preg_match_all('/Target="([^"]+)"/', $content, $matches)) {
                foreach ($matches[1] as $target) {
                    $resolvedPath = $this->resolveRelativePath($relsDir, $target);
                    if (str_starts_with($resolvedPath, 'ppt/media/')) {
                        $referencedMedia[$resolvedPath] = true;
                    }
                }
            }
        }

        // Find orphaned media (in mediaFiles but not in referencedMedia)
        $orphaned = [];
        foreach ($mediaFiles as $mediaPath => $unused) {
            if (!isset($referencedMedia[$mediaPath])) {
                $orphaned[] = $mediaPath;
            }
        }

        return $orphaned;
    }

    /**
     * Resolve a relative target path to an absolute path.
     *
     * @param string $baseDir Base directory (e.g., ppt/slides)
     * @param string $target Relative target (e.g., ../media/image1.png)
     * @return string Resolved absolute path
     */
    protected function resolveRelativePath(string $baseDir, string $target): string
    {
        // Skip external targets
        if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
            return $target;
        }

        // Handle absolute paths
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }

        // Resolve relative path
        $parts = explode('/', $baseDir . '/' . $target);
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($resolved);
            } elseif ($part !== '' && $part !== '.') {
                $resolved[] = $part;
            }
        }

        return implode('/', $resolved);
    }
}
