<?php

declare(strict_types=1);

namespace Cristal\Presentation;

use Closure;
use Cristal\Presentation\Cache\ImageCache;
use Cristal\Presentation\Config\OptimizationConfig;
use Cristal\Presentation\Exception\FileOpenException;
use Cristal\Presentation\Exception\FileSaveException;
use Cristal\Presentation\Resource\AppProperties;
use Cristal\Presentation\Resource\Audio;
use Cristal\Presentation\Resource\Chart;
use Cristal\Presentation\Resource\ContentType;
use Cristal\Presentation\Resource\GenericResource;
use Cristal\Presentation\Resource\Image;
use Cristal\Presentation\Resource\NoteMaster;
use Cristal\Presentation\Resource\NoteSlide;
use Cristal\Presentation\Resource\Presentation;
use Cristal\Presentation\Resource\Slide;
use Cristal\Presentation\Resource\SlideLayout;
use Cristal\Presentation\Resource\SlideMaster;
use Cristal\Presentation\Resource\SvgImage;
use Cristal\Presentation\Resource\Theme;
use Cristal\Presentation\Resource\Video;
use Cristal\Presentation\Resource\XmlResource;
use Cristal\Presentation\Stats\OptimizationStats;
use Cristal\Presentation\Validator\ImageValidator;
use Cristal\Presentation\Validator\PresentationValidator;
use Exception;
use ZipArchive;

/**
 * Main class for manipulating PowerPoint (PPTX) files.
 */
class PPTX
{
    protected ZipArchive $archive;

    /** @var Slide[] */
    protected array $slides = [];

    protected Presentation $presentation;

    protected string $filename;

    protected string $tmpName;

    protected ContentType $contentType;

    protected OptimizationConfig $config;

    protected ImageCache $imageCache;

    protected OptimizationStats $stats;

    protected ?PresentationValidator $validator = null;

    /**
     * PPTX constructor.
     *
     * @param string $path Path to the PPTX file
     * @param array $options Optimization options (optional)
     * @throws FileOpenException
     */
    public function __construct(string $path, array $options = [])
    {
        $this->filename = $path;
        $this->config = new OptimizationConfig($options);
        $this->imageCache = new ImageCache();
        $this->stats = new OptimizationStats();

        // Initialize validator if enabled
        if ($this->config->isEnabled('validate_images')) {
            $this->validator = new PresentationValidator($this->config);
        }

        if (!file_exists($path)) {
            throw new FileOpenException('Unable to open the source PPTX. Path does not exist.');
        }

        // Create tmp copy
        $this->tmpName = tempnam(sys_get_temp_dir(), 'PPTX_');

        copy($path, $this->tmpName);

        // Open copy
        $this->openFile($this->tmpName);
    }

    /**
     * Open a PPTX file.
     *
     * @throws FileOpenException
     */
    public function openFile(string $path): self
    {
        $this->archive = new ZipArchive();
        $res = $this->archive->open($path);

        if ($res !== true) {
            throw new FileOpenException($this->archive->getStatusString());
        }

        $this->contentType = new ContentType($this);
        $resource = $this->contentType->getResource('ppt/presentation.xml');
        if (!$resource instanceof Presentation) {
            throw new FileOpenException('Invalid presentation file: ppt/presentation.xml');
        }
        $this->presentation = $resource;

        $this->loadSlides();

        return $this;
    }

    /**
     * Read existing slides.
     */
    protected function loadSlides(): self
    {
        $this->slides = [];
        
        // Build a map of slideId -> section info
        $slideSections = $this->extractSlideSections();

        foreach ($this->presentation->getXmlContent()->xpath('p:sldIdLst/p:sldId') as $slide) {
            $id = $slide->xpath('@r:id')[0]['id'] . '';
            $slideId = (int) $slide['id'];
            $resource = $this->presentation->getResource($id);
            if ($resource instanceof Slide) {
                // Set section info if available
                if (isset($slideSections[$slideId])) {
                    $section = $slideSections[$slideId];
                    $resource->setSourceSection($section['name'], $section['id']);
                    $resource->setSourceSlideId($slideId);
                }
                $this->slides[] = $resource;
            }
        }

        return $this;
    }

    /**
     * Extract section information for each slide from presentation.xml.
     *
     * @return array<int, array{name: string, id: string}> Map of slideId => section info
     */
    protected function extractSlideSections(): array
    {
        $slideSections = [];
        $xml = $this->presentation->getXmlContent();
        
        // Register namespaces for sections (Office 2010+)
        $xml->registerXPathNamespace('p14', 'http://schemas.microsoft.com/office/powerpoint/2010/main');
        
        // Find sectionLst in extLst
        $sections = $xml->xpath('//p14:sectionLst/p14:section');
        
        foreach ($sections as $section) {
            $sectionName = (string) $section['name'];
            $sectionId = (string) $section['id'];
            
            // Get all slide IDs in this section
            foreach ($section->xpath('p14:sldIdLst/p14:sldId') as $sldId) {
                $slideId = (int) $sldId['id'];
                $slideSections[$slideId] = [
                    'name' => $sectionName,
                    'id' => $sectionId,
                ];
            }
        }
        
        return $slideSections;
    }

    /**
     * Get all slides available in the current presentation.
     *
     * @return Slide[]
     */
    public function getSlides(): array
    {
        return $this->slides;
    }


    /**
     * Import a single slide object.
     *
     * @throws Exception
     */
    public function addSlide(Slide $slide): self
    {
        return $this->addResource($slide);
    }

    /**
     * Add a resource and its dependency inside this document.
     */
    public function addResource(GenericResource $res): self
    {
        $this->processResourceTree($res);

        // Save presentation and content type
        $this->presentation->save();
        $this->contentType->save();

        $this->refreshSource();

        return $this;
    }

