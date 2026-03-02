# PPTX Sanitizer Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a PPTXSanitizer system that auto-detects and repairs PPTX structural issues before save, plus a PPTXComparator for diagnosing new corruption cases.

**Architecture:** Rule-based sanitizer integrated as last step in `saveAs()`. Each rule implements detect/repair. A separate PPTXComparator tool compares corrupt vs repaired files to discover new rules needed.

**Tech Stack:** PHP 8.1+, ZipArchive, SimpleXMLElement, PHPUnit

**Design Doc:** `docs/plans/2026-03-02-pptx-sanitizer-design.md`

---

## Key References

- `Presentation/PPTX.php` — Main entry point. `saveAs()` at line 1159. `reorderPresentationRIds()` at line 1200.
- `Presentation/Config/OptimizationConfig.php` — Config with DEFAULTS array at line 27.
- `Presentation/Validator/OPCValidator.php` — Existing validator pattern (works on ZipArchive).
- `Presentation/Resource/Presentation.php` — `remapResourceIds()` at line 552 (BUG: doesn't update `custDataLst`).
- `Presentation/ResourceInterface.php` — Interface all resources implement.
- `tests/PPTXTest.php` — Existing integration tests.
- `tests/mock/PropaleFailed.pptx` — Corrupted test file.
- `tests/mock/PropaleRepaired.pptx` — PowerPoint-repaired reference.

## Namespace & Path Convention

- Namespace: `Cristal\Presentation\Sanitizer\` → Path: `Presentation/Sanitizer/`
- Namespace: `Cristal\Presentation\Sanitizer\Rules\` → Path: `Presentation/Sanitizer/Rules/`
- Namespace: `Cristal\Presentation\Comparator\` → Path: `Presentation/Comparator/`

---

## Task 1: Create Severity Enum and SanitizeIssue

**Files:**
- Create: `Presentation/Sanitizer/Severity.php`
- Create: `Presentation/Sanitizer/SanitizeIssue.php`

**Step 1: Create the Severity enum**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer;

enum Severity: string
{
    case CRITICAL = 'CRITICAL';
    case HIGH = 'HIGH';
    case MEDIUM = 'MEDIUM';
    case WARNING = 'WARNING';
}
```

**Step 2: Create the SanitizeIssue value object**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer;

class SanitizeIssue
{
    public function __construct(
        public readonly Severity $severity,
        public readonly string $message,
        public readonly string $ruleName,
        public readonly bool $repaired = false,
        public readonly array $details = [],
    ) {}
}
```

**Step 3: Commit**

```bash
git add Presentation/Sanitizer/Severity.php Presentation/Sanitizer/SanitizeIssue.php
git commit -m "feat(sanitizer): add Severity enum and SanitizeIssue value object"
```

---

## Task 2: Create SanitizeReport

**Files:**
- Create: `Presentation/Sanitizer/SanitizeReport.php`
- Create: `tests/Sanitizer/SanitizeReportTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer;

use Cristal\Presentation\Sanitizer\SanitizeIssue;
use Cristal\Presentation\Sanitizer\SanitizeReport;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;

class SanitizeReportTest extends TestCase
{
    /** @test */
    public function it_starts_empty(): void
    {
        $report = new SanitizeReport();
        $this->assertFalse($report->hasIssues());
        $this->assertEmpty($report->getIssues());
        $this->assertSame(0, $report->countBySeverity(Severity::CRITICAL));
    }

    /** @test */
    public function it_tracks_issues(): void
    {
        $report = new SanitizeReport();
        $issue = new SanitizeIssue(Severity::CRITICAL, 'Duplicate rId', 'UniqueRIdRule', true);

        $report->addIssue($issue);

        $this->assertTrue($report->hasIssues());
        $this->assertCount(1, $report->getIssues());
        $this->assertSame(1, $report->countBySeverity(Severity::CRITICAL));
        $this->assertSame(0, $report->countBySeverity(Severity::WARNING));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Sanitizer/SanitizeReportTest.php`
Expected: FAIL (class not found)

**Step 3: Write the SanitizeReport class**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer;

class SanitizeReport
{
    /** @var SanitizeIssue[] */
    private array $issues = [];

    public function addIssue(SanitizeIssue $issue): void
    {
        $this->issues[] = $issue;
    }

    /** @param SanitizeIssue[] $issues */
    public function addIssues(array $issues): void
    {
        foreach ($issues as $issue) {
            $this->addIssue($issue);
        }
    }

    public function hasIssues(): bool
    {
        return !empty($this->issues);
    }

    /** @return SanitizeIssue[] */
    public function getIssues(): array
    {
        return $this->issues;
    }

    public function countBySeverity(Severity $severity): int
    {
        return count(array_filter($this->issues, fn(SanitizeIssue $i) => $i->severity === $severity));
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Sanitizer/SanitizeReportTest.php`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add Presentation/Sanitizer/SanitizeReport.php tests/Sanitizer/SanitizeReportTest.php
git commit -m "feat(sanitizer): add SanitizeReport with tests"
```

---

## Task 3: Create SanitizeRule Interface and PPTXSanitizer

**Files:**
- Create: `Presentation/Sanitizer/SanitizeRule.php`
- Create: `Presentation/Sanitizer/PPTXSanitizer.php`
- Create: `tests/Sanitizer/PPTXSanitizerTest.php`

**Step 1: Create the SanitizeRule interface**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer;

use ZipArchive;

interface SanitizeRule
{
    public function name(): string;

    public function severity(): Severity;

    /**
     * Detect issues in the PPTX archive.
     *
     * @return SanitizeIssue[]
     */
    public function detect(ZipArchive $archive): array;

    /**
     * Repair detected issues in the PPTX archive.
     *
     * @param SanitizeIssue[] $issues Issues detected by detect()
     */
    public function repair(ZipArchive $archive, array $issues): void;
}
```

**Important design note:** Rules receive a `ZipArchive` (not PPTX object) because at the point the sanitizer runs in `saveAs()`, all previous steps have already written their changes to the archive. Working at the ZIP level is simpler and avoids coupling to PPTX internals.

**Step 2: Write the failing test for PPTXSanitizer**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer;

use Cristal\Presentation\Sanitizer\PPTXSanitizer;
use Cristal\Presentation\Sanitizer\SanitizeIssue;
use Cristal\Presentation\Sanitizer\SanitizeReport;
use Cristal\Presentation\Sanitizer\SanitizeRule;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class PPTXSanitizerTest extends TestCase
{
    /** @test */
    public function it_returns_empty_report_with_no_rules(): void
    {
        $sanitizer = new PPTXSanitizer([]);
        $archive = $this->createMock(ZipArchive::class);

        $report = $sanitizer->sanitize($archive);

        $this->assertFalse($report->hasIssues());
    }

    /** @test */
    public function it_detects_and_repairs_issues(): void
    {
        $issue = new SanitizeIssue(Severity::CRITICAL, 'test issue', 'TestRule');

        $rule = $this->createMock(SanitizeRule::class);
        $rule->method('detect')->willReturn([$issue]);
        $rule->expects($this->once())->method('repair');

        $sanitizer = new PPTXSanitizer([$rule]);
        $archive = $this->createMock(ZipArchive::class);

        $report = $sanitizer->sanitize($archive);

        $this->assertTrue($report->hasIssues());
        $this->assertCount(1, $report->getIssues());
        $this->assertTrue($report->getIssues()[0]->repaired);
    }

    /** @test */
    public function it_skips_repair_when_no_issues(): void
    {
        $rule = $this->createMock(SanitizeRule::class);
        $rule->method('detect')->willReturn([]);
        $rule->expects($this->never())->method('repair');

        $sanitizer = new PPTXSanitizer([$rule]);
        $archive = $this->createMock(ZipArchive::class);

        $report = $sanitizer->sanitize($archive);

        $this->assertFalse($report->hasIssues());
    }
}
```

**Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Sanitizer/PPTXSanitizerTest.php`
Expected: FAIL (class not found)

**Step 4: Write the PPTXSanitizer class**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer;

use ZipArchive;

class PPTXSanitizer
{
    /** @var SanitizeRule[] */
    private array $rules;

    /** @param SanitizeRule[] $rules */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function sanitize(ZipArchive $archive): SanitizeReport
    {
        $report = new SanitizeReport();

        foreach ($this->rules as $rule) {
            $issues = $rule->detect($archive);

            if (empty($issues)) {
                continue;
            }

            $rule->repair($archive, $issues);

            // Mark all issues as repaired
            foreach ($issues as $issue) {
                $report->addIssue(new SanitizeIssue(
                    $issue->severity,
                    $issue->message,
                    $issue->ruleName,
                    repaired: true,
                    details: $issue->details,
                ));
            }
        }

        return $report;
    }

    /** @return SanitizeRule[] */
    public function getRules(): array
    {
        return $this->rules;
    }
}
```

**Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Sanitizer/PPTXSanitizerTest.php`
Expected: PASS (3 tests)

**Step 6: Commit**

```bash
git add Presentation/Sanitizer/SanitizeRule.php Presentation/Sanitizer/PPTXSanitizer.php tests/Sanitizer/PPTXSanitizerTest.php
git commit -m "feat(sanitizer): add SanitizeRule interface and PPTXSanitizer orchestrator"
```

---

## Task 4: Integrate Sanitizer into PPTX.php and Config

**Files:**
- Modify: `Presentation/Config/OptimizationConfig.php` (add `sanitize` default)
- Modify: `Presentation/PPTX.php` (add sanitizer call in `saveAs()`, add `getSanitizeReport()`)

**Step 1: Add `sanitize` option to OptimizationConfig**

In `Presentation/Config/OptimizationConfig.php`, add to the DEFAULTS array (line 49, before `'collect_stats'`):

```php
        // Sanitization (auto-repair before save)
        'sanitize' => true,
```

**Step 2: Add sanitizer integration to PPTX.php**

Add import at top of `Presentation/PPTX.php` (after line 30):

```php
use Cristal\Presentation\Sanitizer\PPTXSanitizer;
use Cristal\Presentation\Sanitizer\SanitizeReport;
use Cristal\Presentation\Sanitizer\Rules\UniqueRIdRule;
use Cristal\Presentation\Sanitizer\Rules\AllRIdsResolveRule;
use Cristal\Presentation\Sanitizer\Rules\OrphanedSlideMasterRule;
```

Add property (after line 58 `protected ?PresentationValidator $validator = null;`):

```php
    protected ?SanitizeReport $sanitizeReport = null;
```

Add method after `saveAs()` (after line 1185):

```php
    /**
     * Get the sanitize report from the last saveAs() call.
     */
    public function getSanitizeReport(): ?SanitizeReport
    {
        return $this->sanitizeReport;
    }

    /**
     * Build the list of sanitize rules to apply.
     *
     * @return \Cristal\Presentation\Sanitizer\SanitizeRule[]
     */
    protected function getSanitizeRules(): array
    {
        return [
            new UniqueRIdRule(),
            new AllRIdsResolveRule(),
            new OrphanedSlideMasterRule(),
        ];
    }
```

Modify `saveAs()` method (at line 1173, after `$this->updateAppProperties();`):

```php
        // Sanitize: detect and auto-repair structural issues
        if ($this->config->isEnabled('sanitize')) {
            $sanitizer = new PPTXSanitizer($this->getSanitizeRules());
            $this->sanitizeReport = $sanitizer->sanitize($this->archive);
        }
```

**Step 3: Run existing tests to verify nothing breaks**

Run: `vendor/bin/phpunit tests/PPTXTest.php`
Expected: PASS (all existing tests still pass — sanitizer with no rules found = no-op; the rule classes don't exist yet but they're listed in `getSanitizeRules()` which is only called when sanitize=true)

**Note:** The `getSanitizeRules()` method references rule classes that don't exist yet. The imports will cause a class-not-found only when `saveAs()` is called with `sanitize=true`. If tests fail at this stage, temporarily return an empty array from `getSanitizeRules()` and add the rules back in Task 5+.

**Step 4: Commit**

```bash
git add Presentation/Config/OptimizationConfig.php Presentation/PPTX.php
git commit -m "feat(sanitizer): integrate PPTXSanitizer into saveAs() with config option"
```

---

## Task 5: Implement UniqueRIdRule (CRITICAL — fixes PropaleFailed.pptx #1)

This is the most critical rule. It detects rIds used multiple times in presentation.xml (e.g., `rId14` used for both a slide AND `custDataLst`).

**Root cause:** `remapResourceIds()` in `Presentation.php:552-676` updates `sldIdLst`, `sldMasterIdLst`, `notesMasterIdLst`, `handoutMasterIdLst` — but NOT `custDataLst`, `modifyVerifier`, or any other elements that may use `r:id` attributes.

**Files:**
- Create: `Presentation/Sanitizer/Rules/UniqueRIdRule.php`
- Create: `tests/Sanitizer/Rules/UniqueRIdRuleTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\Rules\UniqueRIdRule;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class UniqueRIdRuleTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pptx_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    /** @test */
    public function it_detects_duplicate_rids_in_presentation_xml(): void
    {
        // Build a minimal PPTX with duplicate rId14 in presentation.xml
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // presentation.xml with rId14 used in BOTH sldIdLst AND custDataLst
        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>
            <p:sldIdLst>
                <p:sldId id="256" r:id="rId2"/>
                <p:sldId id="257" r:id="rId14"/>
            </p:sldIdLst>
            <p:custDataLst><p:tags r:id="rId14"/></p:custDataLst>
        </p:presentation>';

        $presentationRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
            <Relationship Id="rId14" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide2.xml"/>
            <Relationship Id="rId29" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tags" Target="tags/tag1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presentationRels);
        $zip->close();

        $zip->open($this->tmpFile);

        $rule = new UniqueRIdRule();
        $issues = $rule->detect($zip);

        $this->assertNotEmpty($issues, 'Should detect duplicate rId14');
        $this->assertSame(Severity::CRITICAL, $issues[0]->severity);
        $this->assertStringContainsString('rId14', $issues[0]->message);

        $zip->close();
    }

    /** @test */
    public function it_repairs_duplicate_rids(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>
            <p:sldIdLst>
                <p:sldId id="256" r:id="rId2"/>
                <p:sldId id="257" r:id="rId14"/>
            </p:sldIdLst>
            <p:custDataLst><p:tags r:id="rId14"/></p:custDataLst>
        </p:presentation>';

        $presentationRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
            <Relationship Id="rId14" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide2.xml"/>
            <Relationship Id="rId29" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tags" Target="tags/tag1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presentationRels);
        $zip->close();

        $zip->open($this->tmpFile);

        $rule = new UniqueRIdRule();
        $issues = $rule->detect($zip);
        $rule->repair($zip, $issues);

        // Re-detect: should be clean now
        $newIssues = $rule->detect($zip);
        $this->assertEmpty($newIssues, 'After repair, no duplicate rIds should remain');

        // Verify custDataLst now uses the correct rId (rId29 for tags)
        $xml = $zip->getFromName('ppt/presentation.xml');
        $this->assertStringNotContainsString('custDataLst><p:tags r:id="rId14"', $xml);

        $zip->close();
    }

    /** @test */
    public function it_passes_when_no_duplicates(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>
            <p:sldIdLst><p:sldId id="256" r:id="rId2"/></p:sldIdLst>
            <p:custDataLst><p:tags r:id="rId3"/></p:custDataLst>
        </p:presentation>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->close();

        $zip->open($this->tmpFile);

        $rule = new UniqueRIdRule();
        $issues = $rule->detect($zip);

        $this->assertEmpty($issues);

        $zip->close();
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Sanitizer/Rules/UniqueRIdRuleTest.php`
Expected: FAIL (class not found)

**Step 3: Implement UniqueRIdRule**

```php
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

        // Find the highest rId in the .rels file
        $maxRId = 0;
        foreach (array_keys($rIdToTarget) as $rId) {
            $num = (int) preg_replace('/[^0-9]/', '', $rId);
            if ($num > $maxRId) {
                $maxRId = $num;
            }
        }

        // For each duplicate: find the element that is NOT in the known lists
        // and reassign it a correct rId from .rels (by matching target type)
        foreach ($issues as $issue) {
            $duplicateRId = $issue->details['rId'];

            // Find what type the duplicate rId points to in .rels
            $relsTarget = $rIdToTarget[$duplicateRId] ?? null;

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
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Sanitizer/Rules/UniqueRIdRuleTest.php`
Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add Presentation/Sanitizer/Rules/UniqueRIdRule.php tests/Sanitizer/Rules/UniqueRIdRuleTest.php
git commit -m "feat(sanitizer): add UniqueRIdRule — fixes duplicate rId in custDataLst"
```

---

## Task 6: Implement AllRIdsResolveRule

Detects rIds in XML files that point to non-existent targets in the corresponding .rels file.

**Files:**
- Create: `Presentation/Sanitizer/Rules/AllRIdsResolveRule.php`
- Create: `tests/Sanitizer/Rules/AllRIdsResolveRuleTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\Rules\AllRIdsResolveRule;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class AllRIdsResolveRuleTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pptx_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    /** @test */
    public function it_detects_rids_not_in_rels(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // presentation.xml references rId99 which doesn't exist in .rels
        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldIdLst><p:sldId id="256" r:id="rId1"/><p:sldId id="257" r:id="rId99"/></p:sldIdLst>
        </p:presentation>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $rels);
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new AllRIdsResolveRule();
        $issues = $rule->detect($zip);
        $zip->close();

        $this->assertNotEmpty($issues);
        $this->assertSame(Severity::HIGH, $issues[0]->severity);
        $this->assertStringContainsString('rId99', $issues[0]->message);
    }

    /** @test */
    public function it_passes_when_all_rids_resolve(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst>
        </p:presentation>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $rels);
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new AllRIdsResolveRule();
        $issues = $rule->detect($zip);
        $zip->close();

        $this->assertEmpty($issues);
    }
}
```

**Step 2: Run test, verify fail, then implement**

```php
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
```

**Step 3: Run tests, verify pass, commit**

Run: `vendor/bin/phpunit tests/Sanitizer/Rules/AllRIdsResolveRuleTest.php`

```bash
git add Presentation/Sanitizer/Rules/AllRIdsResolveRule.php tests/Sanitizer/Rules/AllRIdsResolveRuleTest.php
git commit -m "feat(sanitizer): add AllRIdsResolveRule — detects undeclared rId references"
```

---

## Task 7: Implement OrphanedSlideMasterRule (fixes PropaleFailed.pptx #2)

Detects SlideMasters that are not used by any SlideLayout that a Slide references.

**Files:**
- Create: `Presentation/Sanitizer/Rules/OrphanedSlideMasterRule.php`
- Create: `tests/Sanitizer/Rules/OrphanedSlideMasterRuleTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer\Rules;

