<?php

namespace Cpro\Presentation;

use Cpro\Presentation\Exception\FileOpenException;
use Cpro\Presentation\Exception\FileSaveException;
use Cpro\Presentation\Resource\Resource;
use Cpro\Presentation\Resource\Slide;
use Cpro\Presentation\Resource\XmlResource;
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
     * @var XmlResource
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

    protected $cachedFilename = [];

    /**
     * Presentation constructor.
     *
     * @param $filename
     *
     * @throws \Exception
     */
    public function __construct($filename)
    {
        $this->filename = $filename;

        if (!file_exists($filename)) {
            throw new FileOpenException('Unable to open the source PPTX. Path does not exist.');
        }

        // Create tmp copy
        $this->tmpName = tempnam(sys_get_temp_dir(), 'PPTX_');

        copy($filename, $this->tmpName);

        // Open copy
        $this->openFile($this->tmpName);

        for ($i = 0; $i < $this->source->numFiles; ++$i) {
            $filenameParts = pathinfo($this->source->statIndex($i)['name']);
            if (isset($filenameParts['dirname']) && isset($filenameParts['filename'])) {
                $this->cachedFilename[] = $filenameParts['dirname'].'/'.$filenameParts['filename'];
            }
        }
    }

    /**
     * Open PPTX file.
     *
     * @param $filename
     *
     * @return $this
     *
     * @throws \Exception
     */
    public function openFile(string $filename)
    {
        $this->source = new ZipArchive();
        $res = $this->source->open($filename);

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
            throw new FileOpenException($errors[$res] ?? 'Cannot open PPTX file, error '.$res.'.');
        }

        $this->contentTypes = $this->readXmlFile('[Content_Types].xml');
        $this->loadSlides();

        return $this;
    }

    /**
     * Create an XmlResource from a filename in the current presentation.
     *
     * @filename Path to the file
     *
     * @return XmlResource
     */
    protected function readXmlFile($filename)
    {
        return new XmlResource($filename, '', 'ppt/', $this->source);
    }

    /**
     * Read existing slides.
     *
     * @return static
     */
    protected function loadSlides()
    {
        $this->slides = [];

        $this->presentation = $this->readXmlFile('ppt/presentation.xml');
        foreach ($this->presentation->content->xpath('p:sldIdLst/p:sldId') as $slide) {
            $id = $slide->xpath('@r:id')[0]['id'].'';
            $this->slides[] = $this->presentation->getResource($id);
        }

        return $this;
    }

    /**
     * Get all slides available in the current presentation.
     *
     * @return Slide[]
     */
    public function getSlides()
    {
        return $this->slides;
    }

    /**
     * Import a single slide object.
     *
     * @param Slide $slide
     *
     * @return static
     */
    public function addSlide(Slide $slide)
    {
        $slide = clone $slide;
        $this->slides[] = $slide;

        // Copy resources
        foreach ($slide->getResources() as $resource) {
            $this->copyResource($resource);
        }

        // Copy slide
        $this->copyResource($slide);

        // Add references
        $rId = $this->presentation->addResource($slide);

        $currentSlides = $this->presentation->content->xpath('p:sldIdLst/p:sldId');

        $ref = $this->presentation->content->xpath('p:sldIdLst')[0]->addChild('sldId');
        $ref['id'] = intval(end($currentSlides)['id']) + 1;
        $ref['r:id'] = $rId;

        $this->presentation->save();
        $this->refreshSource();

        return $this;
    }

    protected function refreshSource()
    {
        $this->close();
        $this->openFile($this->tmpName);
    }

    /**
     * Import multiple slides object.
     *
     * @param array $slides
     *
     * @return $this
     */
    public function addSlides(array $slides)
    {
        foreach ($slides as $slide) {
            $this->addSlide($slide);
        }

        return $this;
    }

    /**
     * Store resource into current presentation.
     *
     * @param resource $resource
     *
     * @return $this
     * @throws \Exception
     */
    public function copyResource(Resource $resource)
    {
        $filename = $this->findAvailableName($resource->getPatternPath());

        $resource->rename(basename($filename));
        $resource->setZipArchive($this->source);
        $this->addContentType('/'.$resource->getAbsoluteTarget());

        $resource->save();

        return $this;
    }

    /**
     * Add content type to the presentation from a filename.
     *
     * @param $filename
     */
    public function addContentType($filename)
    {
        $contentTypeString = ContentType::getTypeFromFilename($filename);

        if (!empty($contentTypeString)) {
            $child = $this->contentTypes->content->addChild('Override');
            $child['PartName'] = $filename;
            $child['ContentType'] = $contentTypeString;

            $this->contentTypes->save();
        }
    }

    /**
     * Find an available filename based on a pattern.
     *
     * @param     $pattern a string contains '{x}' as an index replaced by a incremental number
     * @param int $start   beginning index default is 1
     *
     * @return mixed
     */
    protected function findAvailableName($pattern, $start = 1)
    {
        do {
            $filename = str_replace('{x}', $start, $pattern);
            $filenameParts = pathinfo($filename);

            $filenameWithoutExtension = $filenameParts['dirname'].'/'.$filenameParts['filename'];
            if(!in_array($filenameWithoutExtension, $this->cachedFilename)) {
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
     * @param array|\Closure $data
     *
     * @return self
     */
    public function template($data): self
    {
        foreach ($this->getSlides() as $slide) {
            $slide->template($data);
        }

        return $this;
    }

    public function table(\Closure $data, \Closure $finder)
    {
        foreach ($this->getSlides() as $slide) {
            $slide->table($data, $finder);
        }

        return $this;
    }

    /**
     * Update the images in the slide.
     *
     * @param $data mixed Closure or array which returns: key should match the descr attribute, value is the raw content of the image
     *
     * @return self
     */
    public function images($data): self
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
     * @throws \Exception
     */
    public function saveAs($target)
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
     * @throws \Exception
     */
    public function save()
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
    protected function close()
    {
        if (!@$this->source->close()) {
            throw new FileSaveException('Unable to close the source PPTX');
        }
    }
}