    /**
     * Process a resource tree: clone, rename, and save all resources.
     *
     * @param GenericResource $res The resource to process
     * @return array<string, ResourceInterface> Array of cloned resources
     */
    protected function processResourceTree(GenericResource $res): array
    {
        // Get the tree with information about which resources must be force-cloned
        $resourceList = [];
        $forceCloneTargets = [];
        $tree = $this->getResourceTree($res, $resourceList, $forceCloneTargets);

        /** @var array<string, ResourceInterface> $clonedResources */
        $clonedResources = [];
        
        /** @var array<string, ResourceInterface> $resourceMapping */
        $resourceMapping = [];

        // Clone, rename, and set new destination
        foreach ($tree as $originalResource) {
            $forceClone = in_array($originalResource->getTarget(), $forceCloneTargets, true);
            $newResource = $this->cloneOrReuseResource($originalResource, $forceClone);
            $clonedResources[$originalResource->getTarget()] = $newResource;
            // Map old target to new resource (for reference updates)
            $resourceMapping[$originalResource->getTarget()] = $newResource;
        }
        
        // Synchronize NoteSlide numbering with their parent Slide
        $this->synchronizeNoteSlideNumbering($clonedResources, $res);

        // Update resource references using the complete mapping
        $this->updateResourceReferences($clonedResources, $resourceMapping);

        // Notify presentation and register slides
        $this->registerResourcesWithPresentation($clonedResources, $res);

        // Save all cloned resources
        $this->saveClonedResources($clonedResources);

        return $clonedResources;
    }
    
    /**
     * Synchronize NoteSlide references.
     *
     * Note: NoteSlides are automatically renamed to sequential numbers (notesSlide1, notesSlide2...)
     * by cloneOrReuseResource() via findAvailableName(). No additional renaming is needed here.
     * This method is kept for potential future reference synchronization logic.
     *
     * @param array<string, ResourceInterface> $clonedResources
     * @param GenericResource $rootResource The root resource being processed (usually a Slide)
     */
    protected function synchronizeNoteSlideNumbering(array $clonedResources, GenericResource $rootResource): void
    {
        // NoteSlides are already correctly numbered by cloneOrReuseResource()
        // using findAvailableName() which ensures sequential numbering.
        // No action needed here.
        return;
    }

    /**
     * Clone or reuse an existing resource.
     *
     * @param ResourceInterface $originalResource The original resource
     * @param bool $forceClone Force cloning even if a similar resource exists
     * @return ResourceInterface The cloned or reused resource
     */
    protected function cloneOrReuseResource(ResourceInterface $originalResource, bool $forceClone = false): ResourceInterface
    {
        if (!$originalResource instanceof GenericResource) {
            return clone $originalResource;
        }

        // If force clone is requested, skip reuse checks
        if (!$forceClone) {
            // Check for image deduplication using content hash
            if ($originalResource instanceof Image) {
                $content = $originalResource->getContent();

                // Check image cache first (fast in-memory lookup)
                if ($this->config->isEnabled('deduplicate_images')) {
                    $duplicate = $this->imageCache->findDuplicate($content);
                    if ($duplicate !== null) {
                        if ($this->config->isEnabled('collect_stats')) {
                            $this->stats->recordDeduplication();
                        }
                        return $duplicate;
                    }
                }

                // Then check for existing similar file in archive (slower: requires ZIP access)
                $existingResource = $this->getContentType()->lookForSimilarFile($originalResource);
                if ($existingResource !== null) {
                    // Register in cache for future lookups
                    if ($existingResource instanceof Image) {
                        $this->imageCache->registerWithContent($existingResource->getContent(), $existingResource);
                    }
                    return $existingResource;
                }
            }

            // Check if resource already exists in the document
            // lookForSimilarFile() handles external resources safely
            $existingResource = $this->getContentType()->lookForSimilarFile($originalResource);

            if ($existingResource !== null) {
                // Always reuse non-XmlResource (images, media, etc.)
                if (!$originalResource instanceof XmlResource) {
                    return $existingResource;
                }
                
                // For XmlResource, reuse structural resources (SlideMasters, NoteMasters)
                // OR SlideLayouts and Themes found by content hash comparison
                if ($this->shouldReuseXmlResource($originalResource)
                    || $originalResource instanceof Theme
                    || $originalResource instanceof SlideLayout) {
                    return $existingResource;
                }
            }
        }

        // Clone and configure the resource
        $resource = clone $originalResource;
        $resource->setDocument($this);
        $resource->rename(basename($this->getContentType()->findAvailableName($resource->getPatternPath())));
        $this->contentType->addResource($resource);

        // Configure optimization for images
        if ($resource instanceof Image) {
            $resource->setOptimizationConfig($this->config);
            $this->imageCache->registerWithContent($resource->getContent(), $resource);
        }

        return $resource;
    }

    /**
     * Determine if an XmlResource should be reused instead of cloned.
     *
     * All structural XML resources (SlideMasters, NoteMasters, SlideLayouts, Themes)
     * are now compared by content hash in lookForSimilarFile(), so if a similar
     * resource is found, it means an IDENTICAL resource exists and should be reused.
     *
     * @param XmlResource $resource The XML resource to check
     * @return bool True if the resource should be reused when found by lookForSimilarFile()
     */
    protected function shouldReuseXmlResource(XmlResource $resource): bool
    {
        // All structural resources should be reused if lookForSimilarFile() found an identical one
        return $resource instanceof SlideMaster
            || $resource instanceof NoteMaster
            || $resource instanceof SlideLayout
            || $resource instanceof Theme;
    }

