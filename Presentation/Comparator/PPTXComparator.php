<?php

declare(strict_types=1);

namespace Cristal\Presentation\Comparator;

use ZipArchive;

/**
 * Compares two PPTX files (typically corrupt vs repaired) and reports differences.
 * Used as a development tool to identify new corruption patterns.
 */
class PPTXComparator
{
    public function compare(string $pathA, string $pathB): CompareReport
    {
        $report = new CompareReport();

        $zipA = new ZipArchive();
        $zipB = new ZipArchive();
        $zipA->open($pathA);
        $zipB->open($pathB);

        $filesA = $this->listFiles($zipA);
        $filesB = $this->listFiles($zipB);

        // Files in A but not B (removed by repair)
        foreach ($filesA as $path => $size) {
            if (!isset($filesB[$path])) {
                $report->addRemovedFile($path, $size);
            }
        }

        // Files in B but not A (added by repair)
        foreach ($filesB as $path => $size) {
            if (!isset($filesA[$path])) {
                $report->addAddedFile($path, $size);
            }
        }

        // Files in both but different size
        foreach ($filesA as $path => $sizeA) {
            if (isset($filesB[$path]) && $sizeA !== $filesB[$path]) {
                $report->addModifiedFile($path, $sizeA, $filesB[$path]);
            }
        }

        // Compare .rels files for rId changes
        $this->compareRels($zipA, $zipB, $report);

        $zipA->close();
        $zipB->close();

        return $report;
    }

    /** @return array<string, int> path => size */
    private function listFiles(ZipArchive $zip): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $files[$stat['name']] = $stat['size'];
        }

        return $files;
    }

    private function compareRels(ZipArchive $zipA, ZipArchive $zipB, CompareReport $report): void
    {
        for ($i = 0; $i < $zipA->numFiles; $i++) {
            $path = $zipA->getNameIndex($i);
            if (!str_ends_with($path, '.rels')) {
                continue;
            }

            $contentA = $zipA->getFromName($path);
            $contentB = $zipB->getFromName($path);

            if ($contentA === false || $contentB === false) {
                continue;
            }

            $relsA = $this->parseRels($contentA);
            $relsB = $this->parseRels($contentB);

            foreach ($relsA as $rId => $targetA) {
                $targetB = $relsB[$rId] ?? '(removed)';
                if ($targetA !== $targetB) {
                    $report->addRIdChange($path, $rId, $targetA, $targetB);
                }
            }
        }
    }

    /** @return array<string, string> rId => target */
    private function parseRels(string $xml): array
    {
        $map = [];
        $dom = @simplexml_load_string($xml);
        if ($dom === false) {
            return $map;
        }
        $dom->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($dom->xpath('//r:Relationship') as $rel) {
            $map[(string) $rel['Id']] = (string) $rel['Target'];
        }

        return $map;
    }
}
