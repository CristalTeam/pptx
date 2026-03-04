<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\SanitizeIssue;
use Cristal\Presentation\Sanitizer\SanitizeRule;
use Cristal\Presentation\Sanitizer\Severity;
use ZipArchive;

/**
 * Detects SlideMasters that no slide references (via layouts).
 *
 * Trace: slide → slideLayout (via slide .rels) → slideMaster (via layout .rels).
 * If a slideMaster is in sldMasterIdLst but no slide chain reaches it, it's orphaned.
 *
 * Repair: full cleanup — remove the orphaned master from sldMasterIdLst,
 * presentation.xml.rels, its exclusive layouts, exclusive theme,
 * and the master files themselves from the archive + [Content_Types].xml.
 */
class OrphanedSlideMasterRule implements SanitizeRule
{
    public function name(): string
    {
        return 'OrphanedSlideMasterRule';
    }

    public function severity(): Severity
    {
        return Severity::CRITICAL;
    }

    public function detect(ZipArchive $archive): array
    {
        $presXml = $archive->getFromName('ppt/presentation.xml');
        $presRels = $archive->getFromName('ppt/_rels/presentation.xml.rels');
        if ($presXml === false || $presRels === false) {
            return [];
        }

        // 1. Get all declared masters from sldMasterIdLst
        $declaredMasters = $this->getDeclaredMasters($presXml, $presRels);

        // 2. Trace slides → layouts → masters to find which masters are actually used
        $usedMasters = $this->traceUsedMasters($archive, $presXml, $presRels);

        // 3. Find orphaned masters
        $issues = [];
        foreach ($declaredMasters as $rId => $masterPath) {
            if (!in_array($masterPath, $usedMasters, true)) {
                $issues[] = new SanitizeIssue(
                    Severity::CRITICAL,
                    "Orphaned SlideMaster '$masterPath' ($rId) — not used by any slide",
                    $this->name(),
                    details: ['rId' => $rId, 'masterPath' => $masterPath],
                );
            }
        }

        return $issues;
    }

    public function repair(ZipArchive $archive, array $issues): void
    {
        $presXml = $archive->getFromName('ppt/presentation.xml');
        $presRels = $archive->getFromName('ppt/_rels/presentation.xml.rels');
        if ($presXml === false || $presRels === false) {
            return;
        }

        // Build map of ALL master → layouts to determine exclusivity
        $allMasterLayouts = $this->buildMasterLayoutMap($archive, $presRels);
        // Count how many masters claim each layout
        $layoutOwnerCount = [];
        foreach ($allMasterLayouts as $info) {
            foreach ($info['layouts'] as $layoutPath) {
                $layoutOwnerCount[$layoutPath] = ($layoutOwnerCount[$layoutPath] ?? 0) + 1;
            }
        }

        $filesToDelete = [];

        foreach ($issues as $issue) {
            $rId = $issue->details['rId'];
            $masterPath = $issue->details['masterPath'];

            // 1. Remove from sldMasterIdLst in presentation.xml
            $presXml = preg_replace(
                '/<p:sldMasterId[^>]*r:id="' . preg_quote($rId, '/') . '"[^>]*\/?>/',
                '',
                $presXml
            );

            // 2. Remove from presentation.xml.rels
            $presRels = preg_replace(
                '/<Relationship[^>]*Id="' . preg_quote($rId, '/') . '"[^>]*\/?>/',
                '',
                $presRels
            );

            // 3. Collect master's exclusive layouts and theme for deletion
            $masterInfo = $allMasterLayouts[$masterPath] ?? ['layouts' => [], 'theme' => null];

            foreach ($masterInfo['layouts'] as $layoutPath) {
                if (($layoutOwnerCount[$layoutPath] ?? 0) <= 1) {
                    $filesToDelete[] = $layoutPath;
                    $layoutRelsPath = dirname($layoutPath) . '/_rels/' . basename($layoutPath) . '.rels';
                    $filesToDelete[] = $layoutRelsPath;
                }
            }

            if ($masterInfo['theme'] !== null) {
                $filesToDelete[] = $masterInfo['theme'];
            }

            // 4. Collect master files for deletion
            $filesToDelete[] = $masterPath;
            $masterRelsPath = dirname($masterPath) . '/_rels/' . basename($masterPath) . '.rels';
            $filesToDelete[] = $masterRelsPath;
        }

        // 5. Remove Relationship entries from presentation.xml.rels for deleted files
        foreach (array_unique($filesToDelete) as $file) {
            // Convert absolute path to relative Target (e.g. "ppt/theme/theme6.xml" → "theme/theme6.xml")
            $relTarget = str_starts_with($file, 'ppt/') ? substr($file, 4) : '../' . $file;
            $presRels = preg_replace(
                '/<Relationship[^>]*Target="' . preg_quote($relTarget, '/') . '"[^>]*\/?>/',
                '',
                $presRels
            );
        }

        $archive->addFromString('ppt/presentation.xml', $presXml);
        $archive->addFromString('ppt/_rels/presentation.xml.rels', $presRels);

        // 5. Delete collected files from archive
        foreach (array_unique($filesToDelete) as $file) {
            $archive->deleteName($file);
        }

        // 6. Clean [Content_Types].xml
        $this->cleanContentTypes($archive, $filesToDelete);
    }

