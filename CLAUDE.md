# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Cristal PPTX is a PHP library for manipulating PowerPoint (PPTX) files. It allows copying slides between presentations, applying templating with Mustache-style tags, and merging multiple PPTX files while maintaining proper relationships between resources.

**Key capabilities:**
- Merge slides from multiple PPTX files
- Template replacement using `{{variable}}` syntax with dot notation support
- Image deduplication and optimization
- Preserve sections, notes, and structural resources
- Support for modern media types (audio, video, SVG, charts, comments)

## Development Commands

### Testing
```bash
# Run all tests
composer test
# OR
vendor/bin/phpunit

# Run a specific test
vendor/bin/phpunit tests/PPTXTest.php

# Run a specific test method
vendor/bin/phpunit --filter it_merges_two_pptx
```

### Code Quality
```bash
# Run all checks (format, analyze, test)
composer ci

# Format code (PSR-12)
composer format
# OR
vendor/bin/php-cs-fixer fix

# Static analysis (level 6)
composer analyse
# OR
vendor/bin/phpstan analyse --level=6
```

### Requirements
- PHP >= 8.1
- Extensions: `simplexml`, `zip`
- Optional: `gd`, `fileinfo` (for image features)

## Architecture

### Core Design: Resource Tree Pattern

The library uses a **resource tree pattern** where each PPTX file is a collection of interconnected resources. When merging presentations, the library must:

1. **Clone or reuse resources intelligently** - Structural resources (SlideMasters, NoteMasters, Themes) are deduplicated by content hash, while Slides are always cloned
2. **Update references** - After cloning, all relationship IDs must be updated to point to resources in the destination document
3. **Maintain OPC compliance** - Slides must be added before other resources to ensure correct rId numbering (slides get rId2-N, system resources get rId N+1...)

### Key Classes

#### `PPTX` (Main Entry Point)
- `Presentation/PPTX.php` - Primary interface for manipulating PPTX files
- Creates temporary copy of source file for all operations
- Manages the resource tree through `ContentType`
- Core methods:
  - `addSlide()` / `addSlides()` - Add slides from another presentation
  - `template()` - Apply Mustache-style templating
  - `saveAs()` - Save the modified presentation

#### Resource Hierarchy
All resources extend `GenericResource` or `XmlResource`:

**XML Resources** (extend `XmlResource`):
- `Slide` - Individual presentation slides with templating support
- `SlideMaster` - Master slide layouts (reused when identical)
- `SlideLayout` - Specific layout variants
- `Theme` - Presentation themes
- `NoteMaster` / `NoteSlide` - Speaker notes
- `Presentation` - Main presentation.xml coordinator

**Media Resources** (extend `GenericResource`):
- `Image` - Image files with optimization and deduplication via content hash
- `Audio` / `Video` - Media files
- `SvgImage` - SVG with PNG conversion fallback
- `Chart` - DrawingML chart data

**Metadata Resources**:
- `ContentType` - Manages [Content_Types].xml and resource registration
- `AppProperties` - Tracks slide/notes counts in docProps/app.xml
- `CoreProperties` - Dublin Core metadata
- `Comment` / `CommentAuthor` - Presentation comments

### Critical Workflows

#### Merging Presentations (`addSlides`)
Located in `PPTX.php`:

1. **Collect section data** - `collectSectionData()` preserves section information before processing
2. **For each slide** - Call `addSlide()` which calls `processResourceTree()`
3. **Build resource tree** - `getResourceTree()` recursively finds all dependencies
   - Stops at reusable SlideMasters/NoteMasters
   - Marks Themes for force-clone when master is new
   - Prevents circular references (NoteSlide ↔ Slide)
4. **Clone/reuse resources** - `cloneOrReuseResource()` checks for duplicates:
   - Images: deduplicated via content hash (fast in-memory cache)
   - SlideMasters/NoteMasters: reused if identical by content
   - SlideLayouts/Themes: reused if found by content hash
   - Slides: always cloned (unique content)
5. **Update references** - `updateResourceReferences()` updates all rIds to point to destination resources
6. **Register with presentation** - Add slides first (rId order), then system resources
7. **Save and refresh** - Persist changes and reload from temporary file

