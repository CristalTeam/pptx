<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\SanitizeIssue;
use Cristal\Presentation\Sanitizer\SanitizeRule;
use Cristal\Presentation\Sanitizer\Severity;
use ZipArchive;

/**
 * Detects bidirectional inconsistencies between SlideMasters and SlideLayouts.
 *
 * Problem: After cloning, a SlideMaster's .rels may claim SlideLayouts that
 * actually point back to a DIFFERENT SlideMaster. This happens when the
 * sldLayoutIdLst wasn't properly cleared during cloning.
 *
 * Fix: Remove the mismatched layout references from the master's .rels
 * and sldLayoutIdLst, so each master only claims layouts that point back to it.
 */
class BidirectionalMasterLayoutRule implements SanitizeRule
{
    public function name(): string
    {
        return 'BidirectionalMasterLayoutRule';
    }

    public function severity(): Severity
    {
        return Severity::CRITICAL;
    }

    public function detect(ZipArchive $archive): array
    {
        $presRels = $archive->getFromName('ppt/_rels/presentation.xml.rels');
        if ($presRels === false) {
            return [];
        }

        // Get all declared master paths from presentation.xml.rels
        $masterPaths = $this->getMasterPaths($presRels);

        $issues = [];

        foreach ($masterPaths as $masterPath) {
            $masterRelsPath = dirname($masterPath) . '/_rels/' . basename($masterPath) . '.rels';
            $masterRels = $archive->getFromName($masterRelsPath);
            if ($masterRels === false) {
                continue;
            }

            // Get layouts claimed by this master
            $claimedLayouts = $this->getClaimedLayouts($masterRels, $masterPath);

            foreach ($claimedLayouts as $rId => $layoutPath) {
                // Check where this layout actually points
                $layoutRelsPath = dirname($layoutPath) . '/_rels/' . basename($layoutPath) . '.rels';
                $layoutRels = $archive->getFromName($layoutRelsPath);
                if ($layoutRels === false) {
                    continue;
                }

                $actualMaster = $this->getLayoutMaster($layoutRels, $layoutPath);
                if ($actualMaster === null) {
                    continue;
                }

                if ($actualMaster !== $masterPath) {
                    $masterName = basename($masterPath);
                    $layoutName = basename($layoutPath);
                    $actualMasterName = basename($actualMaster);
                    $issues[] = new SanitizeIssue(
                        Severity::CRITICAL,
                        "Bidirectional mismatch: $masterName claims $layoutName, but $layoutName points to $actualMasterName",
                        $this->name(),
                        details: [
                            'masterPath' => $masterPath,
                            'masterRelsPath' => $masterRelsPath,
                            'layoutPath' => $layoutPath,
                            'layoutRId' => $rId,
                            'actualMaster' => $actualMaster,
                        ],
                    );
                }
            }
        }

        return $issues;
    }

    public function repair(ZipArchive $archive, array $issues): void
    {
        // Group issues by master .rels path
        $byMasterRels = [];
        foreach ($issues as $issue) {
            $masterRelsPath = $issue->details['masterRelsPath'];
            $byMasterRels[$masterRelsPath][] = $issue;
        }

        foreach ($byMasterRels as $masterRelsPath => $masterIssues) {
            $masterRels = $archive->getFromName($masterRelsPath);
            if ($masterRels === false) {
                continue;
            }

            // Also load master XML to update sldLayoutIdLst
            $masterPath = $masterIssues[0]->details['masterPath'];
            $masterXml = $archive->getFromName($masterPath);

            foreach ($masterIssues as $issue) {
                $rId = $issue->details['layoutRId'];

                // Remove from master .rels
                $masterRels = preg_replace(
                    '/<Relationship[^>]*Id="' . preg_quote($rId, '/') . '"[^>]*\/?>/',
                    '',
                    $masterRels
                );

                // Remove from sldLayoutIdLst in master XML
                if ($masterXml !== false) {
                    $masterXml = preg_replace(
                        '/<p:sldLayoutId[^>]*r:id="' . preg_quote($rId, '/') . '"[^>]*\/?>/',
                        '',
                        $masterXml
                    );
                }
            }

            $archive->addFromString($masterRelsPath, $masterRels);
            if ($masterXml !== false) {
                $archive->addFromString($masterPath, $masterXml);
            }
        }
    }

    /** @return string[] master file paths */
    private function getMasterPaths(string $presRels): array
    {
        $paths = [];
        $dom = @simplexml_load_string($presRels);
        if ($dom === false) {
            return $paths;
        }

        $dom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($dom->xpath('//r:Relationship') as $rel) {
            if (str_contains((string) $rel['Type'], 'slideMaster')) {
                $paths[] = 'ppt/' . (string) $rel['Target'];
            }
        }

        return $paths;
    }

    /**
     * Get layouts claimed by a master from its .rels file.
     *
     * @return array<string, string> rId => resolved layout path
     */
    private function getClaimedLayouts(string $masterRels, string $masterPath): array
    {
        $layouts = [];
        $dom = @simplexml_load_string($masterRels);
        if ($dom === false) {
            return $layouts;
        }

        $dom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($dom->xpath('//r:Relationship') as $rel) {
            if (str_contains((string) $rel['Type'], 'slideLayout')) {
                $layouts[(string) $rel['Id']] = $this->resolveRelativePath(
                    dirname($masterPath),
                    (string) $rel['Target']
                );
            }
        }

        return $layouts;
    }

    /**
     * Get the master that a layout points to from its .rels file.
     */
    private function getLayoutMaster(string $layoutRels, string $layoutPath): ?string
    {
        $dom = @simplexml_load_string($layoutRels);
        if ($dom === false) {
            return null;
        }

        $dom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($dom->xpath('//r:Relationship') as $rel) {
            if (str_contains((string) $rel['Type'], 'slideMaster')) {
                return $this->resolveRelativePath(
                    dirname($layoutPath),
                    (string) $rel['Target']
                );
            }
        }

        return null;
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
