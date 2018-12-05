<?php

namespace Cpro\Presentation;

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
     * @return XmlFile
     */
    protected function readXmlFile($file)
    {
        return new XmlFile($this->source, $file);
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
            $this->slides[] = $this->createSlideFromFile('ppt/'.$this->presentation->getResource($id)->getTarget());
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
        $slide->getXML()->setZipArchive($this->source);
        $slides[] = $slide;

        // Copy slide

        $slideName = $this->findAvailableName('ppt/slides/slide{x}.xml');
        $slide->getXML()->rename(basename($slideName));
        $this->addContentType('/'.$slideName);

        // Copy resources

        foreach ($slide->getResource() as $resource) {
            $this->copyResource($resource);
        }

        $slide->getXML()->save();

        // Add references

        $rId = $this->presentation->addResource('slides/'.basename($slideName), Resource::SLIDE);

        $ref = $this->presentation->content->xpath('p:sldIdLst')[0]->addChild('sldId');
        $ref['id'] = '99999'; // todo: Voilà voilà
        $ref['r:id'] = $rId;

        $this->presentation->save();

        // Dump

        /*$dom = dom_import_simplexml($this->presentation->content)->ownerDocument;
        $dom->formatOutput = true;
        dump($dom->saveXML());*/

        return $this;
    }

    public function copyResource(Resource $resource)
    {
        $filename = $this->findAvailableName($resource->getPatternPath());
        $resource->rename(basename($filename));
        $this->source->addFromString($resource->getAbsoluteTarget(), $resource->getContent());
        $resource->setArchive($this->source);
        
        $this->addContentType('/'.$resource->getAbsoluteTarget());

        return $this;
    }

    public function addContentType($filename)
    {
        if (pathinfo($filename)['extension'] === 'xml') {
            preg_match('/ppt\/.*?([a-z]+)[0-9]*\.xml/i', $filename, $fileType);
            
            $child = $this->contentTypes->addChild('Override');
            $child['PartName'] = $filename;
            $child['ContentType'] = 'application/vnd.openxmlformats-officedocument.presentationml.'.$fileType[1].'+xml';

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

    /**
     * @param $filename
     * @return Slide
     */
    public function createSlideFromFile($filename)
    {
        return new Slide($this->readXmlFile($filename));
    }

    public function save()
    {
        $this->source->close();
    }
}