<?php

namespace Cpro\Presentation;

use Cpro\Presentation\Resource\Slide;
use ZipArchive;
use Cpro\Presentation\Resource\XmlResource;
use Cpro\Presentation\Resource\Resource;

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

    /**
     * Presentation constructor.
     * @param $filename
     * @throws \Exception
     */
    public function __construct($filename)
    {
        $this->filename = $filename;

        // Create tmp copy
        $this->tmpName = tempnam(sys_get_temp_dir(), 'PPTX_');
        copy($filename, $this->tmpName);

        // Open copy
        $this->openFile($this->tmpName);
    }

    /**
     * Open PPTX file
     *
     * @param $filename
     * @return $this
     * @throws \Exception
     */
    public function openFile(string $filename)
    {
        $this->source = new ZipArchive;
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
            throw new \Exception($errors[$res] ?? 'Cannot open PPTX file, error '.$res.'.');
        }

        $this->contentTypes = $this->readXmlFile('[Content_Types].xml');
        $this->loadSlides();

        return $this;
    }

    /**
     * @param $file
     * @return XmlResource
     */
    protected function readXmlFile($file, $type = '')
    {
        return new XmlResource($file, $type, 'ppt/', $this->source);
    }

    /**
     * Read existing slides
     */
    protected function loadSlides()
    {
        $this->slides = [];

        $this->presentation = $this->readXmlFile('ppt/presentation.xml');
        foreach ($this->presentation->content->xpath('p:sldIdLst/p:sldId') as $slide) {
            $id = $slide->xpath('@r:id')[0]['id'].'';
            $this->slides[] = $this->presentation->getResource($id);
        }
    }

    /**
     * @return Slide[]
     */
    public function getSlides()
    {
        return $this->slides;
    }

    /**
     * @param Slide $slide
     * @return PPTX
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

        return $this;
    }

    public function addSlides(array $slides)
    {
        foreach ($slides as $slide) {
            $this->addSlide($slide);
        }

        return $this;
    }

    public function copyResource(Resource $resource)
    {
        $filename = $this->findAvailableName($resource->getPatternPath());

        $resource->rename(basename($filename));
        $resource->setZipArchive($this->source);
        $this->addContentType('/'.$resource->getAbsoluteTarget());

        $resource->save();

        return $this;
    }

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
     * @param     $pattern  A string contains '{x}' as an index replaced by a incremental number.
     * @param int $start Beginning index default is 1.
     * @return mixed
     */
    protected function findAvailableName($pattern, $start = 1)
    {
        do {
            $filename = str_replace('{x}', $start, $pattern);
            $available = $this->source->locateName($filename) == false;
            $start++;
        } while (!$available);

        return $filename;
    }

    public function template($data)
    {
        foreach ($this->getSlides() as $slide) {
            $slide->template($data);
        }
    }

    public function saveAs($filename)
    {
        $this->source->close();
        copy($this->tmpName, $filename);
        $this->openFile($this->tmpName);
    }

    public function save()
    {
        $this->saveAs($this->filename);
    }

    public function __destruct()
    {
        $this->source->close();
        unlink($this->tmpName);
    }
}