<?php

namespace Cristal\Presentation;

use Closure;
use Cristal\Presentation\Exception\FileOpenException;
use Cristal\Presentation\Exception\FileSaveException;
use Cristal\Presentation\Resource\ContentType;
use Cristal\Presentation\Resource\GenericResource;
use Cristal\Presentation\Resource\Presentation;
use Cristal\Presentation\Resource\Slide;
use Cristal\Presentation\Resource\XmlResource;
use Exception;
use ZipArchive;

class PPTX
{
    /**
     * @var ZipArchive
     */
    protected $archive;

    /**
     * @var Slide[]
     */
    protected $slides = [];

    /**
     * @var Presentation
     */
    protected $presentation;

    /**
     * @var string
     */
    protected $filename;

    /**
     * @var string
     */
    protected $tmpName;

    /**
     * @var ContentType
     */
    protected $contentType;

    /**
     * Presentation constructor.
     *
     * @throws Exception
     */
    public function __construct(string $path)
    {
        $this->filename = $path;

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
    public function openFile(string $path): PPTX
    {
        $this->archive = new ZipArchive();
        $res = $this->archive->open($path);

        if ($res !== true) {
            throw new FileOpenException($res->getStatusString());
        }

        $this->contentType = new ContentType($this);
        $this->presentation = $this->contentType->getResource('ppt/presentation.xml');

        $this->loadSlides();

        return $this;
    }

    /**
     * Read existing slides.
     */
    protected function loadSlides(): PPTX
    {
        $this->slides = [];

        foreach ($this->presentation->content->xpath('p:sldIdLst/p:sldId') as $slide) {
            $id = $slide->xpath('@r:id')[0]['id'] . '';
            $this->slides[] = $this->presentation->getResource($id);
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
    public function addSlide(Slide $slide): PPTX
    {
        return $this->addResource($slide);
    }

    /**
     * Add a resource and its dependency inside this document.
     */
    public function addResource(GenericResource $res): PPTX
    {
        $tree = $this->getResourceTree($res);

        /** @var GenericResource[] $clonedResources */
        $clonedResources = [];

        // Clone, rename, and set new destination...

        foreach($tree as $originalResource){

            if(!$originalResource instanceof GenericResource){
                $resource = clone $originalResource;
                $clonedResources[$originalResource->getTarget()] = $resource;
                continue;
            }

            // Check if resource already exists in the document.
            $existingResource = $this->getContentType()->lookForSimilarFile($originalResource);

            if(null === $existingResource || $originalResource instanceof XmlResource) {
                $resource = clone $originalResource;
                $resource->setDocument($this);
                $resource->rename(basename((string) $this->getContentType()->findAvailableName($resource->getPatternPath())));
                $this->contentType->addResource($resource);

                $clonedResources[$originalResource->getTarget()] = $resource;
            } else {
                $clonedResources[$originalResource->getTarget()] = $existingResource;
            }
        }

        // After the resource is renamed, replace existing "rIds" by the corresponding new resource...

        foreach($clonedResources as $resource){
            if($resource instanceof XmlResource){
                foreach($resource->resources as $rId => $subResource){
                    $resource->resources[$rId] = $clonedResources[$subResource->getTarget()];
                }
            }

            // Also, notify the Presentation that have a new interesting object...
            $this->presentation->addResource($resource);

            if($resource instanceof Slide){
                $this->slides[] = $res;
            }
        }

        // Finally, save all new resources.
        foreach($clonedResources as $resource){
            $resource->save();
        }

        // And the presentation.
        $this->presentation->save();
        $this->contentType->save();

        $this->refreshSource();

        return $this;
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
     * @throws Exception
     */
    public function addSlides(array $slides): PPTX
    {
        foreach ($slides as $slide) {
            $this->addSlide($slide);
        }

        return $this;
    }

    /**
     * @return ResourceInterface[]
     */
    public function getResourceTree(ResourceInterface $resource, array &$resourceList = []): array
    {
        if(in_array($resource, $resourceList, true)){
            return $resourceList;
        }

        $resourceList[] = $resource;

        if ($resource instanceof XmlResource) {
            foreach($resource->getResources() as $subResource){
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
    public function template($data): PPTX
    {
        foreach ($this->getSlides() as $slide) {
            $slide->template($data);
        }

        $this->refreshSource();
        return $this;
    }

    public function table(Closure $data, Closure $finder): PPTX
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
     * @param mixed $data Closure or array which returns: key should match the descr attribute, value is the raw content of the image.
     */
    public function images($data): PPTX
    {
        foreach ($this->getSlides() as $slide) {
            $slide->images($data);
        }

        return $this;
    }

    /**
     * Save.
     *
     * @param $target
     *
     * @throws FileSaveException
     * @throws Exception
     */
    public function saveAs($target): void
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
     * @throws FileSaveException
     */
    public function __destruct()
    {
        $this->close();
        unlink($this->tmpName);
    }

    /**
     * @throws FileSaveException
     */
    protected function close(): void
    {
        if (!@$this->archive->close()) {
            throw new FileSaveException('Unable to close the source PPTX.');
        }
    }

    public function getArchive(): ZipArchive
    {
        return $this->archive;
    }

    /**
     * @return ContentType
     */
    public function getContentType(): ContentType
    {
        return $this->contentType;
    }
}