    /**
     * Build a map of master path → {layouts, theme} from all masters in .rels.
     *
     * @return array<string, array{layouts: string[], theme: string|null}>
     */
    private function buildMasterLayoutMap(ZipArchive $archive, string $presRels): array
    {
        $map = [];
        $relsDom = @simplexml_load_string($presRels);
        if ($relsDom === false) {
            return $map;
        }

        $relsDom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($relsDom->xpath('//r:Relationship') as $rel) {
            if (!str_contains((string) $rel['Type'], 'slideMaster')) {
                continue;
            }

            $masterPath = 'ppt/' . (string) $rel['Target'];
            $masterRelsPath = dirname($masterPath) . '/_rels/' . basename($masterPath) . '.rels';
            $masterRels = $archive->getFromName($masterRelsPath);

            $layouts = [];
            $theme = null;

            if ($masterRels !== false) {
                $mDom = @simplexml_load_string($masterRels);
                if ($mDom !== false) {
                    $mDom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
                    foreach ($mDom->xpath('//r:Relationship') as $mRel) {
                        $target = $this->resolveRelativePath(dirname($masterPath), (string) $mRel['Target']);
                        if (str_contains((string) $mRel['Type'], 'slideLayout')) {
                            $layouts[] = $target;
                        } elseif (str_contains((string) $mRel['Type'], 'theme')) {
                            $theme = $target;
                        }
                    }
                }
            }

            $map[$masterPath] = ['layouts' => $layouts, 'theme' => $theme];
        }

        return $map;
    }

    /**
     * Remove Override entries from [Content_Types].xml for deleted files.
     *
     * @param string[] $deletedFiles
     */
    private function cleanContentTypes(ZipArchive $archive, array $deletedFiles): void
    {
        $ct = $archive->getFromName('[Content_Types].xml');
        if ($ct === false) {
            return;
        }

        foreach (array_unique($deletedFiles) as $file) {
            // Override entries use PartName="/path/to/file.xml"
            $ct = preg_replace(
                '/<Override[^>]*PartName="\/' . preg_quote($file, '/') . '"[^>]*\/?>/',
                '',
                $ct
            );
        }

        $archive->addFromString('[Content_Types].xml', $ct);
    }

    /** @return array<string, string> rId => master path */
    private function getDeclaredMasters(string $presXml, string $presRels): array
    {
        $masters = [];

        // Parse .rels to get rId → target mapping for slideMaster type
        $relsDom = @simplexml_load_string($presRels);
        if ($relsDom === false) {
            return $masters;
        }

        $relsDom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($relsDom->xpath('//r:Relationship') as $rel) {
            $type = (string) $rel['Type'];
            if (str_contains($type, 'slideMaster')) {
                $masters[(string) $rel['Id']] = 'ppt/' . (string) $rel['Target'];
            }
        }

        return $masters;
    }

    /** @return string[] List of master paths that are actually used */
    private function traceUsedMasters(ZipArchive $archive, string $presXml, string $presRels): array
    {
        $usedMasters = [];

        // Get slide paths from .rels
        $relsDom = @simplexml_load_string($presRels);
        if ($relsDom === false) {
            return $usedMasters;
        }

        $relsDom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $slidePaths = [];
        foreach ($relsDom->xpath('//r:Relationship') as $rel) {
            if (str_contains((string) $rel['Type'], '/slide') && !str_contains((string) $rel['Type'], 'Master') && !str_contains((string) $rel['Type'], 'Layout')) {
                $slidePaths[] = 'ppt/' . (string) $rel['Target'];
            }
        }

        // For each slide, find its layout, then find the layout's master
        foreach ($slidePaths as $slidePath) {
            $slideRelsPath = dirname($slidePath) . '/_rels/' . basename($slidePath) . '.rels';
            $slideRels = $archive->getFromName($slideRelsPath);
            if ($slideRels === false) {
                continue;
            }

            $slideRelsDom = @simplexml_load_string($slideRels);
            if ($slideRelsDom === false) {
                continue;
            }

            $slideRelsDom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
            foreach ($slideRelsDom->xpath('//r:Relationship') as $rel) {
                if (!str_contains((string) $rel['Type'], 'slideLayout')) {
                    continue;
                }

                $layoutPath = $this->resolveRelativePath(dirname($slidePath), (string) $rel['Target']);
                $layoutRelsPath = dirname($layoutPath) . '/_rels/' . basename($layoutPath) . '.rels';
                $layoutRels = $archive->getFromName($layoutRelsPath);
                if ($layoutRels === false) {
                    continue;
                }

                $layoutRelsDom = @simplexml_load_string($layoutRels);
                if ($layoutRelsDom === false) {
                    continue;
                }

                $layoutRelsDom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
                foreach ($layoutRelsDom->xpath('//r:Relationship') as $layoutRel) {
                    if (str_contains((string) $layoutRel['Type'], 'slideMaster')) {
                        $masterPath = $this->resolveRelativePath(dirname($layoutPath), (string) $layoutRel['Target']);
                        $usedMasters[] = $masterPath;
                    }
                }
            }
        }

        return array_unique($usedMasters);
    }

    private function resolveRelativePath(string $baseDir, string $target): string
    {
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
