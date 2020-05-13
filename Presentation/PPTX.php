<?php

namespace Cpro\Presentation;

use Closure;
use Cpro\Presentation\Exception\FileOpenException;
use Cpro\Presentation\Exception\FileSaveException;
use Cpro\Presentation\Resource\NoteMaster;
use Cpro\Presentation\Resource\GenericResource;
use Cpro\Presentation\Resource\Presentation;
use Cpro\Presentation\Resource\Slide;
use Cpro\Presentation\Resource\XmlResource;
use Exception;
use ZipArchive;

class PPTX
{
    /**
     * @var ZipArchive
     */
    protected $source;

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
     * @var array
     */
    protected $cachedFilename = [];

    /**
     * @var array
     */
    protected $resourcesAlreadyCopied = [];

    /**
     * @var XmlResource
     */
    protected $contentTypes;

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

        for ($i = 0; $i < $this->source->numFiles; ++$i) {
            $filenameParts = pathinfo($this->source->statIndex($i)['name']);
            if (isset($filenameParts['dirname'], $filenameParts['filename'])) {
                $this->cachedFilename[] = $filenameParts['dirname'] . '/' . $filenameParts['filename'];
            }
        }
    }

    /**
     * Open a PPTX file.
     *
     * @throws FileOpenException
     */
    public function openFile(string $path): PPTX
    {
        $this->source = new ZipArchive();
        $res = $this->source->open($path);

        if ($res !== true) {
            $errors = [
                0 => 'No error',
                1 => 'Multi-disk zip archives not supported',
                2 => 'Renaming temporary file failed',
                3 => 'Closing zip archive failed',
                4 => 'Seek error',
                5 => 'Read error',
                6 => 'Write error',
                7 => 'CRC error',
                8 => 'Containing zip archive was closed',
                9 => 'No such file',
                10 => 'File already exists',
                11 => 'Can\'t open file',
                12 => 'Failure to create temporary file',
                13 => 'Zlib error',
                14 => 'Malloc failure',
                15 => 'Entry has been changed',
                16 => 'Compression method not supported',
                17 => 'Premature EOF',
                18 => 'Invalid argument',
                19 => 'Not a zip archive',
                20 => 'Internal error',
                21 => 'Zip archive inconsistent',
                22 => 'Can\'t remove file',
                23 => 'Entry has been deleted',
            ];
            throw new FileOpenException($errors[$res] ?? 'Cannot open PPTX file, error ' . $res . '.');
        }

        $this->contentTypes = $this->readXmlFile('[Content_Types].xml');
        $this->loadSlides();

        return $this;
    }

    /**
     * Create an XmlResource from a filename in the current presentation.
     */
    protected function readXmlFile(string $path, string $type = 'application/xml'): GenericResource
    {
        return GenericResource::createFromNode($path, $type, $this->source);
    }

    /**
     * Read existing slides.
     */
    protected function loadSlides(): PPTX
    {
        $this->slides = [];

        $this->presentation = $this->readXmlFile(
            'ppt/presentation.xml',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml'
        );
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
        $slide = clone $slide;
        $this->slides[] = $slide;

        // Copy slide
        $this->copyResource($slide);

        $this->refreshSource();

        return $this;
    }

    /**
     * @throws FileSaveException
     * @throws FileOpenException
     */
    protected function refreshSource(): void
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
     * Store resource into current presentation.
     *
     * @return $this
     * @throws Exception
     */
    public function copyResource(GenericResource $resource): PPTX
    {
        // Check if this file as already copied.

        if(in_array($resource->getKey(), $this->resourcesAlreadyCopied, true)){
            return $this;
        }

        $this->resourcesAlreadyCopied[] = $resource->getKey();

        // Define new destination and declare the new file into the main contentType document.

        $resource->setZipArchive($this->source);
        $resource->rename(basename($this->findAvailableName($resource->getPatternPath())));
        $this->addContentType('/' . $resource->getTarget());

        // Copy its dependency file by calling again this method.

        if ($resource instanceof XmlResource) {
            foreach ($resource->getResources() as $childResource) {
                $this->copyResource($childResource);
            }
        }

        // Finally, save the resource.

        $resource->save();

        // For some specific document make specific action.

        if($resource instanceof NoteMaster || $resource instanceof Slide){
            $this->presentation->addResource($resource);
            $this->presentation->save();
        }

        return $this;
    }

    /**
     * Add content type to the presentation from a filename.
     *
     * @param $filename
     */
    public function addContentType($filename): bool
    {
        $collection = array_map('strval', $this->contentTypes->content->xpath('//@PartName'));

        if(in_array($filename, $collection, true)){
            return false;
        }

        $contentTypeString = ContentType::getTypeFromFilename($filename);

        if (empty($contentTypeString)) {
            return false;
        }

        $child = $this->contentTypes->content->addChild('Override');
        $child->addAttribute('PartName', $filename);
        $child->addAttribute('ContentType', $contentTypeString);

        $this->contentTypes->save();

        return true;
    }

    /**
     * Find an available filename based on a pattern.
     *
     * @param mixed $pattern A string contains '{x}' as an index replaced by a incremental number
     * @param int $start beginning index default is 1
     *
     * @return mixed
     */
    protected function findAvailableName($pattern, $start = 1)
    {
        do {
            $filename = str_replace('{x}', $start, $pattern);
            $filenameParts = pathinfo($filename);

            $filenameWithoutExtension = $filenameParts['dirname'] . '/' . $filenameParts['filename'];
            if (!in_array($filenameWithoutExtension, $this->cachedFilename, true)) {
                $this->cachedFilename[] = $filenameWithoutExtension;
                break;
            }

            ++$start;
        } while (true);

        return $filename;
    }

    /**
     * Fill data to each slide.
     *
     * @param array|Closure $data
     *
     * @return self
     */
    public function template($data): PPTX
    {
        foreach ($this->getSlides() as $slide) {
            $slide->template($data);
        }

        return $this;
    }

    public function table(Closure $data, Closure $finder): PPTX
    {
        foreach ($this->getSlides() as $slide) {
            $slide->table($data, $finder);
        }

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
        if (!@$this->source->close()) {
            throw new FileSaveException('Unable to close the source PPTX.');
        }
    }
}
