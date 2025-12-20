<?php

declare(strict_types=1);

namespace Cristal\Presentation;

use Closure;
use Cristal\Presentation\Cache\ImageCache;
use Cristal\Presentation\Config\OptimizationConfig;
use Cristal\Presentation\Exception\FileOpenException;
use Cristal\Presentation\Exception\FileSaveException;
use Cristal\Presentation\Resource\ContentType;
use Cristal\Presentation\Resource\GenericResource;
use Cristal\Presentation\Resource\Image;
use Cristal\Presentation\Resource\Presentation;
use Cristal\Presentation\Resource\Slide;
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

        foreach ($this->presentation->getXmlContent()->xpath('p:sldIdLst/p:sldId') as $slide) {
            $id = $slide->xpath('@r:id')[0]['id'] . '';
            $resource = $this->presentation->getResource($id);
            if ($resource instanceof Slide) {
                $this->slides[] = $resource;
            }
        }

        return $this;
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
        $tree = $this->getResourceTree($res);

        /** @var array<string, ResourceInterface> $clonedResources */
        $clonedResources = [];

        // Clone, rename, and set new destination
        foreach ($tree as $originalResource) {
            $clonedResources[$originalResource->getTarget()] = $this->cloneOrReuseResource($originalResource);
        }

        // Update resource references
        $this->updateResourceReferences($clonedResources);

        // Notify presentation and register slides
        $this->registerResourcesWithPresentation($clonedResources, $res);

        // Save all cloned resources
        $this->saveClonedResources($clonedResources);

        return $clonedResources;
    }

    /**
     * Clone or reuse an existing resource.
     *
     * @param ResourceInterface $originalResource The original resource
     * @return ResourceInterface The cloned or reused resource
     */
    protected function cloneOrReuseResource(ResourceInterface $originalResource): ResourceInterface
    {
        if (!$originalResource instanceof GenericResource) {
            return clone $originalResource;
        }

        // Check for image deduplication
        if ($originalResource instanceof Image && $this->config->isEnabled('deduplicate_images')) {
            $duplicate = $this->imageCache->findDuplicate($originalResource->getContent());
            if ($duplicate !== null) {
                if ($this->config->isEnabled('collect_stats')) {
                    $this->stats->recordDeduplication();
                }

                return $duplicate;
            }
        }

        // Check if resource already exists in the document
        $existingResource = $this->getContentType()->lookForSimilarFile($originalResource);

        if ($existingResource !== null && !$originalResource instanceof XmlResource) {
            return $existingResource;
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
     * Update resource references after cloning.
     *
     * @param array<string, ResourceInterface> $clonedResources
     */
    protected function updateResourceReferences(array $clonedResources): void
    {
        foreach ($clonedResources as $resource) {
            if ($resource instanceof XmlResource) {
                foreach ($resource->getResources() as $rId => $subResource) {
                    $resource->setResource($rId, $clonedResources[$subResource->getTarget()]);
                }
            }
        }
    }

    /**
     * Register resources with presentation and track slides.
     *
     * @param array<string, ResourceInterface> $clonedResources
     * @param GenericResource $originalResource
     */
    protected function registerResourcesWithPresentation(array $clonedResources, GenericResource $originalResource): void
    {
        foreach ($clonedResources as $resource) {
            $this->presentation->addResource($resource);

            if ($resource instanceof Slide) {
                $this->slides[] = $resource;
            }
        }
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
        foreach ($slides as $slide) {
            $this->addSlide($slide);
        }

        return $this;
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
     * @return ResourceInterface[]
     */
    public function getResourceTree(ResourceInterface $resource, array &$resourceList = []): array
    {
        if (in_array($resource, $resourceList, true)) {
            return $resourceList;
        }

        $resourceList[] = $resource;

        if ($resource instanceof XmlResource) {
            foreach ($resource->getResources() as $subResource) {
                $this->getResourceTree($subResource, $resourceList);
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
    public function template(array|Closure $data): self
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
    public function images(array|Closure $data): self
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
        $this->close();

        if (!copy($this->tmpName, $target)) {
            throw new FileSaveException('Unable to save the final PPTX. Error during the copying.');
        }

        $this->openFile($this->tmpName);
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
