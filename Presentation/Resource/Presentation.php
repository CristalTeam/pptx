<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\ResourceInterface;

/**
 * Presentation resource class for handling the main presentation.xml file.
 */
class Presentation extends XmlResource
{
    /**
     * Track if sections have been removed to avoid repeated removal
     */
    private static bool $sectionsRemoved = false;

    /**
     * Add a resource to the presentation.
     *
     * @param ResourceInterface $resource Resource to add
     * @return string|null The resource ID or null
     */
    public function addResource(ResourceInterface $resource): ?string
    {
        // Remove sections on first slide addition to avoid incorrect ordering
        if ($resource instanceof Slide && !self::$sectionsRemoved) {
            $this->removeSections();
            self::$sectionsRemoved = true;
        }

        if ($resource instanceof NoteMaster) {
            // PowerPoint only supports ONE NoteMaster per presentation
            // Check if a notesMaster already exists and reuse it
            $existing = $this->content->xpath('p:notesMasterIdLst/p:notesMasterId/@r:id');
            if (!empty($existing)) {
                // NoteMaster already exists, return its rId
                return (string)$existing[0];
            }

            // No NoteMaster exists yet, add this one
            $rId = parent::addResource($resource);

            // Create notesMasterIdLst if it doesn't exist
            if (!count($this->content->xpath('p:notesMasterIdLst'))) {
                $this->content->addChild('p:notesMasterIdLst');
            }

            // Add the notesMasterId to the list
            $ref = $this->content->xpath('p:notesMasterIdLst')[0]->addChild('notesMasterId');
            $ref->addAttribute('r:id', $rId, $this->namespaces['r']);

            return $rId;
        }

        if ($resource instanceof Slide) {
            // CRITICAL: Slides must have consecutive rIds (rId2, rId3, rId4...)
            // Find the next available rId specifically for slides
            $rId = $this->getNextSlideRId();

            // Manually add to resources array (bypass parent::addResource which uses max+1)
            $this->resources[$rId] = $resource;

            $currentSlides = $this->content->xpath('p:sldIdLst/p:sldId');

            // PowerPoint slide IDs must be unique and >= 256
            // Find the maximum existing ID and increment it
            $maxId = 255; // Minimum value is 256
            foreach ($currentSlides as $slide) {
                $existingId = (int)$slide['id'];
                if ($existingId > $maxId) {
                    $maxId = $existingId;
                }
            }
            $nextId = $maxId + 1;

            $ref = $this->content->xpath('p:sldIdLst')[0]->addChild('sldId');
            $ref->addAttribute('id', (string) $nextId);
            $ref->addAttribute('r:id', $rId, $this->namespaces['r']);

            // DISABLED: Section copying during merge causes incorrect slide order in sections
            // Sections are organizational features that should be recreated manually after merge
            // The problem is that slides are added to sections in processing order, not final order
            // TODO: Implement proper section reconstruction after all slides are added
            // $sourceSection = $resource->getSourceSection();
            // if ($sourceSection !== null) {
            //     $this->addSlideToSection($nextId, $sourceSection['name'], $sourceSection['id']);
            // }

            return $rId;
        }

        if ($resource instanceof SlideMaster) {
            // Check if this SlideMaster is already registered
            $existingRId = $this->findExistingResourceId($resource);
            if ($existingRId !== null) {
                return $existingRId;
            }

            $rId = parent::addResource($resource);

            $ref = $this->content->xpath('p:sldMasterIdLst')[0]->addChild('sldMasterId');
            $ref->addAttribute('id', (string) self::getUniqueID());
            $ref->addAttribute('r:id', $rId, $this->namespaces['r']);

            return $rId;
        }

        if ($resource instanceof HandoutMaster) {
            $rId = parent::addResource($resource);
            $ref = $this->content->xpath('p:handoutMasterIdLst')[0]->addChild('handoutMasterId');
            $ref->addAttribute('r:id', $rId, $this->namespaces['r']);

            return $rId;
        }

        // For all other resources (presProps, viewProps, theme, tableStyles, etc.),
        // use the parent's generic addResource() to register them in the .rels file
        return parent::addResource($resource);
    }

