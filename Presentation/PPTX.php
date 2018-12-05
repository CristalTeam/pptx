<?php

namespace Cpro\Presentation;

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
    protected $slides;

    /**
     * @var XmlFile
     */
    protected $presentation;

    /**
     * Presentation constructor.
     * @param $filename
     * @throws \Exception
     */
    public function __construct($filename)
    {
        $this->openFile($filename);
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
        $this->presentation = $this->readXmlFile('ppt/presentation.xml');
        $this->slides = [];

        foreach ($this->presentation->content->xpath('p:sldIdLst/p:sldId') as $slide) {
            $id = $slide->xpath('@r:id')[0]['id'].'';
            $this->slides[] = new Slide($this->presentation->getResource($id));
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
        $slides[] = $slide;

        // Copy resources

        foreach ($slide->getResource()->getResources() as $resource) {
            $this->copyResource($resource);
        }

        // Copy slide

        $this->copyResource($slide->getResource());

        // Add references

        $rId = $this->presentation->addResource($slide->getResource());

        $ref = $this->presentation->content->xpath('p:sldIdLst')[0]->addChild('sldId');
        $ref['id'] = '99999'; // todo: Voilà voilà
        $ref['r:id'] = $rId;

        $this->presentation->save();

        return $this;
    }

    public function copyResource(Resource $resource)
    {
        $filename = $this->findAvailableName($resource->getPatternPath());
        $resource->rename(basename($filename));
        $this->source->addFromString($resource->getAbsoluteTarget(), $resource->getContent());
        $resource->setZipArchive($this->source);

        $this->addContentType('/'.$resource->getAbsoluteTarget());

        if ($resource instanceof XmlResource) {
            $resource->save();
        }

        return $this;
    }

    public function getContentType($filename)
    {
        if (pathinfo($filename)['extension'] === 'xml') {
            preg_match('/ppt\/.*?([a-z]+)[0-9]*\.xml/i', $filename, $fileType);
            return 'application/vnd.openxmlformats-officedocument.presentationml.'.$fileType[1].'+xml';
        }

        return '';
    }

    public function addContentType($filename)
    {
        $contentTypeString = $this->getContentType($filename);

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

    public function save()
    {
        $this->source->close();
    }
}