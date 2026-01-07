<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\Cache\LRUCache;
use Cristal\Presentation\PPTX;
use Cristal\Presentation\ResourceInterface;
use SimpleXMLElement;

/**
 * Content type manager for handling PPTX file types.
 */
class ContentType extends GenericResource
{
    /**
     * Classes mapping for content types.
     */
    public const CLASSES = [
        // Generic formats that are defined multiple times must be defined at the top of the list.
        'application/xml' => XmlResource::class,
        'application/image' => Image::class,
        // Then...
        'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml' => Presentation::class,
        'application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml' => SlideLayout::class,
        'application/vnd.openxmlformats-officedocument.presentationml.slide+xml' => Slide::class,
        'application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml' => NoteMaster::class,
        'application/vnd.openxmlformats-officedocument.presentationml.handoutMaster+xml' => HandoutMaster::class,
        'application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml' => SlideMaster::class,
        'application/vnd.openxmlformats-officedocument.presentationml.theme+xml' => Theme::class,
        'application/vnd.openxmlformats-officedocument.presentationml.viewProps+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.presentationml.presProps+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml' => NoteSlide::class,
        // Comments support (ECMA-376 Part 1, Section 13.3.2)
        'application/vnd.openxmlformats-officedocument.presentationml.comments+xml' => Comment::class,
        'application/vnd.openxmlformats-officedocument.presentationml.commentAuthors+xml' => CommentAuthor::class,
        // Themes
        'application/vnd.openxmlformats-officedocument.theme+xml' => Theme::class,
        // Core Properties (Dublin Core metadata)
        'application/vnd.openxmlformats-package.core-properties+xml' => CoreProperties::class,
        'application/vnd.openxmlformats-officedocument.extended-properties+xml' => XmlResource::class,
        // Charts (DrawingML)
        'application/vnd.openxmlformats-officedocument.drawingml.chart+xml' => Chart::class,
        // Images - Standard formats
        'image/png' => Image::class,
        'image/jpeg' => Image::class,
        'image/jpg' => Image::class,
        'image/gif' => Image::class,
        'image/bmp' => Image::class,
        'image/tiff' => Image::class,
        'image/vnd.ms-photo' => Image::class,
        // Images - Modern formats (Office 2019+/365)
        'image/webp' => Image::class,
        'image/avif' => Image::class,
        'image/heif' => Image::class,
        'image/heic' => Image::class,
        // SVG (Office 365 2016+)
        'image/svg+xml' => SvgImage::class,
        // Audio formats
        'audio/mpeg' => Audio::class,
        'audio/mp3' => Audio::class,
        'audio/wav' => Audio::class,
        'audio/x-wav' => Audio::class,
        'audio/mp4' => Audio::class,
        'audio/x-m4a' => Audio::class,
        'audio/aac' => Audio::class,
        'audio/ogg' => Audio::class,
        'audio/x-ms-wma' => Audio::class,
        // Video formats
        'video/mp4' => Video::class,
        'video/x-ms-wmv' => Video::class,
        'video/avi' => Video::class,
        'video/x-msvideo' => Video::class,
        'video/quicktime' => Video::class,
        'video/x-m4v' => Video::class,
        'video/webm' => Video::class,
        'video/mpeg' => Video::class,
        'video/x-mpeg' => Video::class,
        // Fallback
        '_' => GenericResource::class,
    ];

    /**
     * The parsed XML content.
     */
    protected SimpleXMLElement $content;

    /**
     * LRU cache for resources.
     */
    protected $cachedResources;

    /**
     * Use LRU cache flag.
     */
    protected bool $useLRUCache = false;

    /**
     * Override content types.
     *
     * @var array<string, string>
     */
    protected array $overrides = [];

    /**
     * Extension content types.
     *
     * @var array<string, string>
     */
    protected array $extensions = [];

    /**
     * Cached filename list.
     *
     * @var array<int, string>
     */
    protected array $cachedFilename = [];

    /**
     * ContentType constructor.
     */
    public function __construct(PPTX $document)
    {
        parent::__construct('[Content_Types].xml', '', 'application/xml', $document);

        $this->setContent($this->initialDocument->getArchive()->getFromName($this->getInitialTarget()));

        // Initialize LRU cache if configured
        $cacheSize = $document->getConfig()->get('cache_size');
        if ($cacheSize > 0) {
            $this->useLRUCache = true;
            $this->cachedResources = new LRUCache($cacheSize);
        } else {
            $this->cachedResources = [];
        }

        // Get override mimes.
        foreach ($this->content->Override as $resourceNode) {
            $this->overrides[trim((string) $resourceNode['PartName'], '/')] = (string) $resourceNode['ContentType'];
        }

        // Get generic extensions.
        foreach ($this->content->Default as $resourceNode) {
            $this->extensions[(string) $resourceNode['Extension']] = (string) $resourceNode['ContentType'];
        }

        // Store filename list.
        for ($i = 0; $i < $this->getDocument()->getArchive()->numFiles; ++$i) {
            $stat = $this->getDocument()->getArchive()->statIndex($i);
            if ($stat !== false) {
                $filenameParts = pathinfo($stat['name']);
                $dirname = $filenameParts['dirname'] ?? '.';
                $this->cachedFilename[] = $dirname . '/' . $filenameParts['basename'];
            }
        }
    }