use Cristal\Presentation\Sanitizer\Rules\OrphanedSlideMasterRule;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class OrphanedSlideMasterRuleTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pptx_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    /** @test */
    public function it_detects_orphaned_slide_masters(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // presentation.xml with 2 masters, but only 1 slide uses master1's layout
        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst>
                <p:sldMasterId id="2147483648" r:id="rId1"/>
                <p:sldMasterId id="2147483649" r:id="rId2"/>
            </p:sldMasterIdLst>
            <p:sldIdLst><p:sldId id="256" r:id="rId3"/></p:sldIdLst>
        </p:presentation>';

        $presRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster2.xml"/>
            <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
        </Relationships>';

        // slide1 uses slideLayout1 which references slideMaster1
        $slide1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
        </Relationships>';

        $layout1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
        </Relationships>';

        // slideMaster2 has a layout (slideLayout2) but NO slide uses it
        $master2Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout2.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme2.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presRels);
        $zip->addFromString('ppt/slides/slide1.xml', '<xml/>');
        $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', $slide1Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<xml/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $layout1Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout2.xml', '<xml/>');
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<xml/>');
        $zip->addFromString('ppt/slideMasters/slideMaster2.xml', '<xml/>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster2.xml.rels', $master2Rels);
        $zip->addFromString('ppt/theme/theme2.xml', '<xml/>');
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new OrphanedSlideMasterRule();
        $issues = $rule->detect($zip);
        $zip->close();

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('slideMaster2', $issues[0]->message);
    }

    /** @test */
    public function it_passes_when_all_masters_used(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $presentationXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"
                        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
            <p:sldMasterIdLst>
                <p:sldMasterId id="2147483648" r:id="rId1"/>
            </p:sldMasterIdLst>
            <p:sldIdLst><p:sldId id="256" r:id="rId2"/></p:sldIdLst>
        </p:presentation>';

        $presRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>
        </Relationships>';

        $slide1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
        </Relationships>';

        $layout1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
        </Relationships>';

        $zip->addFromString('ppt/presentation.xml', $presentationXml);
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $presRels);
        $zip->addFromString('ppt/slides/slide1.xml', '<xml/>');
        $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', $slide1Rels);
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<xml/>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $layout1Rels);
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<xml/>');
        $zip->close();

        $zip->open($this->tmpFile);
        $rule = new OrphanedSlideMasterRule();
        $issues = $rule->detect($zip);
        $zip->close();

        $this->assertEmpty($issues);
    }
}
```

**Step 2: Implement OrphanedSlideMasterRule**

```php
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
 * Also remove the master's dedicated theme if not shared.
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
```

**Step 3: Run tests, verify pass, commit**

Run: `vendor/bin/phpunit tests/Sanitizer/Rules/OrphanedSlideMasterRuleTest.php`

```bash
git add Presentation/Sanitizer/Rules/OrphanedSlideMasterRule.php tests/Sanitizer/Rules/OrphanedSlideMasterRuleTest.php
git commit -m "feat(sanitizer): add OrphanedSlideMasterRule — removes unused SlideMasters"
```

---

## Task 8: Integration Test with PropaleFailed.pptx

This is the critical validation: the sanitizer must fix the known corruption in PropaleFailed.pptx.

**Files:**
- Create: `tests/Sanitizer/PPTXSanitizerIntegrationTest.php`

**Step 1: Write the integration test**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests\Sanitizer;

use Cristal\Presentation\Sanitizer\PPTXSanitizer;
use Cristal\Presentation\Sanitizer\Rules\AllRIdsResolveRule;
use Cristal\Presentation\Sanitizer\Rules\OrphanedSlideMasterRule;
use Cristal\Presentation\Sanitizer\Rules\UniqueRIdRule;
use Cristal\Presentation\Sanitizer\Severity;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class PPTXSanitizerIntegrationTest extends TestCase
{
    /** @test */
    public function it_detects_issues_in_propale_failed(): void
    {
        $path = __DIR__ . '/../mock/PropaleFailed.pptx';
        if (!file_exists($path)) {
            $this->markTestSkipped('PropaleFailed.pptx not available');
        }

        $zip = new ZipArchive();
        $zip->open($path);

        $sanitizer = new PPTXSanitizer([
            new UniqueRIdRule(),
            new AllRIdsResolveRule(),
            new OrphanedSlideMasterRule(),
        ]);

        $report = $sanitizer->sanitize($zip);
        $zip->close();

        // PropaleFailed.pptx has known issues
        $this->assertTrue($report->hasIssues(), 'PropaleFailed.pptx should have detectable issues');

        // At minimum: duplicate rId14 and orphaned masters
        $criticalCount = $report->countBySeverity(Severity::CRITICAL);
        $this->assertGreaterThanOrEqual(1, $criticalCount, 'Should detect at least 1 CRITICAL issue');
    }

    /** @test */
    public function it_repairs_propale_failed_to_be_cleaner(): void
    {
        $sourcePath = __DIR__ . '/../mock/PropaleFailed.pptx';
        if (!file_exists($sourcePath)) {
            $this->markTestSkipped('PropaleFailed.pptx not available');
        }

        // Work on a copy
        $tmpPath = tempnam(sys_get_temp_dir(), 'pptx_repair_');
        copy($sourcePath, $tmpPath);

        $zip = new ZipArchive();
        $zip->open($tmpPath);

        $sanitizer = new PPTXSanitizer([
            new UniqueRIdRule(),
            new AllRIdsResolveRule(),
            new OrphanedSlideMasterRule(),
        ]);

        $report = $sanitizer->sanitize($zip);
        $zip->close();

        // Re-open and verify no more issues detected
        $zip->open($tmpPath);
        $verifyReport = $sanitizer->sanitize($zip);
        $zip->close();

        // After repair, critical issues should be resolved
        $this->assertSame(
            0,
            $verifyReport->countBySeverity(Severity::CRITICAL),
            'After repair, no CRITICAL issues should remain. Remaining: ' .
            implode(', ', array_map(fn($i) => $i->message, $verifyReport->getIssues()))
        );

        unlink($tmpPath);
    }

    /** @test */
    public function it_does_not_flag_propale_repaired(): void
    {
        $path = __DIR__ . '/../mock/PropaleRepaired.pptx';
        if (!file_exists($path)) {
            $this->markTestSkipped('PropaleRepaired.pptx not available');
        }

        $zip = new ZipArchive();
        $zip->open($path);

        $sanitizer = new PPTXSanitizer([
            new UniqueRIdRule(),
            new AllRIdsResolveRule(),
            new OrphanedSlideMasterRule(),
        ]);

        $report = $sanitizer->sanitize($zip);
        $zip->close();

        $this->assertSame(
            0,
            $report->countBySeverity(Severity::CRITICAL),
            'PropaleRepaired.pptx should have no CRITICAL issues'
        );
    }
}
```