#### Resource Deduplication
Key locations:
- `PPTX::cloneOrReuseResource()` - Main deduplication logic
- `ContentType::lookForSimilarFile()` - Content hash comparison for XML resources
- `ImageCache` - Fast LRU cache for image deduplication
- `PPTX::shouldReuseXmlResource()` - Determines which XML resources can be reused

#### Templating System (`Slide::template`)
Located in `Slide.php`:
- Supports Mustache-style syntax: `{{variable}}`
- Dot notation for nested data: `{{user.name}}`
- Processes through `replaceNeedle()` with HTML escaping
- Also supports table row templating and image replacement

### Important Constraints

**Circular Reference Prevention:**
- NoteSlides reference their parent Slide
- `getResourceTree()` stops traversing NoteSlide children to prevent double-cloning of Slides

**OPC Compliance:**
- Slides MUST be registered before system resources (masters, themes, props)
- This ensures slides get rIds 2-N and system resources get rIds N+1+
- See `registerResourcesWithPresentation()` in `PPTX.php`

**Sequential Numbering:**
- Slide IDs are normalized to sequential values (256, 257, ...) before save
- NoteSlide filenames are automatically numbered (notesSlide1.xml, notesSlide2.xml, ...)
- Section references are updated to match new slide IDs

**Content Hash for Deduplication:**
- All structural XML resources use content hashing for deduplication
- Images use binary content hash for faster duplicate detection
- Hash comparison in `ContentType::lookForSimilarFile()`

### Configuration & Optimization

The library supports optimization through `OptimizationConfig`:
- `deduplicate_images` - Enable image deduplication (default: true)
- `validate_images` - Enable image validation (default: false)
- `collect_stats` - Collect optimization statistics (default: false)
- `lazy_loading` - Lazy load resources (default: true)

Pass options in constructor:
```php
$pptx = new PPTX('file.pptx', [
    'deduplicate_images' => true,
    'collect_stats' => true
]);
```

## Code Style Notes

- **Strict types**: All files use `declare(strict_types=1);`
- **Type hints**: Always use explicit return and parameter types
- **Arrays**: Use typed array docblocks (`@var Slide[]`, `@return array<string, ResourceInterface>`)
- **Exceptions**: Custom exceptions in `Presentation/Exception/`
- **PHP 8.1+**: Uses readonly properties, constructor property promotion

## Testing Strategy

Tests are located in `tests/` and use PHPUnit:
- `PPTXTest.php` - Main integration tests for merging, templating, optimization
- `PPTXValidationTest.php` - Structural validation tests (OPC compliance, sections, layouts)
- `SlideTest.php` - Slide-specific tests
- `NoteSlideTest.php` - NoteSlide functionality tests
- Mock files in `tests/mock/` (DEBUT.pptx, FIN.pptx, MILIEU.pptx, garde.pptx, aquitaine.pptx)
- Output files in `tests/tmp/`

### Validation Tests (PPTXValidationTest.php)

These tests verify PPTX structural integrity:
- `validateSlideMasterReferences()` - All masters in sldMasterIdLst exist
- `validateSlideLayoutToMasterReferences()` - Layouts reference existing masters
- `validateMasterLayoutBidirectional()` - Master↔Layout bidirectional consistency
- `validateNoteSlideReferences()` - NotesSlides reference existing notesMaster
- `validateSectionConsistency()` - All slides in sections when sectionLst exists
- `validateSldLayoutIdLstConsistency()` - sldLayoutIdLst matches .rels (no duplicates)
- `validateRIdOrdering()` - Masters before slides in rId ordering

Test structure:
```php
public function it_does_something(): void
{
    // Arrange
    $pptx = new PPTX(__DIR__ . '/mock/DEBUT.pptx');

    // Act
    $pptx->addSlides($otherPptx->getSlides());

    // Assert
    $this->assertEquals($expected, $actual);
}
```

## Common Pitfalls