    /**
     * Update resource references after cloning.
     *
     * This method updates references in ALL resources (both cloned and reused)
     * to ensure they point to the correct resources in the destination document.
     *
     * Critical for merge operations: When a SlideLayout is reused, its internal
     * references to SlideMaster must be updated to point to the reused SlideMaster
     * in the destination document, not the original source document.
     *
     * @param array<string, ResourceInterface> $clonedResources Resources that were cloned or reused
     * @param array<string, ResourceInterface> $resourceMapping Complete mapping of old target -> new resource
     */
    protected function updateResourceReferences(array $clonedResources, array $resourceMapping): void
    {
        // Track which resources need their .rels regenerated
        $resourcesToSave = [];

        // Update references for cloned resources only
        // SKIP SlideMasters - they are REUSED (not cloned) and their references
        // already point to destination resources, not source resources.
        // Updating their references would incorrectly replace existing SlideLayouts
        // with newly cloned ones (e.g., slideLayout12 → slideLayout14)
        foreach ($clonedResources as $resource) {
            if (!($resource instanceof XmlResource)) {
                continue;
            }

            // Skip SlideMasters - their references are already correct
            if ($resource instanceof SlideMaster) {
                continue;
            }

            $needsUpdate = false;
            $currentResources = $resource->getResources();

            foreach ($currentResources as $rId => $subResource) {
                $targetKey = $subResource->getTarget();

                // SPECIAL CASE 1: NoteSlide must point to the cloned Slide, not the original
                if ($resource instanceof NoteSlide && $subResource instanceof Slide) {
                    $clonedSlide = $this->findClonedSlideForNoteSlide($resource, $clonedResources, $resourceMapping);
                    if ($clonedSlide !== null && $clonedSlide !== $subResource) {
                        $resource->setResource($rId, $clonedSlide);
                        $needsUpdate = true;
                        continue; // Skip generic mapping for this rId
                    }
                }

                // SPECIAL CASE 2: Slide must point to the cloned NoteSlide, not the original
                if ($resource instanceof Slide && $subResource instanceof NoteSlide) {
                    $clonedNote = $this->findClonedNoteSlideForSlide($resource, $clonedResources, $resourceMapping);
                    if ($clonedNote !== null && $clonedNote !== $subResource) {
                        $resource->setResource($rId, $clonedNote);
                        $needsUpdate = true;
                        continue; // Skip generic mapping for this rId
                    }
                }

                // Generic mapping for all other resources
                // If we have a mapping for this target, update the reference
                if (array_key_exists($targetKey, $resourceMapping)) {
                    $mappedResource = $resourceMapping[$targetKey];

                    // Only update if the reference changed
                    // (different object or different document)
                    if ($subResource !== $mappedResource ||
                        ($subResource instanceof GenericResource &&
                         $mappedResource instanceof GenericResource &&
                         $subResource->getDocument() !== $mappedResource->getDocument())) {

                        $resource->setResource($rId, $mappedResource);
                        $needsUpdate = true;
                    }
                }
            }

            // If references were updated, force regeneration of .rels file
            if ($needsUpdate) {
                $resourcesToSave[] = $resource;
            }
        }

        // Force save all resources that had reference updates
        // This ensures .rels files are regenerated even for reused resources
        foreach ($resourcesToSave as $resource) {
            // XmlResource::performSave() will regenerate the .rels file
            // We need to call it directly to bypass isDraft() check
            if (method_exists($resource, 'performSave')) {
                $reflection = new \ReflectionMethod($resource, 'performSave');
                $reflection->setAccessible(true);
                $reflection->invoke($resource);
            }
        }
    }

    /**
     * Find the cloned Slide that should be referenced by a NoteSlide.
     * Uses source metadata to match the correct Slide after renaming.
     *
     * When a Slide is cloned and renamed (e.g., slide15 → slide20), the associated
     * NoteSlide must be updated to point to the new Slide name, not the old one.
     *
     * @param NoteSlide $noteSlide The NoteSlide looking for its Slide
     * @param array<string, ResourceInterface> $clonedResources All cloned resources
     * @param array<string, ResourceInterface> $resourceMapping Mapping from original targets to cloned resources
     * @return Slide|null The matching Slide or null
     */
    protected function findClonedSlideForNoteSlide(NoteSlide $noteSlide, array $clonedResources, array $resourceMapping): ?Slide
    {
        // Get the original slide reference from the NoteSlide's resources
        foreach ($noteSlide->getResources() as $resource) {
            if ($resource instanceof Slide) {
                $originalTarget = $resource->getTarget();

                // First, check if there's a direct mapping for this Slide
                if (array_key_exists($originalTarget, $resourceMapping)) {
                    $mappedResource = $resourceMapping[$originalTarget];
                    if ($mappedResource instanceof Slide) {
                        return $mappedResource;
                    }
                }

                // Fallback: Search through cloned resources by sourceSlideId matching
                foreach ($clonedResources as $clonedResource) {
                    if ($clonedResource instanceof Slide) {
                        // Check if this is the same original slide
                        if ($this->isSameOriginalSlide($resource, $clonedResource)) {
                            return $clonedResource;
                        }
                    }
                }

                // If not found in mapping or clonedResources, return the original
                // (this happens when the slide already existed in destination)
                return $resource;
            }
        }

        return null;
    }

    /**
     * Find the cloned NoteSlide that should be referenced by a Slide.
     * Uses filename matching to find the correct NoteSlide after cloning.
     *
     * When a NoteSlide is cloned, the parent Slide must be updated to point
     * to the new NoteSlide, not the old one.
     *
     * @param Slide $slide The Slide looking for its NoteSlide
     * @param array<string, ResourceInterface> $clonedResources All cloned resources
     * @param array<string, ResourceInterface> $resourceMapping Mapping from original targets to cloned resources
     * @return NoteSlide|null The matching NoteSlide or null
     */
    protected function findClonedNoteSlideForSlide(Slide $slide, array $clonedResources, array $resourceMapping): ?NoteSlide
    {
        // Get the original NoteSlide reference from the Slide's resources
        foreach ($slide->getResources() as $resource) {
            if ($resource instanceof NoteSlide) {
                $originalNoteTarget = $resource->getTarget();

                // First, check if there's a direct mapping for this NoteSlide
                if (array_key_exists($originalNoteTarget, $resourceMapping)) {
                    $mappedResource = $resourceMapping[$originalNoteTarget];
                    if ($mappedResource instanceof NoteSlide) {
                        return $mappedResource;
                    }
                }

                // Fallback: Search through cloned resources by filename matching
                // NoteSlides are matched by their sequential numbering (notesSlide1, notesSlide2, etc.)
                foreach ($clonedResources as $clonedResource) {
                    if ($clonedResource instanceof NoteSlide) {
                        // Extract the sequential number from both note slides
                        $originalNumber = $this->extractNoteSlideNumber($originalNoteTarget);
                        $clonedNumber = $this->extractNoteSlideNumber($clonedResource->getTarget());

                        // Match by sequential number OR by exact filename
                        if ($originalNumber !== null && $clonedNumber !== null && $originalNumber === $clonedNumber) {
                            return $clonedResource;
                        }

                        // Fallback: match by exact basename
                        if (basename($originalNoteTarget) === basename($clonedResource->getTarget())) {
                            return $clonedResource;
                        }
                    }
                }

                // If not found in mapping or clonedResources, return the original
                return $resource;
            }
        }

        return null;
    }