    /**
     * Remove all sections from the presentation.
     * Sections become invalid when merging presentations, so they should be removed.
     */
    protected function removeSections(): void
    {
        // Register p14 namespace
        $this->content->registerXPathNamespace('p14', 'http://schemas.microsoft.com/office/powerpoint/2010/main');

        // Find the ext element containing sectionLst
        $extElements = $this->content->xpath('//p:ext[@uri="{521415D9-36F7-43E2-AB2F-B90AF26B5E84}"]');

        if (!empty($extElements)) {
            // Remove this ext element (contains sections)
            $dom = dom_import_simplexml($extElements[0]);
            $dom->parentNode->removeChild($dom);
        }
    }

    /**
     * Rebuild sections from slide metadata after merge.
     * This method should be called AFTER all slides have been added to ensure
     * slide IDs are finalized.
     */
    public function rebuildSectionsFromSlides(): void
    {
        $this->mapResources();

        // Collect sections from all slides
        $sections = [];  // ['sectionName' => ['guid' => 'xxx', 'slideIds' => [...]]]

        // Get all slides in order
        $slideIds = $this->content->xpath('p:sldIdLst/p:sldId');

        foreach ($slideIds as $sldIdNode) {
            $slideId = (int)$sldIdNode['id'];
            $rId = (string)$sldIdNode->attributes($this->namespaces['r'])->id;

            // Get the slide resource
            if (!isset($this->resources[$rId])) {
                continue;
            }

            $slide = $this->resources[$rId];
            if (!($slide instanceof Slide)) {
                continue;
            }

            // Get section info from slide
            $sectionInfo = $slide->getSourceSection();
            if ($sectionInfo === null) {
                continue;
            }

            $sectionName = $sectionInfo['name'];
            $sectionGuid = $sectionInfo['id'];

            // Add slide to section
            if (!isset($sections[$sectionName])) {
                $sections[$sectionName] = [
                    'guid' => $sectionGuid,
                    'slideIds' => []
                ];
            }

            $sections[$sectionName]['slideIds'][] = $slideId;
        }

        // If no sections, nothing to do
        if (empty($sections)) {
            return;
        }

        // Remove old sections
        $this->removeSections();

        // Register p14 namespace
        $this->content->registerXPathNamespace('p14', 'http://schemas.microsoft.com/office/powerpoint/2010/main');

        // Create extLst if it doesn't exist
        $extLst = $this->content->xpath('p:extLst');
        if (empty($extLst)) {
            $extLst = $this->content->addChild('p:extLst');
        } else {
            $extLst = $extLst[0];
        }

        // Create ext element for sections
        $ext = $extLst->addChild('ext', null, 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $ext->addAttribute('uri', '{521415D9-36F7-43E2-AB2F-B90AF26B5E84}');

        // Create sectionLst
        $sectionLst = $ext->addChild('p14:sectionLst', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');

        // Add each section
        foreach ($sections as $sectionData) {
            $section = $sectionLst->addChild('section', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');
            $section->addAttribute('name', $sectionData['name']);
            $section->addAttribute('id', $sectionData['guid']);

            // Add sldIdLst to section
            $sldIdLst = $section->addChild('sldIdLst', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');

            // Add all slide IDs to this section
            foreach ($sectionData['slideIds'] as $slideId) {
                $sldId = $sldIdLst->addChild('sldId', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');
                $sldId->addAttribute('id', (string)$slideId);
            }
        }
    }

    /**
     * Rebuild sections from collected section data (survives refreshSource).
     * This method uses pre-collected section data instead of relying on in-memory slide metadata.
     *
     * @param array $sectionData Array mapping source slide IDs to section info ['name' => ..., 'id' => ...]
     */
    /**
     * Extract existing sections from presentation.xml before merge.
     * Returns array mapping slide indices to section info.
     *
     * @return array Array mapping slide index to section info ['name' => ..., 'id' => ...]
     */
    public function extractExistingSections(): array
    {
        $existingSections = [];

        // Register namespaces
        $this->content->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $this->content->registerXPathNamespace('p14', 'http://schemas.microsoft.com/office/powerpoint/2010/main');

        // Get all slide IDs in order
        $slideNodes = $this->content->xpath('p:sldIdLst/p:sldId');
        $slideIdToIndex = [];
        $index = 0;
        foreach ($slideNodes as $slideNode) {
            $slideId = (int)$slideNode['id'];
            $slideIdToIndex[$slideId] = $index;
            $index++;
        }

        // Extract sections
        $sections = $this->content->xpath('//p14:sectionLst/p14:section');
        if (empty($sections)) {
            return [];
        }

        foreach ($sections as $section) {
            $sectionName = (string)$section['name'];
            $sectionGuid = (string)$section['id'];

            // Get slide IDs in this section
            $sectionSlides = $section->xpath('.//p14:sldIdLst/p14:sldId');
            foreach ($sectionSlides as $sectionSlide) {
                $slideId = (int)$sectionSlide['id'];
                if (isset($slideIdToIndex[$slideId])) {
                    $slideIndex = $slideIdToIndex[$slideId];
                    $existingSections[$slideIndex] = [
                        'name' => $sectionName,
                        'id' => $sectionGuid
                    ];
                }
            }
        }

        return $existingSections;
    }

    /**
     * Rebuild sections from collected section data.
     * Merges existing sections with new sections from added slides.
     *
     * @param array $existingSections Array mapping slide index to section info from current presentation
     * @param array $newSections Array mapping slide index to section info from new slides
     */
    public function rebuildSectionsFromCollectedData(array $existingSections, array $newSections = []): void
    {
        // Merge existing and new section data
        $allSectionData = $existingSections + $newSections;

        if (empty($allSectionData)) {
            return;
        }

        // Get all slides in order from presentation.xml
        $slideNodes = $this->content->xpath('p:sldIdLst/p:sldId');

        // Build array of final slide IDs in order
        $finalSlideIds = [];
        foreach ($slideNodes as $sldIdNode) {
            $finalSlideIds[] = (int)$sldIdNode['id'];
        }

        // Collect sections - map slides by index
        $sections = [];  // ['sectionKey' => ['name' => ..., 'guid' => ..., 'slideIds' => [...]]]

        foreach ($allSectionData as $index => $sectionInfo) {
            // Skip if we don't have a corresponding final slide
            if (!isset($finalSlideIds[$index])) {
                continue;
            }

            $finalSlideId = $finalSlideIds[$index];
            $sectionName = $sectionInfo['name'];
            $sectionGuid = $sectionInfo['id'];

            // Create unique key: name + guid to handle multiple sections with same name
            $sectionKey = $sectionName . '_' . $sectionGuid;

            // Add slide to section
            if (!isset($sections[$sectionKey])) {
                $sections[$sectionKey] = [
                    'name' => $sectionName,
                    'guid' => $sectionGuid,
                    'slideIds' => []
                ];
            }

            $sections[$sectionKey]['slideIds'][] = $finalSlideId;
        }

        if (empty($sections)) {
            return;
        }

        // Remove old sections
        $this->removeSections();

        // Register p14 namespace
        $this->content->registerXPathNamespace('p14', 'http://schemas.microsoft.com/office/powerpoint/2010/main');

        // Create extLst if it doesn't exist
        $extLst = $this->content->xpath('p:extLst');
        if (empty($extLst)) {
            $extLst = $this->content->addChild('p:extLst');
        } else {
            $extLst = $extLst[0];
        }

        // Create ext element for sections
        $ext = $extLst->addChild('ext', null, 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $ext->addAttribute('uri', '{521415D9-36F7-43E2-AB2F-B90AF26B5E84}');

        // Create sectionLst
        $sectionLst = $ext->addChild('p14:sectionLst', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');

        // Add each section
        foreach ($sections as $sectionData) {
            $section = $sectionLst->addChild('section', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');
            $section->addAttribute('name', $sectionData['name']);
            $section->addAttribute('id', $sectionData['guid']);

            // Add sldIdLst to section
            $sldIdLst = $section->addChild('sldIdLst', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');

            // Add all slide IDs to this section
            foreach ($sectionData['slideIds'] as $slideId) {
                $sldId = $sldIdLst->addChild('sldId', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');
                $sldId->addAttribute('id', (string)$slideId);
            }
        }
    }

    /**
     * Add a slide to a section in the sectionLst.
     * Creates the section if it doesn't exist.
     *
     * @param int $slideId The slide ID to add
     * @param string $sectionName The section name
     * @param string $sectionGuid The section GUID
     */
    protected function addSlideToSection(int $slideId, string $sectionName, string $sectionGuid): void
    {
        // Register p14 namespace
        $this->content->registerXPathNamespace('p14', 'http://schemas.microsoft.com/office/powerpoint/2010/main');
        
        // Find existing sectionLst in extLst
        $sectionLst = $this->content->xpath('//p14:sectionLst');
        
        if (empty($sectionLst)) {
            // No sections exist yet - we need to create extLst and sectionLst
            // This is complex - for now we'll just skip if no sections exist
            return;
        }
        
        $sectionLst = $sectionLst[0];
        
        // Find section by name
        $existingSection = $sectionLst->xpath("p14:section[@name='$sectionName']");
        
        if (!empty($existingSection)) {
            // Section exists - add slide ID to it
            $section = $existingSection[0];
        } else {
            // Create new section
            $section = $sectionLst->addChild('section', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');
            $section->addAttribute('name', $sectionName);
            $section->addAttribute('id', $sectionGuid);
            
            // Add sldIdLst to section
            $section->addChild('sldIdLst', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');
        }
        
        // Add slide ID to section's sldIdLst
        $sldIdLst = $section->xpath('p14:sldIdLst');
        if (!empty($sldIdLst)) {
            $sldId = $sldIdLst[0]->addChild('sldId', null, 'http://schemas.microsoft.com/office/powerpoint/2010/main');
            $sldId->addAttribute('id', (string) $slideId);
        }
    }

    /**
     * Get the next available rId for a slide.
     * Slides should have consecutive rIds, but must not collide with other resources.
     *
     * @return string The next available rId for a slide (e.g., 'rId2', 'rId3', 'rId4'...)
     */
    private function getNextSlideRId(): string
    {
        $this->mapResources();

        // Get all existing rIds (slides and non-slides)
        $allUsedIds = [];
        foreach ($this->resources as $rId => $resource) {
            $allUsedIds[] = (int)str_replace('rId', '', $rId);
        }

        // Find all existing slide rIds
        $slideRIds = [];
        foreach ($this->resources as $rId => $resource) {
            if ($resource instanceof Slide) {
                $slideRIds[] = (int)str_replace('rId', '', $rId);
            }
        }

        // Start from rId2 (rId1 is usually slideMaster)
        $nextId = 2;

        // Try to find the next consecutive rId for slides
        // but skip any rId that's already in use by ANY resource
        sort($slideRIds);
        foreach ($slideRIds as $existingId) {
            if ($existingId == $nextId && !in_array($nextId, $allUsedIds, true)) {
                $nextId++;
            } else if (in_array($nextId, $allUsedIds, true)) {
                // This rId is used by another resource, skip it
                $nextId++;
            } else {
                // Found a gap in slide sequence
                break;
            }
        }

        // Final check: ensure the proposed rId is not in use
        while (in_array($nextId, $allUsedIds, true)) {
            $nextId++;
        }

        return 'rId' . $nextId;
    }

    /**
     * Find if a resource is already registered in this presentation.
     * Used to avoid duplicating structural resources (Masters, Themes, etc.).
     *
     * @param ResourceInterface $resource The resource to check
     * @return string|null The existing resource ID if found, null otherwise
     */
    private function findExistingResourceId(ResourceInterface $resource): ?string
    {
        $this->mapResources();

        // For GenericResource, compare by target path to detect reused resources
        if ($resource instanceof GenericResource) {
            foreach ($this->resources as $rId => $existingResource) {
                if ($existingResource instanceof GenericResource &&
                    $existingResource->getTarget() === $resource->getTarget()) {
                    return $rId;
                }
            }
        }

        // For other resources, compare by reference (original behavior)
        foreach ($this->resources as $rId => $existingResource) {
            if ($existingResource === $resource) {
                return $rId;
            }
        }

        return null;
    }

    /**
     * Remap all resource IDs according to the provided mapping.
     * Updates both the .rels file and the XML content references.
     *
     * This method is used to reorganize rIds to follow PowerPoint OPC conventions:
     * - SlideMasters should have rId1
     * - Slides should have consecutive rIds (rId2, rId3, rId4, ...)
     * - System resources should come after slides
     *
     * @param array<string, string> $mapping Old rId => New rId
     * @throws Exception
     */
    public function remapResourceIds(array $mapping): void
    {
        // Step 1: Remap internal resources array
        $newResources = [];
        foreach ($this->resources as $oldRId => $resource) {
            $newRId = $mapping[$oldRId] ?? $oldRId;
            $newResources[$newRId] = $resource;
        }
        $this->resources = $newResources;

        // Step 2: Update sldIdLst in presentation.xml
        // CRITICAL: Must reorder <p:sldId> elements to match new rId sequence
        // PowerPoint expects slides in order: rId2, rId3, rId4, ...
        $slides = $this->content->xpath('p:sldIdLst/p:sldId');

        // First, update all r:id attributes and collect slide info
        $slideData = [];
        foreach ($slides as $sldId) {
            $oldRId = (string)$sldId->attributes($this->namespaces['r'])->id;
            $newRId = $mapping[$oldRId] ?? $oldRId;
            $slideId = (string)$sldId['id'];

            // Update the r:id attribute
            $sldId->attributes($this->namespaces['r'])->id = $newRId;

            // Store slide data for reordering
            $slideData[] = [
                'element' => $sldId,
                'rId' => $newRId,
                'id' => $slideId,
            ];
        }

        // Sort by new rId (extract numeric part for sorting)
        usort($slideData, function ($a, $b) {
            $numA = (int) preg_replace('/[^0-9]/', '', $a['rId']);
            $numB = (int) preg_replace('/[^0-9]/', '', $b['rId']);
            return $numA <=> $numB;
        });

        // Rebuild the sldIdLst in correct order
        $sldIdLst = $this->content->xpath('p:sldIdLst')[0];

        // Remove all existing <p:sldId> elements
        foreach ($slides as $sldId) {
            unset($sldId[0]);
        }

        // Re-add them in sorted order
        foreach ($slideData as $data) {
            $newSldId = $sldIdLst->addChild('p:sldId', null, $this->namespaces['p']);
            $newSldId->addAttribute('id', $data['id']);
            $newSldId->addAttribute('r:id', $data['rId'], $this->namespaces['r']);
        }

        // Step 3: Update sldMasterIdLst
        $masters = $this->content->xpath('p:sldMasterIdLst/p:sldMasterId');
        foreach ($masters as $masterId) {
            $oldRId = (string)$masterId->attributes($this->namespaces['r'])->id;
            if (isset($mapping[$oldRId])) {
                $masterId->attributes($this->namespaces['r'])->id = $mapping[$oldRId];
            }
        }

        // Step 4: Update notesMasterIdLst
        $noteMasters = $this->content->xpath('p:notesMasterIdLst/p:notesMasterId');
        foreach ($noteMasters as $noteId) {
            $oldRId = (string)$noteId->attributes($this->namespaces['r'])->id;
            if (isset($mapping[$oldRId])) {
                $noteId->attributes($this->namespaces['r'])->id = $mapping[$oldRId];
            }
        }

        // Step 5: Update handoutMasterIdLst if present
        $handoutMasters = $this->content->xpath('p:handoutMasterIdLst/p:handoutMasterId');
        foreach ($handoutMasters as $handoutId) {
            $oldRId = (string)$handoutId->attributes($this->namespaces['r'])->id;
            if (isset($mapping[$oldRId])) {
                $handoutId->attributes($this->namespaces['r'])->id = $mapping[$oldRId];
            }
        }

        // Step 6: Force regeneration of .rels file with new IDs
        // Mark as draft to ensure save() regenerates the .rels file
        $this->isDraft = true;
        $this->save();
    }
}