1. **Don't modify resources directly in the archive** - Always use the PPTX API methods
2. **RefreshSource() is expensive** - Batch operations use `addSlidesBatch()` to refresh only once
3. **Content hash must match exactly** - Even whitespace differences prevent resource reuse
4. **Relationship IDs are fragile** - Never manually edit rIds; use `setResource()` methods
5. **Sections are complex** - They reference slides by ID, which changes during merge; handled by `rebuildSectionsFromCollectedData()`
6. **SlideMaster cloning pitfall** - When cloning, must clear both `$resources` array AND `sldLayoutIdLst` in XML to avoid duplicates
7. **Mixed section presentations** - Merging files where one has sections and another doesn't requires auto-creating default section for orphan slides

## Git Branch Context

- **Main branch**: `master`
- **Current branch**: `fork_zirymy`
- Recent work includes NoteSlide support, metadata handling, and merge fixes

## Recent Changes

The fork includes significant improvements:
- Full NoteSlide support with proper numbering
- Section preservation during merge
- Circular reference prevention for NoteSlide ↔ Slide
- OPC validation and compliance
- Enhanced media support (audio, video, SVG, charts)
- Content hash-based deduplication for all structural resources

### Critical Fixes (2026-01-25)

**Fix 1: rId Reordering for OPC Compliance**
- **Problem**: rIds were assigned in wrong order (system resources before slides), causing PowerPoint corruption
- **Solution**: Automatic rId reordering in `saveAs()` via `reorderPresentationRIds()`
- **Impact**: Fixes 80% of corruption issues
- **Location**: `Presentation/PPTX.php:791-841`, `Presentation/Resource/Presentation.php:450-515`

**Fix 2: NoteSlide ↔ Slide Reference Correction**
- **Problem**: NoteSlides pointed to wrong Slides after cloning and renaming
- **Solution**: Special handling in `updateResourceReferences()` with smart matching by sourceSlideId
- **Impact**: Fixes broken bidirectional references
- **Location**: `Presentation/PPTX.php:381-588` (includes 4 auxiliary methods)

### Critical Fixes (2026-02-10)

**Fix 3: Section Orphan Slides**
- **Problem**: When merging presentations where one has sections and another doesn't, slides without section info become "orphans". PPTX rule: when `<p14:sectionLst>` exists, ALL slides must be in a section.
- **Solution**: Auto-create "Section par défaut" for orphan slides in `rebuildSectionsFromCollectedData()`
- **Location**: `Presentation/Resource/Presentation.php:298-384`
- **Example**: Merging `garde.pptx` (no sections) + `aquitaine.pptx` (with sections) → slides from garde.pptx are placed in "Section par défaut"

**Fix 4: SlideMaster sldLayoutIdLst Duplication**
- **Problem**: When cloning a SlideMaster, the `<p:sldLayoutIdLst>` in the XML wasn't cleared. Old layout references remained and new ones were appended, causing:
  - Duplicate rId entries (22 entries instead of 11)
  - Invalid reference to theme (rId1) in layout list
- **Solution**: Added `clearSldLayoutIdLst()` method called in `__clone()` to clear existing entries before adding new ones
- **Location**: `Presentation/Resource/SlideMaster.php:35-56` (clone), `106-127` (clear method)
- **Symptom**: PowerPoint repair removes the problematic SlideMaster and its exclusive layouts

### PPTX Structure Rules (Important for Debugging)

1. **Sections**: When `<p14:sectionLst>` exists in presentation.xml, ALL slides must be referenced in a section
2. **SlideMaster sldLayoutIdLst**: Must match exactly the slideLayout relationships in the .rels file (no duplicates, no theme references)
3. **Bidirectional consistency**: If SlideMaster claims a SlideLayout, that layout must reference back to the same master
4. **rId ordering**: SlideMasters first, then Slides, then system resources (notesMaster, themes, props)

**Verification Tools**:
- `tools/verify_rids.php` - Checks OPC compliance of rId ordering
- `tools/verify_noteslides.php` - Verifies NoteSlide ↔ Slide reference consistency
- `tests/PPTXValidationTest.php` - Comprehensive validation tests for all rules above

**Documentation**:
- `ANALYSE_CORRUPTION.md` - Detailed analysis of corruption issues
- `CORRECTION1_SUCCESS.md` - rId reordering implementation report
- `CORRECTION2_SUCCESS.md` - NoteSlide reference fix report
- `CORRECTIONS_FINALES.md` - Final comprehensive report