**Step 2: Run tests and iterate**

Run: `vendor/bin/phpunit tests/Sanitizer/PPTXSanitizerIntegrationTest.php`

If tests fail, debug and fix the rules. This is the real-world validation.

**Step 3: Commit**

```bash
git add tests/Sanitizer/PPTXSanitizerIntegrationTest.php
git commit -m "test(sanitizer): add integration tests with PropaleFailed.pptx"
```

---

## Task 9: PPTXComparator — Diagnostic Tool

**Files:**
- Create: `Presentation/Comparator/PPTXComparator.php`
- Create: `Presentation/Comparator/CompareReport.php`

**Step 1: Implement CompareReport**

```php
<?php

declare(strict_types=1);

namespace Cristal\Presentation\Comparator;

class CompareReport
{
    /** @var array<string, mixed> */
    private array $addedFiles = [];
    /** @var array<string, mixed> */
    private array $removedFiles = [];
    /** @var array<string, mixed> */
    private array $modifiedFiles = [];
    /** @var array<string, mixed> */
    private array $rIdChanges = [];

    public function addAddedFile(string $path, int $size): void
    {
        $this->addedFiles[$path] = $size;
    }

    public function addRemovedFile(string $path, int $size): void
    {
        $this->removedFiles[$path] = $size;
    }

    public function addModifiedFile(string $path, int $sizeBefore, int $sizeAfter): void
    {
        $this->modifiedFiles[$path] = ['before' => $sizeBefore, 'after' => $sizeAfter];
    }

    public function addRIdChange(string $file, string $rId, string $oldTarget, string $newTarget): void
    {
        $this->rIdChanges[] = compact('file', 'rId', 'oldTarget', 'newTarget');
    }

    /** @return array<string, int> */
    public function getAddedFiles(): array { return $this->addedFiles; }
    /** @return array<string, int> */
    public function getRemovedFiles(): array { return $this->removedFiles; }
    /** @return array<string, mixed> */
    public function getModifiedFiles(): array { return $this->modifiedFiles; }
    /** @return array<string, mixed> */
    public function getRIdChanges(): array { return $this->rIdChanges; }

    public function hasDifferences(): bool
    {
        return !empty($this->addedFiles) || !empty($this->removedFiles) || !empty($this->modifiedFiles) || !empty($this->rIdChanges);
    }
}
```

