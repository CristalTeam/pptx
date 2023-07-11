<?php

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\PPTX;
use Cristal\Presentation\ResourceInterface;
use SimpleXMLElement;

class ContentType extends GenericResource
{
    /**
     * Classes mapping.
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
        'application/vnd.openxmlformats-officedocument.theme+xml' => Theme::class,
        'application/vnd.openxmlformats-package.core-properties+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.extended-properties+xml' => XmlResource::class,
        'image/png' => Image::class,
        'image/jpeg' => Image::class,
        'image/vnd.ms-photo' => Image::class,
        '_' => GenericResource::class,
    ];

    /**
     * @var SimpleXMLElement
     */
    public $content;

    /**
     * @var GenericResource[]
     */
    protected $cachedResources;

    /**
     * @var array|string[]
     */
    protected $overrides;

    /**
     * @var array|string[]
     */
    protected $extensions;

    /**
     * @var array
     */
    protected $cachedFilename = [];

    public function __construct(PPTX $document)
    {
        parent::__construct('[Content_Types].xml', '', 'application/xml', $document);

        $this->setContent($this->initialDocument->getArchive()->getFromName($this->getInitialTarget()));

        // Get override mimes.

        foreach ($this->content->Override as $resourceNode) {
            $this->overrides[trim((string)$resourceNode['PartName'], '/')] = (string)$resourceNode['ContentType'];
        }

        // Get generic extensions.

        foreach ($this->content->Default as $resourceNode) {
            $this->extensions[(string)$resourceNode['Extension']] = (string)$resourceNode['ContentType'];
        }

        // Store filename list.

        for ($i = 0; $i < $this->getDocument()->getArchive()->numFiles; ++$i) {
            $filenameParts = pathinfo($this->getDocument()->getArchive()->statIndex($i)['name']);
            if (isset($filenameParts['dirname'], $filenameParts['basename'])) {
                $this->cachedFilename[] = $filenameParts['dirname'] . '/' . $filenameParts['basename'];
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
     * Get resource class from its contentType.
     */
    public static function getResourceClassFromType(string $contentType): string
    {
        return static::CLASSES[$contentType] ?? static::CLASSES['_'];
    }

    public function getResource(string $path, string $relType = '', $external = false, bool $storeInCache = true): ResourceInterface
    {
        $path = !$external ? static::resolveAbsolutePath($path) : $path;

        if (isset($this->cachedResources[$path])) {
            return $this->cachedResources[$path];
        }

        if($external) {
            $resource = new External($path, $relType);
        } else {
            $contentType =
                $this->overrides[$path]
                ?? $this->extensions[pathinfo($path)['extension'] ?? null]
                ?? '';

            $className = static::getResourceClassFromType($contentType);
            $resource = new $className($path, $relType, $contentType, $this->document);
        }

        if ($storeInCache) {
            $this->cachedResources[$path] = $resource;
        }

        return $resource;
    }

    public function lookForSimilarFile(GenericResource $originalResource)
    {
        $startBy = dirname($originalResource->getTarget()) . '/';
        foreach ($this->cachedFilename as $path) {
            if (0 === strpos($path, $startBy) && dirname($path) . '/' === $startBy) {
                $existingFile = $this->getResource($path, $originalResource->getRelType(), false, false);
                if ($existingFile->getHashFile() === $originalResource->getHashFile()) {
                    return $existingFile;
                }
            }
        }

        return null;
    }

    /**
     * Find an available filename based on a pattern.
     *
     * @param mixed $pattern A string contains '{x}' as an index replaced by a incremental number
     * @param int $start beginning index default is 1
     *
     * @return mixed
     */
    public function findAvailableName($pattern, $start = 1)
    {
        $filenameList = array_map(static function ($path) {
            $pathInfo = pathinfo($path);
            return $pathInfo['dirname'] . '/' . $pathInfo['filename'];
        }, $this->cachedFilename);

        do {
            $filename = str_replace('{x}', $start, $pattern);
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

    public function isDraft(): bool
    {
        return true;
    }

    /**
     * Add content type to the presentation from a filename.
     */
    public function addResource(GenericResource $resource): void
    {
        $fileExtension = pathinfo($resource->getTarget())['extension'];
        $fileContentType = $this->extensions[$fileExtension] ?? null;

        $realContentType = $resource->getContentType();

        // Append unknown format.

        if(null === $fileContentType){
            $fileContentType = $this->extensions[$fileExtension] = $realContentType;

            $child = $this->content->addChild('Default');
            $child->addAttribute('Extension', $fileExtension);
            $child->addAttribute('ContentType', $fileContentType);
        }

        // If the contentType does not exist on generics extensions, then add a specific "Override" child.

        if ($fileContentType !== $realContentType) {
            $child = $this->content->addChild('Override');
            $child->addAttribute('PartName', '/' . $resource->getTarget());
            $child->addAttribute('ContentType', $realContentType);
        }

        $this->cachedResources[$resource->getTarget()] = $resource;
    }
}
