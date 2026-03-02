<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\SanitizeIssue;
use Cristal\Presentation\Sanitizer\SanitizeRule;
use Cristal\Presentation\Sanitizer\Severity;
use ZipArchive;

/**
 * Detects and repairs duplicate rId usage in presentation.xml.
 *
 * Problem: reorderPresentationRIds() updates sldIdLst, sldMasterIdLst,
 * notesMasterIdLst, handoutMasterIdLst — but NOT custDataLst or other
 * elements. After rId renumbering, custDataLst may reference an rId
 * that now belongs to a slide.
 *
 * Fix: Find the correct rId from the .rels file by matching the target
 * type, and update the XML element.
 */
class UniqueRIdRule implements SanitizeRule
{
    public function name(): string
    {
        return 'UniqueRIdRule';
    }

    public function severity(): Severity
    {
        return Severity::CRITICAL;
    }

    public function detect(ZipArchive $archive): array
    {
        $xml = $archive->getFromName('ppt/presentation.xml');
        if ($xml === false) {
            return [];
        }

        // Extract ALL r:id references from presentation.xml
        if (!preg_match_all('/r:id="(rId\d+)"/', $xml, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        // Count occurrences of each rId
        $rIdUsage = [];
        foreach ($matches[1] as [$rId, $offset]) {
            $rIdUsage[$rId][] = $offset;
        }

        $issues = [];
        foreach ($rIdUsage as $rId => $offsets) {
            if (count($offsets) > 1) {
                $issues[] = new SanitizeIssue(
                    Severity::CRITICAL,
                    "Duplicate rId '$rId' used " . count($offsets) . " times in presentation.xml",
                    $this->name(),
                    details: ['rId' => $rId, 'count' => count($offsets)],
                );
            }
        }

        return $issues;
    }

    public function repair(ZipArchive $archive, array $issues): void
    {
        $xml = $archive->getFromName('ppt/presentation.xml');
        $relsXml = $archive->getFromName('ppt/_rels/presentation.xml.rels');

        if ($xml === false || $relsXml === false) {
            return;
        }

        // Build rId → target type mapping from .rels
        $rIdToTarget = $this->parseRelsFile($relsXml);

        // Parse known rId lists from presentation.xml (these are "claimed" rIds)
        $claimedRIds = $this->extractClaimedRIds($xml);

        // For each duplicate: find the element that is NOT in the known lists
        // and reassign it a correct rId from .rels (by matching target type)
        foreach ($issues as $issue) {
            $duplicateRId = $issue->details['rId'];

            // Find alternative rIds in .rels that match the non-slide type
            // (the duplicate is typically: slide claimed rId, but custDataLst/tags still uses it)
            foreach ($rIdToTarget as $rId => $info) {
                // Skip the duplicate itself
                if ($rId === $duplicateRId) {
                    continue;
                }

                // Skip rIds already claimed by known lists
                if (in_array($rId, $claimedRIds, true)) {
                    continue;
                }

                // This rId is in .rels but not used in any known list
                // Check if it's a tags/custData type
                if (str_contains($info['type'], 'tags') || str_contains($info['target'], 'tags/')) {
                    // Replace the duplicate rId in custDataLst with this correct one
                    $xml = preg_replace(
                        '/(<p:tags\s+r:id=")' . preg_quote($duplicateRId, '/') . '(")/s',
                        '${1}' . $rId . '${2}',
                        $xml,
                        1
                    );
                    break;
                }
            }
        }

        $archive->addFromString('ppt/presentation.xml', $xml);
    }

    /**
     * Parse a .rels file and return rId => [type, target] mapping.
     *
     * @return array<string, array{type: string, target: string}>
     */
    private function parseRelsFile(string $relsXml): array
    {
        $map = [];
        $dom = @simplexml_load_string($relsXml);
        if ($dom === false) {
            return $map;
        }

        $dom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($dom->xpath('//r:Relationship') as $rel) {
            $map[(string) $rel['Id']] = [
                'type' => (string) $rel['Type'],
                'target' => (string) $rel['Target'],
            ];
        }

        return $map;
    }

    /**
     * Extract all rIds that are "claimed" by known XML lists in presentation.xml.
     *
     * @return string[]
     */
    private function extractClaimedRIds(string $xml): array
    {
        $claimed = [];

        // sldIdLst, sldMasterIdLst, notesMasterIdLst, handoutMasterIdLst
        $patterns = [
            '/sldId[^>]*r:id="(rId\d+)"/',
            '/sldMasterId[^>]*r:id="(rId\d+)"/',
            '/notesMasterId[^>]*r:id="(rId\d+)"/',
            '/handoutMasterId[^>]*r:id="(rId\d+)"/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $xml, $m)) {
                $claimed = array_merge($claimed, $m[1]);
            }
        }

        return array_unique($claimed);
    }
}