**Step 2: Implement PPTXComparator**

```php
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
```

**Step 3: Commit**

```bash
git add Presentation/Comparator/PPTXComparator.php Presentation/Comparator/CompareReport.php
git commit -m "feat(comparator): add PPTXComparator for corrupt vs repaired analysis"
```

---

## Task 10: CLI Tool — pptx-doctor.php

**Files:**
- Create: `tools/pptx-doctor.php`

**Step 1: Implement the CLI tool**

```php
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

    $zip = new ZipArchive();
    $zip->open($path);

    $sanitizer = new PPTXSanitizer([
        new UniqueRIdRule(),
        new AllRIdsResolveRule(),
        new OrphanedSlideMasterRule(),
    ]);

    // Run detect-only (on a copy to avoid modifying the original)
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

    $zip->close();

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
```

**Step 2: Test the CLI manually**

```bash
php tools/pptx-doctor.php diagnose tests/mock/PropaleFailed.pptx
php tools/pptx-doctor.php compare tests/mock/PropaleFailed.pptx tests/mock/PropaleRepaired.pptx
```

**Step 3: Commit**

```bash
git add tools/pptx-doctor.php
git commit -m "feat(tools): add pptx-doctor.php CLI for diagnosis and comparison"
```

---

## Task 11: Run Full Test Suite and Verify