    /**
     * Check if two Slides represent the same original slide.
     * Uses sourceSlideId metadata for matching after renaming.
     *
     * @param Slide $slide1 First slide to compare
     * @param Slide $slide2 Second slide to compare
     * @return bool True if both slides represent the same original slide
     */
    protected function isSameOriginalSlide(Slide $slide1, Slide $slide2): bool
    {
        // Strategy 1: Compare by source slide ID (most reliable)
        $id1 = $slide1->getSourceSlideId();
        $id2 = $slide2->getSourceSlideId();

        if ($id1 !== null && $id2 !== null && $id1 === $id2) {
            return true;
        }

        // Strategy 2: Compare by basename (e.g., slide15.xml)
        // This works when slides haven't been renamed
        $basename1 = basename($slide1->getTarget());
        $basename2 = basename($slide2->getTarget());

        if ($basename1 === $basename2) {
            return true;
        }

        // Strategy 3: They are the exact same object
        if ($slide1 === $slide2) {
            return true;
        }

        return false;
    }

    /**
     * Extract the sequential number from a NoteSlide filename.
     * E.g., "ppt/notesSlides/notesSlide2.xml" → 2
     *
     * @param string $target The NoteSlide target path
     * @return int|null The sequential number or null if not found
     */
    protected function extractNoteSlideNumber(string $target): ?int
    {
        // Match pattern: notesSlide{number}.xml
        if (preg_match('/notesSlide(\d+)\.xml$/', $target, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Register resources with presentation and track slides.
     * CRITICAL: Add resources in the correct order to ensure OPC compliance.
     * Slides MUST be added first, then system resources (masters, props, themes).
     *
     * IMPORTANT: Only presentation-level resources should be added to presentation.xml.rels.
     * Child resources (images, layouts, notes) are already linked via their parent's .rels file.
     *
     * @param array<string, ResourceInterface> $clonedResources
     * @param GenericResource $originalResource
     */
    protected function registerResourcesWithPresentation(array $clonedResources, GenericResource $originalResource): void
    {
        // Separate resources by type to control registration order
        $slides = [];
        $presentationLevelResources = [];
        $slideLayouts = [];

        foreach ($clonedResources as $originalTarget => $resource) {
            // Only consider resources that need to be added (not already in presentation)
            if (!($resource instanceof GenericResource) || $this->isResourceAlreadyInPresentation($resource)) {
                // Track slides even if already in presentation
                if ($resource instanceof Slide) {
                    $this->slides[] = $resource;
                }
                continue;
            }

            if ($resource instanceof Slide) {
                $slides[] = $resource;
            } elseif ($resource instanceof SlideLayout) {
                // Collect SlideLayouts to register them with their SlideMaster
                $slideLayouts[] = $resource;
            } elseif ($this->shouldRegisterInPresentationRels($resource)) {
                // Only add resources that belong in presentation.xml.rels
                $presentationLevelResources[] = $resource;
            }
            // Resources not meeting the criteria (images, noteslides, etc.)
            // are already properly linked via their parent resource's .rels file
        }

        // CRITICAL: Add slides FIRST to get rIds 2-N
        foreach ($slides as $slide) {
            $this->presentation->addResource($slide);
            $this->slides[] = $slide;
        }

        // Then add presentation-level system resources (masters, props, themes) to get rIds N+1...
        foreach ($presentationLevelResources as $resource) {
            $this->presentation->addResource($resource);
        }

        // Register SlideLayouts with their parent SlideMaster
        $this->registerSlideLayoutsWithMaster($slideLayouts, $clonedResources);
    }

    /**
     * Register SlideLayouts with their parent SlideMaster.
     *
     * When a SlideLayout is cloned, it must be added to the SlideMaster's .rels file.
     * The SlideLayout already has a relation TO the SlideMaster, but the SlideMaster
     * must also have a relation TO the SlideLayout for OPC compliance.
     *
     * IMPORTANT: The SlideLayout may reference a SlideMaster from the SOURCE document.
     * We must find the corresponding SlideMaster in the DESTINATION document.
     *
     * @param SlideLayout[] $slideLayouts The SlideLayouts to register
     * @param array<string, ResourceInterface> $clonedResources Mapping of cloned resources
     */
    protected function registerSlideLayoutsWithMaster(array $slideLayouts, array $clonedResources): void
    {
        // Track which SlideMasters were modified
        $modifiedMasters = [];

        foreach ($slideLayouts as $slideLayout) {
            // Find the SlideMaster this layout belongs to in the DESTINATION document
            $slideMaster = $this->findDestinationSlideMasterForLayout($slideLayout, $clonedResources);

            if ($slideMaster === null) {
                continue;
            }

            // Check if this SlideLayout is already registered with the SlideMaster
            $alreadyRegistered = false;
            foreach ($slideMaster->getResources() as $existingResource) {
                if ($existingResource instanceof SlideLayout &&
                    $existingResource->getTarget() === $slideLayout->getTarget()) {
                    $alreadyRegistered = true;
                    break;
                }
            }

            if (!$alreadyRegistered) {
                // Add the SlideLayout to the SlideMaster's resources
                $slideMaster->addResource($slideLayout);
                // Track this master as modified
                $masterId = spl_object_id($slideMaster);
                $modifiedMasters[$masterId] = $slideMaster;
            }
        }

        // Force save all modified SlideMasters to persist the .rels changes
        foreach ($modifiedMasters as $master) {
            $master->save();
        }
    }

    /**
     * Find the destination SlideMaster for a SlideLayout.
     *
     * The SlideLayout may reference a SlideMaster from the source document.
     * We need to find the corresponding SlideMaster in the destination document
     * (either cloned or reused).
     *
     * @param SlideLayout $slideLayout The SlideLayout to find the master for
     * @param array<string, ResourceInterface> $clonedResources Mapping of cloned resources
     * @return SlideMaster|null The destination SlideMaster or null if not found
     */
    protected function findDestinationSlideMasterForLayout(SlideLayout $slideLayout, array $clonedResources): ?SlideMaster
    {
        // First, find the SlideMaster the SlideLayout references
        $layoutMaster = null;
        foreach ($slideLayout->getResources() as $resource) {
            if ($resource instanceof SlideMaster) {
                $layoutMaster = $resource;
                break;
            }
        }

        if ($layoutMaster === null) {
            return null;
        }

        // Get the target path from clonedResources mapping (source → dest)
        // This tells us what target path the SlideMaster has in the destination
        $sourceTarget = $layoutMaster->getTarget();
        $destTarget = $sourceTarget; // Default: same path

        if (isset($clonedResources[$sourceTarget])) {
            $mappedResource = $clonedResources[$sourceTarget];
            if ($mappedResource instanceof SlideMaster) {
                $destTarget = $mappedResource->getTarget();
            }
        }

        // CRITICAL: Find the SlideMaster from the presentation's resources
        // by matching the target path. This ensures we get the ACTUAL object
        // that's in the presentation, not a different object with the same path.
        foreach ($this->presentation->getResources() as $resource) {
            if ($resource instanceof SlideMaster && $resource->getTarget() === $destTarget) {
                return $resource;
            }
        }

        // Fallback: return first SlideMaster from presentation
        foreach ($this->presentation->getResources() as $resource) {
            if ($resource instanceof SlideMaster) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Determine if a resource should be registered in presentation.xml.rels.
     *
     * Only these resource types belong in presentation.xml.rels:
     * - SlideMaster (p:sldMasterIdLst)
     * - NoteMaster (p:notesMasterIdLst)
     * - HandoutMaster (p:handoutMasterIdLst)
     * - Theme (direct relationship)
     * - PresProps, ViewProps, TableStyles, RevisionInfo, CommentAuthors, etc.
     *
     * These resources should NOT be in presentation.xml.rels (they're linked via parent .rels):
     * - Image, Audio, Video, SvgImage, Chart (media resources)
     * - SlideLayout (linked from SlideMaster)
     * - NoteSlide (linked from Slide)
     *
     * @param GenericResource $resource The resource to check
     * @return bool True if the resource belongs in presentation.xml.rels
     */
    protected function shouldRegisterInPresentationRels(GenericResource $resource): bool
    {
        // Resources that MUST NOT be in presentation.xml.rels
        // (they are already linked from their parent container's .rels file)
        if ($resource instanceof Image ||
            $resource instanceof Audio ||
            $resource instanceof Video ||
            $resource instanceof SvgImage ||
            $resource instanceof Chart ||
            $resource instanceof SlideLayout ||
            $resource instanceof NoteSlide) {
            return false;
        }

        // Resources that SHOULD be in presentation.xml.rels
        // - SlideMaster, NoteMaster, HandoutMaster (handled specially in Presentation::addResource)
        // - Theme (presentation-level theme reference)
        // - PresProps, ViewProps, TableStyles, etc. (XmlResource with specific paths)
        return true;
    }

    /**
     * Check if a resource is already registered in the presentation.
     *
     * @param GenericResource $resource The resource to check
     * @return bool True if the resource exists in presentation
     */
    protected function isResourceAlreadyInPresentation(GenericResource $resource): bool
    {
        // Check if this resource is already in presentation's resources
        $presentationResources = $this->presentation->getResources();
        
        foreach ($presentationResources as $existingResource) {
            if ($existingResource instanceof GenericResource &&
                $existingResource->getTarget() === $resource->getTarget()) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Save all cloned resources and collect stats.
     *
     * @param array<string, ResourceInterface> $clonedResources
     */
    protected function saveClonedResources(array $clonedResources): void
    {
        foreach ($clonedResources as $resource) {
            // Collect stats for images if enabled
            if ($resource instanceof Image && $this->config->isEnabled('collect_stats')) {
                $this->collectImageStats($resource);
            }

            // Skip SlideMasters - their .rels files are managed by registerSlideLayoutsWithMaster()
            // Saving them here would overwrite the layout registrations we just added
            if ($resource instanceof SlideMaster) {
                continue;
            }

            $resource->save();
        }
    }

    /**
     * Collect compression stats for an image.
     *
     * @param Image $resource
     */
    protected function collectImageStats(Image $resource): void
    {
        $originalSize = $resource->getOriginalSize();
        $compressedSize = $resource->getCompressedSize();

        if ($originalSize && $compressedSize && $originalSize !== $compressedSize) {
            $type = $resource->detectImageType($resource->getContent()) ?? 'unknown';
            $this->stats->recordCompression($originalSize, $compressedSize, $type);
        }
    }

    /**
     * @throws FileSaveException
     * @throws FileOpenException
     */
    public function refreshSource(): void
    {
        $this->close();
        $this->openFile($this->tmpName);
    }

    /**
     * Import multiple slides object.
     *
     * @param Slide[] $slides
     * @throws Exception
     */
    public function addSlides(array $slides): self
    {
        // CRITICAL: Extract existing sections from presentation.xml BEFORE processing
        // (otherwise they are lost after refreshSource)
        $existingSections = $this->presentation->extractExistingSections();

        // Collect section information from new slides
        $newSectionData = $this->collectSectionData($slides, count($this->slides));

        foreach ($slides as $slide) {
            $this->addSlide($slide);
        }

        // Rebuild sections: merge existing sections + new sections
        $this->presentation->rebuildSectionsFromCollectedData($existingSections, $newSectionData);

        return $this;
    }

    /**
     * Collect section information from slides before they're processed.
     * This preserves section data through save/refresh cycles.
     *
     * @param Slide[] $slides Slides to collect section data from
     * @param int $startIndex Starting index for section data mapping
     * @return array Array mapping slide index to section info
     */
    protected function collectSectionData(array $slides, int $startIndex = 0): array
    {
        $sectionData = [];

        // Use sequential index to map sections to final slide positions
        $index = $startIndex;
        foreach ($slides as $slide) {
            $sectionInfo = $slide->getSourceSection();

            if ($sectionInfo !== null) {
                $sectionData[$index] = $sectionInfo;
            }

            $index++;
        }

        return $sectionData;
    }

    /**
     * Optimized batch processing for adding multiple slides.
     * Faster than addSlides because it only refreshes once at the end.
     *
     * @param Slide[] $slides Array of slides to add
     * @param array $options Processing options
     * @throws Exception
     * @return self
     */
    public function addSlidesBatch(array $slides, array $options = []): self
    {
        $defaultOptions = [
            'refresh_at_end' => true,
            'save_incrementally' => false,
            'collect_stats' => $this->config->isEnabled('collect_stats'),
            'continue_on_error' => false,
        ];

        $options = array_merge($defaultOptions, $options);

        $addedCount = 0;

        foreach ($slides as $slide) {
            $this->processResourceTree($slide);
            $addedCount++;

            // Save incrementally if requested
            if ($options['save_incrementally']) {
                $this->presentation->save();
                $this->contentType->save();
            }
        }

        // Save and refresh once at the end
        if ($addedCount > 0) {
            // Rebuild sections from slide metadata after all slides are added
            $this->presentation->rebuildSectionsFromSlides();

            $this->presentation->save();
            $this->contentType->save();

            if ($options['refresh_at_end']) {
                $this->refreshSource();
            }
        }

        return $this;
    }

    /**
     * Add a resource without refreshing the source (for batch processing).
     *
     * @param GenericResource $res
     * @throws Exception
     * @return self
     * @deprecated Use processResourceTree() instead
     */
    protected function addResourceWithoutRefresh(GenericResource $res): self
    {
        $this->processResourceTree($res);

        return $this;
    }

    /**
     * Get the resource tree for a given resource.
     *
     * Important: Stops recursion at SlideMasters/NoteMasters that will be REUSED.
     * For NEW masters (will be cloned), traverse their children and mark their
     * direct dependencies (Theme) as force-clone to maintain proper references.
     *
     * @param ResourceInterface $resource The root resource
     * @param array $resourceList Accumulated resource list
     * @param array $forceCloneTargets Targets that must be force-cloned (passed by reference)
     * @return ResourceInterface[] Complete resource tree
     */
    public function getResourceTree(ResourceInterface $resource, array &$resourceList = [], array &$forceCloneTargets = []): array
    {
        if (in_array($resource, $resourceList, true)) {
            return $resourceList;
        }

        $resourceList[] = $resource;

        if ($resource instanceof XmlResource) {
            // For SlideMasters and NoteMasters: check if they will be reused
            // If reused (already in destination), don't traverse their children
            // If new (will be cloned), traverse normally AND mark Theme as force-clone
            if ($resource instanceof SlideMaster || $resource instanceof NoteMaster) {
                $existingResource = $this->getContentType()->lookForSimilarFile($resource);
                if ($existingResource !== null) {
                    // This master will be reused - don't traverse its children
                    return $resourceList;
                }

                // This master will be cloned - mark its Theme as force-clone
                // so the new master gets its own theme reference
                foreach ($resource->getResources() as $subResource) {
                    if ($subResource instanceof Theme) {
                        $forceCloneTargets[] = $subResource->getTarget();
                    }
                }
            }

            // CRITICAL: Don't traverse NoteSlide children to avoid circular references
            // NoteSlides reference their parent Slide, which would cause the Slide
            // to be cloned twice (first as root, then as NoteSlide's child)
            if ($resource instanceof NoteSlide) {
                return $resourceList;
            }

            foreach ($resource->getResources() as $subResource) {
                $this->getResourceTree($subResource, $resourceList, $forceCloneTargets);
            }
        }

        return $resourceList;
    }

    /**
     * Fill data to each slide.
     *
     * @param array|Closure $data
     *
     * @throws FileOpenException
     * @throws FileSaveException
     */
    public function template($data): self
    {
        foreach ($this->getSlides() as $slide) {
            $slide->template($data);
        }

        $this->refreshSource();

        return $this;
    }

    /**
     * Fill table data in each slide.
     *
     * @param Closure $data
     * @param Closure $finder
     */
    public function table(Closure $data, Closure $finder): self
    {
        foreach ($this->getSlides() as $slide) {
            $slide->table($data, $finder);
        }

        $this->refreshSource();

        return $this;
    }

    /**
     * Update the images in the slide.
     *
     * @param array|Closure $data Closure or array which returns: key should match the descr attribute, value is the raw content of the image.
     */
    public function images($data): self
    {
        foreach ($this->getSlides() as $slide) {
            $slide->images($data);
        }

        return $this;
    }

    /**
     * Save the presentation to a new file.
     *
     * @param string $target Target file path
     *
     * @throws FileSaveException
     * @throws Exception
     */
    public function saveAs(string $target): void
    {
        // Reorder rIds to follow PowerPoint OPC conventions
        // Slides must have consecutive rIds starting from rId2
        // System resources (masters, props, themes) must come AFTER slides
        $this->reorderPresentationRIds();

        // Normalize slide IDs to be sequential starting from 256
        $this->normalizeSlideIds();

        // Clean orphaned resources before saving
        $this->cleanOrphanedResources();

        // Update app.xml metadata before saving
        $this->updateAppProperties();

        // Save ContentType after all modifications
        $this->contentType->save();

        $this->close();

        if (!copy($this->tmpName, $target)) {
            throw new FileSaveException('Unable to save the final PPTX. Error during the copying.');
        }

        $this->openFile($this->tmpName);
    }
    
    /**
     * Reorder rIds in presentation.xml to follow PowerPoint OPC conventions.
     *
     * PowerPoint expects resources in this order:
     * 1. SlideMasters (rId1)
     * 2. Slides (rId2, rId3, rId4, ...)
     * 3. System resources (notesMasters, presProps, viewProps, themes, tableStyles)
     *
     * This method reorganizes the rIds to match this order, preventing corruption.
     * Called before saveAs() to ensure OPC compliance.
     *
     * @throws Exception
     */
    protected function reorderPresentationRIds(): void
    {
        // Step 1: Collect all resources by type
        // getResources() calls mapResources() internally
        $slideMasters = [];
        $slides = [];
        $systemResources = [];

        foreach ($this->presentation->getResources() as $rId => $resource) {
            if ($resource instanceof SlideMaster) {
                $slideMasters[$rId] = $resource;
            } elseif ($resource instanceof Slide) {
                $slides[$rId] = $resource;
            } else {
                // System resources: NoteMasters, AppProperties, CoreProperties, presProps, viewProps, themes, tableStyles
                $systemResources[$rId] = $resource;
            }
        }

        // Step 2: Build new rId mapping
        // old rId => new rId
        $rIdMapping = [];
        $nextRId = 1;

        // SlideMasters first (rId1)
        foreach ($slideMasters as $oldRId => $resource) {
            $rIdMapping[$oldRId] = 'rId' . $nextRId++;
        }

        // Slides second (rId2+)
        // CRITICAL: Sort slides by their slide number before assigning rIds
        // This ensures presentation.xml sldIdLst order matches rId order (slide1, slide2, slide3...)
        uasort($slides, function ($a, $b) {
            $numA = (int) preg_replace('/[^0-9]/', '', basename($a->getTarget()));
            $numB = (int) preg_replace('/[^0-9]/', '', basename($b->getTarget()));
            return $numA <=> $numB;
        });

        foreach ($slides as $oldRId => $resource) {
            $rIdMapping[$oldRId] = 'rId' . $nextRId++;
        }

        // System resources last (rId N+)
        foreach ($systemResources as $oldRId => $resource) {
            $rIdMapping[$oldRId] = 'rId' . $nextRId++;
        }

        // Step 3: Update presentation.xml and .rels file
        if (!empty($rIdMapping)) {
            $this->presentation->remapResourceIds($rIdMapping);
        }
    }

    /**
     * Clean orphaned resources.
     *
     * IMPORTANT: SlideLayouts referenced by SlideMasters are KEPT even if not used by slides.
     * PowerPoint requires SlideMasters to have valid layout references.
     * Removes revisionInfo.xml and unreferenced media files.
     */
    protected function cleanOrphanedResources(): void
    {
        // Remove revisionInfo.xml if present (causes corruption)
        /*$revisionInfo = 'ppt/revisionInfo.xml';
        if ($this->archive->locateName($revisionInfo) !== false) {
            $this->archive->deleteName($revisionInfo);
            $this->removeFromContentTypes($revisionInfo);
            $this->removeRevisionInfoFromPresentationRels();
        }*/
        
        // Clean orphaned media files
        $this->cleanOrphanedMedia();
    }
    
    /**
     * Remove media files that are not referenced in any .rels file.
     */
    protected function cleanOrphanedMedia(): void
    {
        // Collect all media files in the archive
        $mediaFiles = [];
        for ($i = 0; $i < $this->archive->numFiles; $i++) {
            $filename = $this->archive->getNameIndex($i);
            if ($filename !== false && str_starts_with($filename, 'ppt/media/')) {
                $mediaFiles[$filename] = true;
            }
        }

        if (empty($mediaFiles)) {
            return;
        }

        // Collect all referenced media from .rels files
        $referencedMedia = [];
        for ($i = 0; $i < $this->archive->numFiles; $i++) {
            $filename = $this->archive->getNameIndex($i);
            if ($filename !== false && str_ends_with($filename, '.rels')) {
                $content = $this->archive->getFromName($filename);
                if ($content !== false) {
                    $relsDir = dirname(dirname($filename)); // e.g., ppt/slides from ppt/slides/_rels/slide1.xml.rels
                    if (preg_match_all('/Target="([^"]+)"/', $content, $matches)) {
                        foreach ($matches[1] as $target) {
                            // Resolve relative path
                            $resolvedPath = $this->resolveRelativeMediaPath($relsDir, $target);
                            if ($resolvedPath !== null && str_starts_with($resolvedPath, 'ppt/media/')) {
                                $referencedMedia[$resolvedPath] = true;
                            }
                        }
                    }
                }
            }
        }
        
        // Remove unreferenced media
        foreach ($mediaFiles as $mediaPath => $unused) {
            if (!isset($referencedMedia[$mediaPath])) {
                $this->archive->deleteName($mediaPath);
            }
        }
    }
    
    /**
     * Resolve a relative target path from a .rels file to an absolute path.
     *
     * @param string $baseDir The directory containing the .rels source file (e.g., ppt/slides)
     * @param string $target The relative target (e.g., ../media/image1.png)
     * @return string|null The resolved absolute path, or null if external
     */
    protected function resolveRelativeMediaPath(string $baseDir, string $target): ?string
    {
        // Skip external targets
        if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
            return null;
        }
        
        // Handle absolute paths
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        
        // Resolve relative path
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
    
    /**
     * Remove revisionInfo relationship from presentation.xml.rels.
     */
    protected function removeRevisionInfoFromPresentationRels(): void
    {
        $relsPath = 'ppt/_rels/presentation.xml.rels';
        $relsContent = $this->archive->getFromName($relsPath);
        if ($relsContent === false) {
            return;
        }
        
        $xml = new \SimpleXMLElement($relsContent);
        
        // Register namespace for XPath
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        
        // Find and remove revisionInfo relationship
        $relationships = $xml->xpath("//r:Relationship[contains(@Type, 'revisionInfo')]");
        foreach ($relationships as $rel) {
            $dom = dom_import_simplexml($rel);
            $dom->parentNode->removeChild($dom);
        }
        
        // Save updated rels file
        $this->archive->addFromString($relsPath, $xml->asXML());
    }
    
    /**
     * Remove a file from [Content_Types].xml overrides.
     */
    protected function removeFromContentTypes(string $path): void
    {
        // Use ContentType object to ensure changes are persisted when saved
        $this->contentType->removeResource($path);
    }

    /**
     * Normalize slide IDs to be sequential starting from 256 (PowerPoint standard).
     * Also updates the section list to use the new IDs.
     */
    protected function normalizeSlideIds(): void
    {
        $xml = $this->presentation->getXmlContent();
        $xml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xml->registerXPathNamespace('p14', 'http://schemas.microsoft.com/office/powerpoint/2010/main');
        
        // Build mapping of old ID -> new ID
        $idMapping = [];
        $slides = $xml->xpath('//p:sldIdLst/p:sldId');
        
        foreach ($slides as $index => $slide) {
            $oldId = (int) $slide['id'];
            $newId = 256 + $index;
            $idMapping[$oldId] = $newId;
            $slide['id'] = (string)$newId;
        }
        
        // Update section IDs using the mapping
        $this->updateSectionSlideIds($xml, $idMapping);
        
        $this->presentation->save();
    }
    
    /**
     * Update slide IDs in section list to match the new sequential IDs.
     *
     * @param \SimpleXMLElement $xml The presentation XML
     * @param array<int, int> $idMapping Mapping of old ID => new ID
     */
    protected function updateSectionSlideIds(\SimpleXMLElement $xml, array $idMapping): void
    {
        $sections = $xml->xpath('//p14:sectionLst/p14:section');
        
        foreach ($sections as $section) {
            $sldIds = $section->xpath('p14:sldIdLst/p14:sldId');
            
            foreach ($sldIds as $sldId) {
                $oldId = (int) $sldId['id'];
                if (isset($idMapping[$oldId])) {
                    $sldId['id'] = (string) $idMapping[$oldId];
                }
            }
        }
    }

    /**
     * Update app.xml with current slide and notes counts.
     */
    protected function updateAppProperties(): void
    {
        try {
            $appProps = $this->contentType->getResource('docProps/app.xml');
            
            if ($appProps instanceof AppProperties) {
                // Count slides
                $slideCount = count($this->slides);
                $appProps->updateSlideCount($slideCount);
                
                // Count notes
                $notesCount = 0;
                foreach ($this->slides as $slide) {
                    foreach ($slide->getResources() as $resource) {
                        if ($resource instanceof NoteSlide) {
                            $notesCount++;
                            break;
                        }
                    }
                }
                $appProps->updateNotesCount($notesCount);
                
                $appProps->save();
            }
        } catch (\Exception $e) {
            // If app.xml doesn't exist or can't be updated, continue anyway
            // This is not critical for PPTX functionality
        }
    }

    /**
     * Overwrites the open file with the news.
     *
     * @throws Exception
     */
    public function save(): void
    {
        $this->saveAs($this->filename);
    }

    /**
     * Destructor - clean up temporary files.
     *
     * @throws FileSaveException
     */
    public function __destruct()
    {
        $this->close();
        unlink($this->tmpName);
    }

    /**
     * Close the archive.
     *
     * @throws FileSaveException
     */
    protected function close(): void
    {
        if (!@$this->archive->close()) {
            throw new FileSaveException('Unable to close the source PPTX.');
        }
    }

    /**
     * Get the underlying ZipArchive.
     */
    public function getArchive(): ZipArchive
    {
        return $this->archive;
    }

    /**
     * Get the content type manager.
     */
    public function getContentType(): ContentType
    {
        return $this->contentType;
    }

    /**
     * Get the optimization configuration.
     */
    public function getConfig(): OptimizationConfig
    {
        return $this->config;
    }

    /**
     * Get the image cache.
     */
    public function getImageCache(): ImageCache
    {
        return $this->imageCache;
    }

    /**
     * Get optimization statistics.
     *
     * @return array
     */
    public function getOptimizationStats(): array
    {
        $stats = $this->stats->getReport();
        $cacheStats = $this->imageCache->getStats();

        return array_merge($stats, [
            'cache_stats' => $cacheStats,
        ]);
    }

    /**
     * Get a summary of optimizations performed.
     */
    public function getOptimizationSummary(): string
    {
        return $this->stats->getSummary();
    }

    /**
     * Validate the presentation (slides and resources).
     *
     * @return array Validation report
     */
    public function validate(): array
    {
        if (!$this->validator) {
            $this->validator = new PresentationValidator($this->config);
        }

        $resources = [];
        foreach ($this->slides as $slide) {
            $resources = array_merge($resources, array_values($slide->getResources()));
        }

        return $this->validator->validatePresentation($this->slides, $resources);
    }

    /**
     * Validate only images.
     *
     * @return array Image validation report
     */
    public function validateImages(): array
    {
        $imageValidator = new ImageValidator($this->config);
        $report = [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
            'details' => [],
        ];

        foreach ($this->slides as $slide) {
            foreach ($slide->getResources() as $resource) {
                if ($resource instanceof Image) {
                    $report['total']++;
                    $content = $resource->getContent();
                    $imageReport = $imageValidator->validateWithReport($content);

                    $report['details'][$resource->getTarget()] = $imageReport;

                    if ($imageReport['valid']) {
                        $report['valid']++;
                    } else {
                        $report['invalid']++;
                    }
                }
            }
        }

        return $report;
    }
}
