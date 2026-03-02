<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\SanitizeIssue;
use Cristal\Presentation\Sanitizer\SanitizeRule;
use Cristal\Presentation\Sanitizer\Severity;
use ZipArchive;

/**
 * Detects rIds used in XML files that are not declared in the corresponding .rels.
 */
class AllRIdsResolveRule implements SanitizeRule
{
    public function name(): string
    {
        return 'AllRIdsResolveRule';
    }

    public function severity(): Severity
    {
        return Severity::HIGH;
    }

    public function detect(ZipArchive $archive): array
    {
        $issues = [];

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $xmlPath = $archive->getNameIndex($i);

            if (!str_ends_with($xmlPath, '.xml') || str_ends_with($xmlPath, '.rels')) {
                continue;
            }

            $xml = $archive->getFromIndex($i);
            if ($xml === false) {
                continue;
            }

            if (!preg_match_all('/r:id="(rId\d+)"/i', $xml, $matches)) {
                continue;
            }

            $usedRIds = array_unique($matches[1]);

            // Load corresponding .rels
            $relsPath = dirname($xmlPath) . '/_rels/' . basename($xmlPath) . '.rels';
            $relsXml = $archive->getFromName($relsPath);

            if ($relsXml === false) {
                if (!empty($usedRIds)) {
                    $issues[] = new SanitizeIssue(
                        Severity::HIGH,
                        "Missing .rels file '$relsPath' but '$xmlPath' uses rIds: " . implode(', ', $usedRIds),
                        $this->name(),
                        details: ['file' => $xmlPath, 'relsPath' => $relsPath, 'rIds' => $usedRIds],
                    );
                }
                continue;
            }

            $declaredRIds = $this->extractDeclaredRIds($relsXml);

            foreach ($usedRIds as $rId) {
                if (!in_array($rId, $declaredRIds, true)) {
                    $issues[] = new SanitizeIssue(
                        Severity::HIGH,
                        "Undeclared rId '$rId' in '$xmlPath' (not in '$relsPath')",
                        $this->name(),
                        details: ['file' => $xmlPath, 'rId' => $rId, 'relsPath' => $relsPath],
                    );
                }
            }
        }

        return $issues;
    }

    public function repair(ZipArchive $archive, array $issues): void
    {
        // Group issues by file
        $byFile = [];
        foreach ($issues as $issue) {
            $file = $issue->details['file'] ?? null;
            $rId = $issue->details['rId'] ?? null;
            if ($file && $rId) {
                $byFile[$file][] = $rId;
            }
        }

        // For each file, remove XML elements that reference undeclared rIds
        foreach ($byFile as $file => $orphanRIds) {
            $xml = $archive->getFromName($file);
            if ($xml === false) {
                continue;
            }

            foreach ($orphanRIds as $rId) {
                // Remove elements containing the orphan rId reference
                // Pattern: remove the entire element that contains r:id="rIdXX"
                $xml = preg_replace(
                    '/<[^>]*r:id="' . preg_quote($rId, '/') . '"[^>]*\/?>/',
                    '',
                    $xml
                );
            }

            $archive->addFromString($file, $xml);
        }
    }

    /** @return string[] */
    private function extractDeclaredRIds(string $relsXml): array
    {
        $rIds = [];
        $dom = @simplexml_load_string($relsXml);
        if ($dom === false) {
            return $rIds;
        }

        $dom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($dom->xpath('//r:Relationship') as $rel) {
            $rIds[] = (string) $rel['Id'];
        }

        return $rIds;
    }
}
