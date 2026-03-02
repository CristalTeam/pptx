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
 * Repair: remove orphaned master from sldMasterIdLst and presentation.xml.rels.
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

        foreach ($issues as $issue) {
            $rId = $issue->details['rId'];

            // Remove from sldMasterIdLst in presentation.xml
            $presXml = preg_replace(
                '/<p:sldMasterId[^>]*r:id="' . preg_quote($rId, '/') . '"[^>]*\/?>/',
                '',
                $presXml
            );

            // Remove from presentation.xml.rels
            $presRels = preg_replace(
                '/<Relationship[^>]*Id="' . preg_quote($rId, '/') . '"[^>]*\/?>/',
                '',
                $presRels
            );
        }

        $archive->addFromString('ppt/presentation.xml', $presXml);
        $archive->addFromString('ppt/_rels/presentation.xml.rels', $presRels);
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