**Step 1: Run all tests**

```bash
vendor/bin/phpunit
```

Expected: ALL tests pass, including existing PPTXTest.php tests.

**Step 2: Run static analysis**

```bash
vendor/bin/phpstan analyse --level=6
```

Fix any type issues.

**Step 3: Run code formatting**

```bash
vendor/bin/php-cs-fixer fix
```

**Step 4: Final commit**

```bash
git add -A
git commit -m "chore: fix code style and static analysis issues"
```

---

## Future Tasks (not in this plan, add as needed)

These rules can be added incrementally when new corruption cases appear:

- **SldLayoutIdLstConsistencyRule** — Rebuild sldLayoutIdLst from .rels
- **BidirectionalMasterLayoutRule** — Fix Layout→Master mismatches
- **NoteSlideReferenceRule** — Fix NoteSlide→Slide broken refs
- **SectionCompletenessRule** — Add orphan slides to default section
- **ImageExtensionRule** — Fix mismatched image extensions via MIME detection
- **ContentTypeCompletenessRule** — Add missing Content_Types entries
- **RIdOrderingRule** — Warn on non-OPC rId ordering

Each follows the same TDD pattern: write test → implement detect() → implement repair() → integration test → commit.

---

## Summary

| Task | What | Files | Est. |
|------|------|-------|------|
| 1 | Severity + SanitizeIssue | 2 new | quick |
| 2 | SanitizeReport + test | 2 new | quick |
| 3 | SanitizeRule + PPTXSanitizer + test | 3 new | quick |
| 4 | Config + PPTX.php integration | 2 modified | medium |
| 5 | UniqueRIdRule + test | 2 new | medium |
| 6 | AllRIdsResolveRule + test | 2 new | medium |
| 7 | OrphanedSlideMasterRule + test | 2 new | medium |
| 8 | Integration test PropaleFailed | 1 new | medium |
| 9 | PPTXComparator + CompareReport | 2 new | medium |
| 10 | CLI pptx-doctor.php | 1 new | quick |
| 11 | Full test suite + CI | 0 new | quick |
