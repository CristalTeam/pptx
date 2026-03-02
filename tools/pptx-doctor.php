<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\Sanitizer\PPTXSanitizer;
use Cristal\Presentation\Sanitizer\Rules\AllRIdsResolveRule;
use Cristal\Presentation\Sanitizer\Rules\OrphanedSlideMasterRule;
use Cristal\Presentation\Sanitizer\Rules\UniqueRIdRule;
use Cristal\Presentation\Comparator\PPTXComparator;

$command = $argv[1] ?? null;

if ($command === 'diagnose' && isset($argv[2])) {
    $path = $argv[2];
    if (!file_exists($path)) {
        fwrite(STDERR, "File not found: $path\n");
        exit(1);
    }

    echo "=== PPTX Doctor — Diagnostic ===\n";
    echo "File: $path\n\n";

    $sanitizer = new PPTXSanitizer([
        new UniqueRIdRule(),
        new AllRIdsResolveRule(),
        new OrphanedSlideMasterRule(),
    ]);

    // Run on a copy to avoid modifying the original
    $tmpPath = tempnam(sys_get_temp_dir(), 'pptx_diag_');
    copy($path, $tmpPath);

    $tmpZip = new ZipArchive();
    $tmpZip->open($tmpPath);
    $report = $sanitizer->sanitize($tmpZip);
    $tmpZip->close();
    unlink($tmpPath);

    if (!$report->hasIssues()) {
        echo "No issues detected.\n";
    } else {
        foreach ($report->getIssues() as $issue) {
            echo "[{$issue->severity->value}] {$issue->message}\n";
        }
        echo "\nTotal: " . count($report->getIssues()) . " issue(s) detected and auto-repaired.\n";
    }

} elseif ($command === 'compare' && isset($argv[2], $argv[3])) {
    $pathA = $argv[2];
    $pathB = $argv[3];

    echo "=== PPTX Doctor — Compare ===\n";
    echo "File A (corrupt): $pathA\n";
    echo "File B (repaired): $pathB\n\n";

    $comparator = new PPTXComparator();
    $report = $comparator->compare($pathA, $pathB);

    if (!empty($report->getRemovedFiles())) {
        echo "Files removed by repair:\n";
        foreach ($report->getRemovedFiles() as $path => $size) {
            echo "  [-] $path ($size bytes)\n";
        }
        echo "\n";
    }

    if (!empty($report->getAddedFiles())) {
        echo "Files added by repair:\n";
        foreach ($report->getAddedFiles() as $path => $size) {
            echo "  [+] $path ($size bytes)\n";
        }
        echo "\n";
    }

    if (!empty($report->getModifiedFiles())) {
        echo "Files modified:\n";
        foreach ($report->getModifiedFiles() as $path => $info) {
            $diff = $info['after'] - $info['before'];
            $sign = $diff > 0 ? '+' : '';
            echo "  [~] $path ({$info['before']} → {$info['after']}, {$sign}{$diff})\n";
        }
        echo "\n";
    }

    if (!empty($report->getRIdChanges())) {
        echo "rId changes:\n";
        foreach ($report->getRIdChanges() as $change) {
            echo "  {$change['file']}: {$change['rId']} '{$change['oldTarget']}' → '{$change['newTarget']}'\n";
        }
        echo "\n";
    }

    if (!$report->hasDifferences()) {
        echo "No differences found.\n";
    }

} else {
    echo "Usage:\n";
    echo "  php pptx-doctor.php diagnose <file.pptx>              Detect issues\n";
    echo "  php pptx-doctor.php compare <corrupt.pptx> <repaired.pptx>  Compare files\n";
    exit(1);
}