    /**
     * Reset an XML content from a string.
     *
     * @param string $content Must be a valid XML.
     */
    public function setContent(string $content): void
    {
        $this->content = new SimpleXMLElement($content, LIBXML_NOWARNING);
    }

    /**
     * Returns a string content from the XML object.
     */
    public function getContent(): string
    {
        return $this->content->asXml();
    }

    /**
     * Get resource class from its content type.
     *
     * @param string $contentType Content type string
     * @return string Class name
     */
    public static function getResourceClassFromType(string $contentType): string
    {
        return static::CLASSES[$contentType] ?? static::CLASSES['_'];
    }

    /**
     * Get a resource by path.
     *
     * @param string $path Resource path
     * @param string $relType Relationship type
     * @param bool $external Whether the resource is external
     * @param bool $storeInCache Whether to store in cache
     */
    public function getResource(string $path, string $relType = '', bool $external = false, bool $storeInCache = true): ResourceInterface
    {
        $path = !$external ? static::resolveAbsolutePath($path) : $path;

        // Check cache
        if ($this->useLRUCache) {
            $cached = $this->cachedResources->get($path);
            if ($cached !== null) {
                return $cached;
            }
        } else {
            if (isset($this->cachedResources[$path])) {
                return $this->cachedResources[$path];
            }
        }

        if ($external) {
            $resource = new External($path, $relType);
        } else {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $contentType =
                $this->overrides[$path]
                ?? ($extension !== '' ? ($this->extensions[$extension] ?? '') : '');

            $className = static::getResourceClassFromType($contentType);
            $resource = new $className($path, $relType, $contentType, $this->document);

            // Enable lazy loading if configured
            if ($this->document->getConfig()->isEnabled('lazy_loading')) {
                $resource->setLazyLoading(true);
            }
        }

        if ($storeInCache) {
            if ($this->useLRUCache) {
                $this->cachedResources->set($path, $resource);
            } else {
                $this->cachedResources[$path] = $resource;
            }
        }

        return $resource;
    }

    /**
     * Look for a similar file in the archive.
     *
     * @param GenericResource $originalResource Resource to compare
     * @return GenericResource|null Existing resource if found
     */
    public function lookForSimilarFile(GenericResource $originalResource): ?GenericResource
    {
        $startBy = dirname($originalResource->getTarget()) . '/';
        foreach ($this->cachedFilename as $path) {
            if (str_starts_with($path, $startBy) && dirname($path) . '/' === $startBy) {
                $existingFile = $this->getResource($path, $originalResource->getRelType(), false, false);
                if ($existingFile instanceof GenericResource
                    && $existingFile->getHashFile() === $originalResource->getHashFile()) {
                    return $existingFile;
                }
            }
        }

        return null;
    }

    /**
     * Find an available filename based on a pattern.
     *
     * @param string|null $pattern A string containing '{x}' as an index replaced by an incremental number
     * @param int $start Beginning index (default is 1)
     * @return string Available filename
     */
    public function findAvailableName(?string $pattern, int $start = 1): string
    {
        $filenameList = array_map(static function (string $path): string {
            $pathInfo = pathinfo($path);

            return $pathInfo['dirname'] . '/' . $pathInfo['filename'];
        }, $this->cachedFilename);

        do {
            $filename = str_replace('{x}', (string) $start, $pattern ?? '');
            $filenameParts = pathinfo($filename);

            $filenameWithoutExtension = $filenameParts['dirname'] . '/' . $filenameParts['filename'];

            if (!in_array($filenameWithoutExtension, $filenameList, true)) {
                $this->cachedFilename[] = $filename;
                break;
            }

            ++$start;
        } while (true);

        return $filename;
    }

    /**
     * Check if the resource is a draft.
     */
    public function isDraft(): bool
    {
        return true;
    }

    /**
     * Add content type to the presentation from a resource.
     */
    public function addResource(GenericResource $resource): void
    {
        $fileExtension = pathinfo($resource->getTarget(), PATHINFO_EXTENSION);
        $fileContentType = $this->extensions[$fileExtension] ?? null;

        $realContentType = $resource->getContentType();

        // Append unknown format.
        if ($fileContentType === null) {
            $fileContentType = $this->extensions[$fileExtension] = $realContentType;

            $child = $this->content->addChild('Default');
            $child->addAttribute('Extension', $fileExtension);
            $child->addAttribute('ContentType', $fileContentType);
        }

        // If the contentType does not exist on generic extensions, then add a specific "Override" child.
        if ($fileContentType !== $realContentType) {
            $child = $this->content->addChild('Override');
            $child->addAttribute('PartName', '/' . $resource->getTarget());
            $child->addAttribute('ContentType', $realContentType);
        }

        if ($this->useLRUCache) {
            $this->cachedResources->set($resource->getTarget(), $resource);
        } else {
            $this->cachedResources[$resource->getTarget()] = $resource;
        }
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed>|null
     */
    public function getCacheStats(): ?array
    {
        return $this->useLRUCache ? $this->cachedResources->getStats() : null;
    }
}
